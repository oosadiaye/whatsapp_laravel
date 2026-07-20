<?php

declare(strict_types=1);

namespace App\Services\MailClient;

use App\Models\EmailAccount;

/**
 * Adapter for one mail-account backend (plan B2). IMAP/SMTP ships first
 * ({@see ImapSmtpProvider}); Gmail/Graph OAuth adapters would implement the same
 * contract, registered in {@see MailAccountProviderFactory} — mirroring the
 * EmailEventParser bounce-adapter pattern.
 *
 * Inbound fetch (B3) and outbound send (B5) extend this contract in their own
 * steps; B2 only needs to prove a connection.
 */
interface MailAccountProvider
{
    /**
     * Verify the account's stored credentials can actually connect (e.g. an IMAP
     * login). Never throws — returns a {@see ConnectionResult} carrying the
     * failure reason so the UI can surface it.
     */
    public function connectionTest(EmailAccount $account): ConnectionResult;
}
