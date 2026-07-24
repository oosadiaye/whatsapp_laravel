<?php

declare(strict_types=1);

namespace App\Services\MailClient;

use App\Models\EmailAccount;

/**
 * The outbound counterpart of {@see MailFetcher} (plan B5a): send one message
 * from a connected account. Implementations own the transport detail (SMTP for
 * IMAP accounts; Graph/Gmail API later) so the send job depends on this contract
 * only. Throws on a hard send failure — the caller decides retry policy (send is
 * NOT retried; see {@see \App\Jobs\SendUserEmail}).
 */
interface MailSender
{
    public function send(EmailAccount $account, OutboundEmail $email): void;
}
