<?php

declare(strict_types=1);

namespace Tests\Feature\Mailbox;

use App\Exceptions\MailAuthException;
use App\Jobs\SyncEmailAccount;
use App\Models\EmailAccount;
use App\Services\MailClient\EmailSyncService;
use App\Services\MailClient\FetchResult;
use App\Services\MailClient\MailAccountProviderFactory;
use App\Services\MailClient\MailFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Plan B3 — the sync job's failure handling: auth failure is terminal
 * (needs_reauth, no retry), transient errors propagate (retry with backoff), and
 * unhealthy accounts are skipped.
 */
class SyncEmailAccountJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A factory whose fetcherFor returns a fetcher that runs $onFetch.
     */
    private function factoryFetching(\Closure $onFetch): MailAccountProviderFactory
    {
        return new class($onFetch) extends MailAccountProviderFactory
        {
            public function __construct(private readonly \Closure $onFetch) {}

            public function fetcherFor(EmailAccount $account): ?MailFetcher
            {
                $onFetch = $this->onFetch;

                return new class($onFetch) implements MailFetcher
                {
                    public function __construct(private readonly \Closure $onFetch) {}

                    public function fetch(EmailAccount $account): FetchResult
                    {
                        return ($this->onFetch)($account);
                    }
                };
            }
        };
    }

    public function test_auth_failure_flags_reauth_and_does_not_rethrow(): void
    {
        $account = EmailAccount::factory()->create(['is_active' => true, 'needs_reauth' => false]);

        $factory = $this->factoryFetching(fn () => throw new MailAuthException('invalid credentials'));

        // Must NOT throw — a retry can't fix bad creds.
        (new SyncEmailAccount($account->id))->handle(new EmailSyncService, $factory);

        $this->assertTrue($account->fresh()->needs_reauth);
    }

    public function test_a_transient_error_propagates_so_the_job_retries(): void
    {
        $account = EmailAccount::factory()->create(['is_active' => true]);

        $factory = $this->factoryFetching(fn () => throw new RuntimeException('connection reset'));

        $this->expectException(RuntimeException::class);
        (new SyncEmailAccount($account->id))->handle(new EmailSyncService, $factory);
    }

    public function test_an_inactive_account_is_skipped(): void
    {
        $account = EmailAccount::factory()->create(['is_active' => false]);

        // fetcherFor would throw if the job didn't skip first.
        $factory = $this->factoryFetching(fn () => throw new RuntimeException('should not be called'));

        (new SyncEmailAccount($account->id))->handle(new EmailSyncService, $factory);

        $this->assertFalse($account->fresh()->needs_reauth);
    }

    public function test_a_mailbox_with_more_mail_waiting_queues_its_next_batch(): void
    {
        Queue::fake();
        $account = EmailAccount::factory()->create(['is_active' => true]);
        $factory = $this->factoryFetching(fn () => new FetchResult([], ['inbox' => ['uidvalidity' => 1, 'last_uid' => 50]], hasMore: true));

        (new SyncEmailAccount($account->id))->handle(new EmailSyncService, $factory);

        Queue::assertPushed(SyncEmailAccount::class, fn (SyncEmailAccount $job): bool => $job->accountId === $account->id);
    }

    public function test_a_caught_up_mailbox_queues_nothing_more(): void
    {
        Queue::fake();
        $account = EmailAccount::factory()->create(['is_active' => true]);
        $factory = $this->factoryFetching(fn () => new FetchResult([], ['inbox' => ['uidvalidity' => 1, 'last_uid' => 3]]));

        (new SyncEmailAccount($account->id))->handle(new EmailSyncService, $factory);

        Queue::assertNothingPushed();
    }
}
