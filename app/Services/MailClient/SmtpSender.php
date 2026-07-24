<?php

declare(strict_types=1);

namespace App\Services\MailClient;

use App\Mail\UserMail;
use App\Models\EmailAccount;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a {@see UserMail} through the ACCOUNT'S OWN SMTP server (plan B5a) —
 * never the app's global MAIL_MAILER. The credentials live encrypted on the
 * account; here they're registered as an ad-hoc mailer config keyed by account
 * id and the message is dispatched through it.
 *
 * Runtime config mutation is safe: the send runs inside an isolated queue-job
 * process, and under Mail::fake() in tests the transport config is ignored (the
 * fake records the mailable without opening a socket).
 *
 * Expected `credentials` keys: smtp_host, smtp_port, smtp_encryption, username,
 * password (same bag the IMAP fetch uses).
 */
class SmtpSender implements MailSender
{
    public function send(EmailAccount $account, OutboundEmail $email): void
    {
        $creds = $account->credentials ?? [];
        $mailer = 'mailbox_'.$account->id;

        $encryption = ($creds['smtp_encryption'] ?? 'tls') ?: null;

        config(["mail.mailers.{$mailer}" => [
            'transport' => 'smtp',
            'host' => (string) ($creds['smtp_host'] ?? ''),
            'port' => (int) ($creds['smtp_port'] ?? 587),
            'encryption' => $encryption,
            'username' => (string) ($creds['username'] ?? ''),
            'password' => (string) ($creds['password'] ?? ''),
            'timeout' => 15,
        ]]);

        // The mailable carries its own recipients/from via envelope(), so send()
        // needs no ->to(); a transport error bubbles up to the job.
        Mail::mailer($mailer)->send(new UserMail($account, $email));
    }
}
