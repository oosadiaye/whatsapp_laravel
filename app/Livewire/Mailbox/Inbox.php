<?php

declare(strict_types=1);

namespace App\Livewire\Mailbox;

use App\Jobs\SyncEmailAccount;
use App\Livewire\Mailbox\Concerns\WithCompose;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * The per-employee mailbox inbox (plan B4) — thread list + read view. This is
 * the B4 SHELL: B5 renders its composer + per-message actions into the seam in
 * the view, so the two steps don't both own this component.
 *
 * Scoping (review M4) is PRIVATE-per-user by default — a user sees only their
 * OWN accounts' threads; mailbox.view_all widens it to the team (the inverse of
 * conversations.*). Authorization runs on every action/render, not just mount,
 * because Livewire updates bypass the route middleware.
 *
 * Message bodies are untrusted inbound HTML, rendered in a sandboxed srcdoc
 * iframe (no allow-scripts/allow-same-origin) — reusing the email-preview
 * sandbox, safe under the app CSP (frame-src 'self').
 */
class Inbox extends Component
{
    use WithCompose, WithFileUploads, WithPagination;

    public string $search = '';

    public string $folder = EmailThread::FOLDER_INBOX;

    public ?int $selectedThreadId = null;

    public const SORT_FIELDS = ['date', 'sender', 'subject'];

    /** date | sender | subject, kept in the address bar so a refresh keeps the order. */
    #[Url(as: 'sort')]
    public string $sortBy = 'date';

    /** asc | desc */
    #[Url(as: 'dir')]
    public string $sortDir = 'desc';

    /** Failed sends with an id above this have not been announced yet (a toast tells the person once). */
    public int $lastFailureSeenId = 0;

    public function mount(): void
    {
        $this->lastFailureSeenId = (int) $this->ownFailedSends()->max('email_messages.id');
    }

    /**
     * Run on the page's poll: announce a message that has just failed to send, once. The list and
     * banners refresh on the same round trip.
     */
    public function refreshMailbox(): void
    {
        $fresh = $this->ownFailedSends()
            ->where('email_messages.id', '>', $this->lastFailureSeenId)
            ->latest('id')
            ->limit(3)
            ->get();

        foreach ($fresh as $failed) {
            $this->dispatch('notify', message: __('Not sent: ":subject". :reason', [
                'subject' => $failed->subject ?: __('(no subject)'),
                'reason' => $failed->send_error,
            ]), type: 'error');
        }

        $this->lastFailureSeenId = max($this->lastFailureSeenId, (int) $this->ownFailedSends()->max('email_messages.id'));
    }

    /**
     * "Check now": look for new mail in the person's own mailboxes right away and say what happened.
     * Runs in this request so the real reason shows if it fails.
     */
    public function syncNow(): void
    {
        abort_unless((bool) config('mail_client.enabled'), 404);
        abort_unless(auth()->user()?->can('mailbox.view'), 403);

        $rateKey = 'mail-sync-now:'.auth()->id();
        if (RateLimiter::tooManyAttempts($rateKey, 6)) {
            $this->dispatch('notify', message: __('You just checked. Wait a moment and try again.'), type: 'error');

            return;
        }
        RateLimiter::hit($rateKey, 60);

        $accounts = EmailAccount::query()->where('user_id', auth()->id())->where('is_active', true)->get();
        if ($accounts->isEmpty()) {
            $this->dispatch('notify', message: __('No mailbox is connected yet.'), type: 'error');

            return;
        }

        // Give a web request room for a slow mail server. Only raises a limit that exists (it is 0, i.e.
        // none, on the command line), so tests and workers are not suddenly put on a clock.
        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0 && $limit < 120) {
            @set_time_limit(120);
        }
        $ids = $accounts->pluck('id')->all();
        $before = EmailMessage::query()->whereIn('email_account_id', $ids)->count();
        $attemptedBefore = $accounts->mapWithKeys(fn (EmailAccount $a): array => [$a->id => $a->last_sync_attempt_at?->getTimestamp()])->all();

        // At most three mailboxes per click, so one request cannot tie up a web worker for minutes.
        foreach ($accounts->where('needs_reauth', false)->take(3) as $account) {
            try {
                SyncEmailAccount::dispatchSync($account->id);
            } catch (\Throwable) {
                // The job has already written the reason to the account; it is shown below.
            }
        }

        $problem = EmailAccount::query()->whereIn('id', $ids)->where(fn ($q) => $q->where('needs_reauth', true)->orWhereNotNull('last_sync_error'))->first();
        if ($problem !== null) {
            $this->dispatch('notify', message: __('Could not check :email. :reason', [
                'email' => $problem->email,
                'reason' => $problem->last_sync_error ?: __('Reconnect the mailbox in its settings.'),
            ]), type: 'error');

            return;
        }

        // A run that found another run already in progress does nothing; do not call that "up to date".
        $ran = EmailAccount::query()->whereIn('id', $ids)->get()
            ->contains(fn (EmailAccount $a): bool => $a->last_sync_attempt_at?->getTimestamp() !== ($attemptedBefore[$a->id] ?? null));
        if (! $ran) {
            $this->dispatch('notify', message: __('A check is already running. Try again in a moment.'));

            return;
        }

        $new = EmailMessage::query()->whereIn('email_account_id', $ids)->count() - $before;
        $this->dispatch('notify', message: $new > 0
            ? trans_choice(':count new message.|:count new messages.', $new, ['count' => $new])
            : __('You are up to date.'));
    }

    /** Jump to the Sent folder at the newest message that failed to send. */
    public function showFailedSends(): void
    {
        $failed = $this->ownFailedSends()->latest('id')->first();

        $this->folder = EmailThread::FOLDER_SENT;
        $this->resetPage();
        $this->selectedThreadId = $failed?->email_thread_id;
    }

    /**
     * Reverb push (plan B6): refresh the moment sync stores new mail on THIS
     * user's channel. `$refresh` re-queries the list + open thread and preserves
     * compose state (public props survive), so a live delivery never interrupts
     * a reply in progress. Falls back gracefully to manual refresh if Reverb is
     * down — mail is not latency-critical.
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        $userId = auth()->id();

        return $userId === null
            ? []
            : ["echo-private:user.{$userId},.mail.received" => '$refresh'];
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingSortBy(): void
    {
        $this->resetPage();
    }

    public function updatingSortDir(): void
    {
        $this->resetPage();
    }

    public function toggleSortDir(): void
    {
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        $this->resetPage();
    }

    public function updatingFolder(): void
    {
        $this->resetPage();
        $this->selectedThreadId = null;
        $this->cancelCompose();
    }

    public function selectThread(int $threadId): void
    {
        $thread = $this->accessibleThreads()->whereKey($threadId)->first();
        abort_if($thread === null, 404);

        // Mark read LOCALLY (provider write-back lands in B5). Re-sync only adds
        // new messages, so this state persists.
        if ($thread->unread_count > 0) {
            $thread->messages()->where('is_read', false)->update(['is_read' => true]);
            $thread->update(['unread_count' => 0]);
        }

        $this->selectedThreadId = $thread->id;
    }

    public function render()
    {
        $threads = $this->accessibleThreads()
            ->tap(fn (Builder $q) => $this->inFolder($q))
            ->when($this->search !== '', function (Builder $q): void {
                $term = '%'.$this->search.'%';
                $q->where(function (Builder $sub) use ($term): void {
                    $sub->where('subject', 'like', $term)
                        ->orWhereHas('messages', function (Builder $m) use ($term): void {
                            $m->where('from_email', 'like', $term)
                                ->orWhere('subject', 'like', $term)
                                ->orWhere('body_text', 'like', $term);
                        });
                });
            })
            ->tap(fn (Builder $q) => $this->applySort($q))
            ->withExists(['messages as has_failed_send' => fn (Builder $m) => $m->where('send_status', EmailMessage::SEND_FAILED)])
            ->with('latestMessage')
            ->paginate(25);

        $selected = null;
        if ($this->selectedThreadId !== null) {
            $selected = $this->accessibleThreads()
                ->with([
                    'account',
                    // Order by EVENT time: inbound has received_at, outbound has
                    // sent_at (received_at NULL). A plain orderBy('received_at')
                    // sorts NULLs first, so every sent reply would jump above the
                    // inbound message it answered — coalesce to the real timestamp.
                    'messages' => fn ($q) => $q
                        ->orderByRaw('COALESCE(received_at, sent_at, created_at) asc')
                        ->orderBy('id'),
                    'messages.attachments',
                ])
                ->whereKey($this->selectedThreadId)
                ->first();
        }

        // Accounts the user may send AS (own + active). Drives the composer's
        // account selector and whether reply/forward/compose are offered — you
        // can read a colleague's thread but only send from your own identity.
        $myAccounts = EmailAccount::query()
            ->where('user_id', auth()->id())
            ->where('is_active', true)
            ->orderBy('email')
            ->get();

        $canSendFromSelected = $selected !== null
            && $selected->account !== null
            && $selected->account->user_id === auth()->id();

        $syncProblemAccount = $myAccounts->first(fn (EmailAccount $a): bool => $a->needs_reauth || $a->last_sync_error !== null);

        return view('livewire.mailbox.inbox', [
            'failedSendCount' => $this->ownFailedSends()->count(),
            'lastChecked' => $myAccounts->max('last_synced_at'),
            'syncProblemAccount' => $syncProblemAccount,
            'threads' => $threads,
            'selected' => $selected,
            'myAccounts' => $myAccounts,
            'canSendFromSelected' => $canSendFromSelected,
        ]);
    }

    /**
     * Order by date, sender (the person we wrote to, in Sent) or subject, either direction. The
     * field and direction come from the client, so both are checked against fixed lists. Ties fall
     * back to newest-first so paging never shuffles rows.
     */
    private function applySort(Builder $query): void
    {
        $dir = $this->sortDir === 'asc' ? 'asc' : 'desc';
        $field = in_array($this->sortBy, self::SORT_FIELDS, true) ? $this->sortBy : 'date';

        if ($field === 'sender') {
            $column = $this->folder === EmailThread::FOLDER_SENT ? 'to' : 'from_email';
            $query->orderBy(
                EmailMessage::query()->select($column)->whereColumn('email_messages.email_thread_id', 'email_threads.id')->latest('id')->limit(1),
                $dir,
            );
        } elseif ($field === 'subject') {
            $query->orderBy('subject', $dir);
        } else {
            $query->orderBy('last_message_at', $dir);
        }

        $query->orderByDesc('id');
    }

    /**
     * Inbox/Archive/Trash are the thread's own folder. Sent is every conversation that holds a
     * message we sent, so a reply sent from the app shows there even though its thread started in
     * the inbox (archived or trashed threads stay in their own folder).
     */
    private function inFolder(Builder $query): void
    {
        if ($this->folder !== EmailThread::FOLDER_SENT) {
            $query->where('folder', $this->folder);

            return;
        }

        $query->where(function (Builder $q): void {
            $q->where('folder', EmailThread::FOLDER_SENT)
                ->orWhere(function (Builder $t): void {
                    $t->whereNotIn('folder', [EmailThread::FOLDER_ARCHIVE, EmailThread::FOLDER_TRASH])
                        ->whereHas('messages', fn (Builder $m) => $m->where('direction', EmailMessage::DIRECTION_OUTBOUND));
                });
        });
    }

    /**
     * Threads the current user may see. Aborts if the feature is off or the user
     * lacks mailbox.view — Livewire actions don't re-run the route middleware.
     */
    private function accessibleThreads(): Builder
    {
        abort_unless((bool) config('mail_client.enabled'), 404);
        abort_unless(auth()->user()?->can('mailbox.view'), 403);

        $query = EmailThread::query();

        if (! auth()->user()->can('mailbox.view_all')) {
            $query->whereHas('account', fn (Builder $q) => $q->where('user_id', auth()->id()));
        }

        return $query;
    }
}
