<?php

/**
 * Pretends a prospect answered: stores an inbound email in the mailbox the lead was written from, then
 * fires the same MailReceived event the real mailbox sync fires. Run from the project root:
 *
 *   php tests/E2E/lead-crm/simulate-reply.php prospect@example.test "Yes, tell me more about a demo."
 *
 * Local testing only: it writes to whatever database .env points at.
 */

use App\Events\Mailbox\MailReceived;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $from, $text] = array_pad($argv, 3, null);
if (! $from || ! $text) {
    fwrite(STDERR, "Usage: php simulate-reply.php <prospect email> \"<what they wrote>\"\n");
    exit(1);
}

$sent = EmailMessage::query()
    ->where('direction', EmailMessage::DIRECTION_OUTBOUND)
    ->where('to', 'like', '%"'.strtolower($from).'"%')
    ->orderByDesc('id')
    ->first();
$account = $sent ? EmailAccount::find($sent->email_account_id) : EmailAccount::query()->where('is_active', true)->first();
if (! $account) {
    fwrite(STDERR, "No mailbox found.\n");
    exit(1);
}

$thread = $sent?->thread ?? EmailThread::create([
    'email_account_id' => $account->id, 'subject' => 'Re: simulated', 'last_message_at' => now(),
    'unread_count' => 0, 'folder' => EmailThread::FOLDER_INBOX, 'thread_ref' => bin2hex(random_bytes(8)),
]);

$message = $thread->messages()->create([
    'email_account_id' => $account->id,
    'direction' => EmailMessage::DIRECTION_INBOUND,
    'message_id' => bin2hex(random_bytes(8)).'@prospect.test',
    'in_reply_to' => $sent?->message_id ? '<'.trim($sent->message_id, '<>').'>' : null,
    'from_email' => strtolower($from),
    'to' => [$account->email],
    'subject' => 'Re: '.($sent?->subject ?? 'Hello'),
    'body_text' => $text,
    'is_read' => false,
    'received_at' => now(),
]);

MailReceived::dispatch($account, $message);

echo "Reply stored as message {$message->id} and MailReceived dispatched.\n";
