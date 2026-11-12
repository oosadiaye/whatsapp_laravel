<?php

declare(strict_types=1);

namespace App\Livewire\Mailbox\Concerns;

use App\Jobs\SendUserEmail;
use App\Livewire\Mailbox\Inbox;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Services\AiEmailDraftService;
use App\Services\AiEmailEditor;
use App\Services\MailClient\AttachmentName;
use App\Services\MailClient\OutboundAttachment;
use App\Services\MailClient\OutboundEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The mailbox composer (plan B5b) — reply / reply-all / forward / fresh compose —
 * rendered into the B4 inbox seam. Kept a trait so the {@see Inbox}
 * stays the read SHELL and this stays the write path (php/patterns: small,
 * focused units).
 *
 * SEND-AS-SELF is the invariant: you may only send from an account you OWN, even
 * if mailbox.view_all lets you READ a colleague's thread — reading is oversight,
 * sending as them is impersonation. Every entry point (start*, send) re-checks
 * ownership; nothing trusts a client-supplied account/thread id.
 *
 * Depends on the host component's private accessibleThreads() (shared trait
 * scope) so the composer honours the exact same visibility rules as the reader.
 */
trait WithCompose
{
    /** Most people one message may go to (To and Cc together). */
    public const MAX_RECIPIENTS = 50;

    public bool $composing = false;

    /** reply|reply_all|forward|new */
    public string $composeMode = 'reply';

    public ?int $composeAccountId = null;

    public ?int $replyToMessageId = null;

    public ?int $composeThreadId = null;

    public string $composeTo = '';

    public string $composeCc = '';

    public string $composeSubject = '';

    public string $composeBody = '';

    public ?string $composeInReplyTo = null;

    public ?string $composeReferences = null;

    /** @var array<int, TemporaryUploadedFile> */
    public array $composeFiles = [];

    /** The signature block placed in the body when the composer opened (so a changed sender can swap it). */
    public string $appliedSignature = '';

    /** A message that failed to send and is being edited to send again; removed once the new send is queued. */
    public ?int $retryingMessageId = null;

    /** The body as it was before the last AI edit, so one click puts it back. */
    public ?string $bodyBeforeAi = null;

    // AI drafting modal
    public bool $showAiDraft = false;

    public bool $aiDrafting = false;

    public string $aiDraftGoal = '';

    public string $aiDraftTone = 'professional';

    public string $aiDraftContext = '';

    /**
     * Fix spelling and grammar, rephrase, or polish what has been written so far. The previous text
     * is kept for {@see undoAiEdit()}; if the AI is unavailable the draft is left untouched.
     */
    public function aiEdit(string $mode, AiEmailEditor $editor): void
    {
        abort_unless(in_array($mode, AiEmailEditor::MODES, true), 422);

        $body = $this->composeBody;
        if (trim($body) === '') {
            $this->dispatch('notify', 'Write something first, then the AI can improve it.', type: 'error');

            return;
        }
        if (mb_strlen($body) > AiEmailEditor::MAX_CHARS) {
            $this->dispatch('notify', 'That message is too long for the AI editor. Edit it in smaller parts.', type: 'error');

            return;
        }

        // Same quota guard as drafting: a held-down button must not burn the API budget.
        $rateKey = 'ai-edit:'.auth()->id();
        if (RateLimiter::tooManyAttempts($rateKey, 20)) {
            $this->dispatch('notify', 'Too many AI edits. Wait a moment and try again.', type: 'error');

            return;
        }
        RateLimiter::hit($rateKey, 60);

        $edited = $editor->edit($mode, $body);

        if ($edited === null) {
            $this->dispatch('notify', 'The AI editor is not available right now. Your text is unchanged.', type: 'error');

            return;
        }

        $this->bodyBeforeAi = $body;
        $this->composeBody = $edited;
    }

    public function undoAiEdit(): void
    {
        if ($this->bodyBeforeAi === null) {
            return;
        }

        $this->composeBody = $this->bodyBeforeAi;
        $this->bodyBeforeAi = null;
    }

    public function draftWithAi(AiEmailDraftService $service): void
    {
        $this->validate([
            'aiDraftGoal' => ['required', 'string', 'max:500'],
            'aiDraftTone' => ['required', 'in:professional,friendly,formal,casual,persuasive'],
            'aiDraftContext' => ['nullable', 'string', 'max:2000'],
        ]);

        // Throttle the Gemini-backed draft so a user can't burn API quota/cost by
        // spamming "Generate Draft" (M7) — mirrors the limiters on outbound calls
        // and per-account email sends.
        $rateKey = 'ai-draft:'.auth()->id();
        if (RateLimiter::tooManyAttempts($rateKey, 10)) {
            $this->dispatch('notify', 'Too many AI drafts — wait a moment and try again.', type: 'error');

            return;
        }
        RateLimiter::hit($rateKey, 60);

        $this->aiDrafting = true;

        $draft = $service->draft([
            'recipient_name' => $this->composeTo,
            'goal' => $this->aiDraftGoal,
            'tone' => $this->aiDraftTone,
            'context' => $this->aiDraftContext,
            'subject' => $this->composeSubject,
        ]);

        $this->aiDrafting = false;
        $this->showAiDraft = false;

        if ($draft !== null) {
            $this->composeBody = $draft;
            $this->dispatch('notify', 'Draft generated by AI.');
        } else {
            $this->dispatch('notify', 'AI draft failed. Check your Gemini API key.', type: 'error');
        }
    }

    public function startReply(int $messageId, bool $all = false): void
    {
        $message = $this->sendableMessage($messageId);

        $recipients = [$message->from_email];
        if ($all) {
            $recipients = array_merge($recipients, (array) $message->to, (array) $message->cc);
        }

        $this->openComposer($all ? 'reply_all' : 'reply', $message->account->id, $message->email_thread_id);
        $this->replyToMessageId = $message->id;
        $this->composeTo = implode(', ', $this->cleanRecipients($recipients, $message->account->email));
        $this->composeSubject = $this->prefixSubject('Re:', (string) $message->subject);
        $this->composeInReplyTo = $message->message_id !== null ? '<'.$message->message_id.'>' : null;
        $this->composeReferences = $this->buildReferences($message);
    }

    /** Sending from a different mailbox swaps in that mailbox's signature, if the old one is still there. */
    public function updatedComposeAccountId(): void
    {
        if ($this->composeMode !== 'new') {
            return;
        }

        $account = $this->ownActiveAccounts()->whereKey($this->composeAccountId)->first();
        $new = $this->signatureBlock($account);
        $old = $this->appliedSignature;

        if ($old !== '' && str_contains($this->composeBody, $old)) {
            $this->composeBody = str_replace($old, $new, $this->composeBody);
        } elseif (trim($this->composeBody) === '') {
            $this->composeBody = $new;
        }

        $this->appliedSignature = $new;
    }

    /**
     * Reopen a message that failed to send, filled in as it was, so the person can fix the cause
     * (usually the address) and send it again. Attachments are not kept, so they must be added again.
     */
    public function editFailed(int $messageId): void
    {
        $failed = $this->ownFailedSends()->with('thread')->whereKey($messageId)->first();
        abort_if($failed === null, 404);

        $account = $this->ownActiveAccounts()->whereKey($failed->email_account_id)->first();
        if ($account === null) {
            $this->dispatch('notify', message: __('That mailbox is disconnected. Reconnect it first, then send again.'), type: 'error');

            return;
        }

        $isReply = $failed->in_reply_to !== null;
        $this->openComposer($isReply ? 'reply' : 'new', $account->id, $isReply ? $failed->email_thread_id : null);
        $this->retryingMessageId = $failed->id;
        $this->composeTo = implode(', ', (array) $failed->to);
        $this->composeCc = implode(', ', (array) $failed->cc);
        $this->composeSubject = (string) $failed->subject;
        $this->composeBody = (string) $failed->body_text;
        $this->composeInReplyTo = $failed->in_reply_to;
        $this->composeReferences = $failed->references_header;
        $this->appliedSignature = '';
    }

    /** Remove a message that failed to send, and its conversation if it held nothing else. */
    public function discardFailed(int $messageId): void
    {
        $failed = $this->ownFailedSends()->whereKey($messageId)->first();
        abort_if($failed === null, 404);

        $this->removeFailedSend($failed);
    }

    public function startForward(int $messageId): void
    {
        $message = $this->sendableMessage($messageId);

        // A forward opens a NEW thread (no In-Reply-To/References to the original).
        $this->openComposer('forward', $message->account->id, null);
        $this->composeSubject = $this->prefixSubject('Fwd:', (string) $message->subject);
        $this->composeBody = $this->appliedSignature.$this->quote($message);
    }

    public function startCompose(): void
    {
        $account = $this->ownActiveAccounts()->first();
        abort_if($account === null, 403);

        $this->openComposer('new', $account->id, null);
    }

    public function cancelCompose(): void
    {
        $this->resetCompose();
    }

    public function removeFile(int $index): void
    {
        unset($this->composeFiles[$index]);
        $this->composeFiles = array_values($this->composeFiles);
    }

    public function send(): void
    {
        abort_unless((bool) config('mail_client.enabled'), 404);

        $this->validate([
            'composeAccountId' => ['required', 'integer'],
            'composeTo' => ['required', 'string'],
            'composeSubject' => ['required', 'string', 'max:255'],
            'composeCc' => ['nullable', 'string'],
            'composeBody' => ['nullable', 'string'],
            'composeFiles' => ['array', 'max:10'],
            'composeFiles.*' => ['file', 'max:10240'], // 10 MB each
        ]);

        // Re-resolve from the DB scoped to the current user — the account id is
        // client state and must never be trusted to pick the sending identity.
        $account = $this->ownActiveAccounts()->whereKey($this->composeAccountId)->first();
        abort_if($account === null, 403);

        [$to, $badTo] = $this->splitAddresses($this->composeTo);
        [$cc, $badCc] = $this->splitAddresses($this->composeCc);

        if ($badTo !== []) {
            $this->addError('composeTo', __("These don't look like email addresses: :list", ['list' => implode(', ', $badTo)]));
        } elseif ($to === []) {
            $this->addError('composeTo', __('Enter at least one valid recipient email.'));
        } elseif (count($to) + count($cc) > self::MAX_RECIPIENTS) {
            $this->addError('composeTo', __('Too many recipients. Send to at most :max people at once.', ['max' => self::MAX_RECIPIENTS]));
        }
        if ($badCc !== []) {
            $this->addError('composeCc', __("These don't look like email addresses: :list", ['list' => implode(', ', $badCc)]));
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $outbound = new OutboundEmail(
            to: $to,
            subject: $this->composeSubject,
            bodyHtml: null,
            bodyText: $this->composeBody,
            cc: $cc,
            inReplyTo: $this->composeInReplyTo,
            references: $this->composeReferences,
            threadId: $this->composeThreadId,
            attachments: $this->stageAttachments($account),
        );

        SendUserEmail::dispatch($account->id, $outbound);

        // The earlier failed copy is replaced by this attempt (which is itself marked if it fails too).
        $failed = $this->retryingMessageId !== null ? $this->ownFailedSends()->whereKey($this->retryingMessageId)->first() : null;
        if ($failed !== null) {
            $this->removeFailedSend($failed);
        }

        $this->resetCompose();
        session()->flash('mailbox_status', __('Sending your message. If it cannot be delivered you will be told here.'));
        $this->dispatch('mailbox-sent');
    }

    /**
     * Load a message the current user may reply to: it must sit on an account the
     * user OWNS and in a thread the reader can see. Guards impersonation and
     * cross-tenant access in one place.
     */
    private function sendableMessage(int $messageId): EmailMessage
    {
        abort_unless((bool) config('mail_client.enabled'), 404);

        $message = EmailMessage::query()
            ->with(['account'])
            ->whereKey($messageId)
            ->whereHas('account', fn ($q) => $q->where('user_id', auth()->id()))
            ->first();

        abort_if($message === null, 403);
        abort_unless($this->accessibleThreads()->whereKey($message->email_thread_id)->exists(), 403);

        return $message;
    }

    private function openComposer(string $mode, int $accountId, ?int $threadId): void
    {
        $this->resetCompose();
        $this->composing = true;
        $this->composeMode = $mode;
        $this->composeAccountId = $accountId;
        $this->composeThreadId = $threadId;

        // Signature goes below the cursor, ahead of any quoted/forwarded text.
        $this->appliedSignature = $this->signatureBlock($this->ownActiveAccounts()->whereKey($accountId)->first());
        $this->composeBody = $this->appliedSignature;
    }

    /** "-- " is the conventional signature delimiter; mail clients and the AI editor both recognise it. */
    private function signatureBlock(?EmailAccount $account): string
    {
        $signature = trim((string) $account?->signature);

        return $account?->signature_auto && $signature !== '' ? "\n\n-- \n".$signature : '';
    }

    private function resetCompose(): void
    {
        $this->reset([
            'composing', 'composeMode', 'composeAccountId', 'replyToMessageId',
            'composeThreadId', 'composeTo', 'composeCc', 'composeSubject',
            'composeBody', 'composeInReplyTo', 'composeReferences', 'composeFiles', 'bodyBeforeAi', 'appliedSignature', 'retryingMessageId',
        ]);
    }

    /**
     * @return Builder<EmailAccount>
     */
    private function ownActiveAccounts()
    {
        return EmailAccount::query()
            ->where('user_id', auth()->id())
            ->where('is_active', true)
            ->orderBy('email');
    }

    /**
     * @return list<OutboundAttachment>
     */
    private function stageAttachments(EmailAccount $account): array
    {
        $staged = [];

        foreach ($this->composeFiles as $file) {
            // Client-supplied upload name — sanitise before it touches a path.
            $name = AttachmentName::safe($file->getClientOriginalName());
            $path = $file->storeAs(
                'mailbox/outbox/'.$account->id,
                Str::uuid()->toString().'-'.$name,
                'local',
            );

            $staged[] = new OutboundAttachment(
                diskPath: $path,
                filename: $name,
                mime: $file->getMimeType() ?: 'application/octet-stream',
            );
        }

        return $staged;
    }

    /**
     * @param  list<string>  $addresses
     * @return list<string>
     */
    private function cleanRecipients(array $addresses, string $self): array
    {
        $self = strtolower(trim($self));
        $seen = [];

        foreach ($addresses as $address) {
            $address = strtolower(trim((string) $address));
            if ($address === '' || $address === $self || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $seen[$address] = true;
        }

        return array_keys($seen);
    }

    /**
     * Split what was typed into addresses. Accepts "a@x.com, b@y.com", one per line, and the
     * "Ada Obi <ada@x.com>" form. Anything that is not an address is returned so it can be shown,
     * never silently dropped.
     *
     * @return array{0: list<string>, 1: list<string>} [valid, invalid]
     */
    private function splitAddresses(string $raw): array
    {
        $raw = (string) preg_replace('/"?[^<>,;"]*"?\s*<([^<>\s]+)>/', '$1', $raw);
        $parts = preg_split('/[,;\s]+/', trim($raw)) ?: [];

        $valid = [];
        $invalid = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (filter_var($part, FILTER_VALIDATE_EMAIL)) {
                $valid[strtolower($part)] = $part;
            } else {
                $invalid[$part] = $part;
            }
        }

        return [array_values($valid), array_values($invalid)];
    }

    /**
     * Messages from the current user's own mailboxes that could not be sent.
     *
     * @return Builder<EmailMessage>
     */
    private function ownFailedSends()
    {
        // Livewire actions do not re-run the route middleware, so check here as the reader does.
        abort_unless((bool) config('mail_client.enabled'), 404);
        abort_unless(auth()->user()?->can('mailbox.view'), 403);

        return EmailMessage::query()
            ->where('direction', EmailMessage::DIRECTION_OUTBOUND)
            ->where('send_status', EmailMessage::SEND_FAILED)
            ->whereHas('account', fn ($q) => $q->where('user_id', auth()->id()));
    }

    private function removeFailedSend(EmailMessage $failed): void
    {
        $thread = $failed->thread;
        $wasUnread = ! $failed->is_read;
        $failed->delete();

        // A failed send was counted as unread when it was recorded; take that back.
        if ($wasUnread && $thread !== null && $thread->unread_count > 0) {
            $thread->decrement('unread_count');
        }

        if ($thread !== null && $thread->messages()->doesntExist()) {
            if ($this->selectedThreadId === $thread->id) {
                $this->selectedThreadId = null;
            }
            $thread->delete();
        }
    }

    private function prefixSubject(string $prefix, string $subject): string
    {
        $subject = trim($subject);
        if (Str::startsWith(Str::lower($subject), Str::lower($prefix))) {
            return $subject;
        }

        return trim($prefix.' '.$subject);
    }

    private function buildReferences(EmailMessage $message): ?string
    {
        $chain = trim((string) $message->references_header);

        if ($message->message_id !== null) {
            $chain = trim($chain.' <'.$message->message_id.'>');
        }

        return $chain !== '' ? $chain : null;
    }

    private function quote(EmailMessage $message): string
    {
        $when = ($message->received_at ?? $message->sent_at)?->format('M j, Y g:i A') ?? '';
        $original = $message->body_text ?: strip_tags((string) $message->body_html);

        $quoted = collect(preg_split('/\r\n|\r|\n/', $original) ?: [])
            ->map(fn (string $line): string => '> '.$line)
            ->implode("\n");

        return "\n\n---------- Forwarded message ----------\n"
            ."From: {$message->from_email}\nDate: {$when}\nSubject: {$message->subject}\n\n"
            .$quoted;
    }
}
