<?php

declare(strict_types=1);

namespace App\Services\MailClient;

use App\Models\EmailAccount;

/**
 * Resolves an {@see EmailAccount}'s provider slug to its adapter (plan B2). The
 * single registration point — add a `case` + adapter class for Gmail/Graph
 * later. Mirrors EmailEventParserFactory.
 */
class MailAccountProviderFactory
{
    public function make(string $provider): ?MailAccountProvider
    {
        return match ($provider) {
            EmailAccount::PROVIDER_IMAP => new ImapSmtpProvider,
            EmailAccount::PROVIDER_GMAIL => new GmailProvider,
            EmailAccount::PROVIDER_GRAPH => new GraphProvider,
            default => null,
        };
    }

    public function for(EmailAccount $account): ?MailAccountProvider
    {
        return $this->make($account->provider);
    }

    public function fetcherFor(EmailAccount $account): ?MailFetcher
    {
        return match ($account->provider) {
            EmailAccount::PROVIDER_IMAP => new ImapFetcher,
            EmailAccount::PROVIDER_GMAIL => new ImapFetcher,
            EmailAccount::PROVIDER_GRAPH => new ImapFetcher,
            default => null,
        };
    }

    public function senderFor(EmailAccount $account): ?MailSender
    {
        return match ($account->provider) {
            EmailAccount::PROVIDER_IMAP => new SmtpSender,
            EmailAccount::PROVIDER_GMAIL => new SmtpSender,
            EmailAccount::PROVIDER_GRAPH => new SmtpSender,
            default => null,
        };
    }
}
