<?php

declare(strict_types=1);

namespace App\Livewire\Mailbox;

use App\Livewire\Mailbox\Concerns\WithCompose;
use App\Models\EmailAccount;
use App\Models\EmailThread;
use Illuminate\Database\Eloquent\Builder;
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
            ->where('folder', $this->folder)
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
            ->orderByDesc('last_message_at')
            ->paginate(20);

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

        return view('livewire.mailbox.inbox', [
            'threads' => $threads,
            'selected' => $selected,
            'myAccounts' => $myAccounts,
            'canSendFromSelected' => $canSendFromSelected,
        ]);
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
