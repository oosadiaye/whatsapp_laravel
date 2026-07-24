<?php

declare(strict_types=1);

namespace App\Livewire\Mailbox\Concerns;

use App\Jobs\SendUserEmail;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Services\MailClient\AttachmentName;
use App\Services\MailClient\OutboundAttachment;
use App\Services\MailClient\OutboundEmail;
use Illuminate\Support\Str;

/**
 * The mailbox composer (plan B5b) — reply / reply-all / forward / fresh compose —
 * rendered into the B4 inbox seam. Kept a trait so the {@see \App\Livewire\Mailbox\Inbox}
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

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $composeFiles = [];

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

    public function startForward(int $messageId): void
    {
        $message = $this->sendableMessage($messageId);

        // A forward opens a NEW thread (no In-Reply-To/References to the original).
        $this->openComposer('forward', $message->account->id, null);
        $this->composeSubject = $this->prefixSubject('Fwd:', (string) $message->subject);
        $this->composeBody = $this->quote($message);
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

        $to = $this->parseAddresses($this->composeTo);
        if ($to === []) {
            $this->addError('composeTo', __('Enter at least one valid recipient email.'));

            return;
        }

        $outbound = new OutboundEmail(
            to: $to,
            subject: $this->composeSubject,
            bodyHtml: null,
            bodyText: $this->composeBody,
            cc: $this->parseAddresses($this->composeCc),
            inReplyTo: $this->composeInReplyTo,
            references: $this->composeReferences,
            threadId: $this->composeThreadId,
            attachments: $this->stageAttachments($account),
        );

        SendUserEmail::dispatch($account->id, $outbound);

        $this->resetCompose();
        session()->flash('mailbox_status', __('Message queued for delivery.'));
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
    }

    private function resetCompose(): void
    {
        $this->reset([
            'composing', 'composeMode', 'composeAccountId', 'replyToMessageId',
            'composeThreadId', 'composeTo', 'composeCc', 'composeSubject',
            'composeBody', 'composeInReplyTo', 'composeReferences', 'composeFiles',
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<EmailAccount>
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
     * @return list<string>
     */
    private function parseAddresses(string $raw): array
    {
        $parts = preg_split('/[,;\s]+/', trim($raw)) ?: [];

        $valid = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && filter_var($part, FILTER_VALIDATE_EMAIL)) {
                $valid[strtolower($part)] = $part;
            }
        }

        return array_values($valid);
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
