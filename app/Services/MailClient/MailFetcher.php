<?php

declare(strict_types=1);

namespace App\Services\MailClient;

use App\Models\EmailAccount;

/**
 * Pulls new messages for an account from its provider (plan B3). The I/O
 * boundary — implementations ({@see ImapFetcher}) do the provider-specific
 * fetching/parsing; everything downstream depends on this contract and is
 * fixture-tested with a stub.
 *
 * MUST be idempotent-friendly: it reads the account's sync cursor
 * (EmailAccount.sync_state) and returns only messages after it, plus the new
 * cursor to persist. On a cursor invalidation it returns a full re-sync (the
 * service dedups). On an AUTH failure it throws {@see \App\Exceptions\MailAuthException}
 * (terminal — the job stops retrying); transient/network errors propagate so the
 * job can retry with backoff.
 */
interface MailFetcher
{
    public function fetch(EmailAccount $account): FetchResult;
}
