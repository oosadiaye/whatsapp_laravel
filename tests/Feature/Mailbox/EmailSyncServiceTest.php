<?php

declare(strict_types=1);

namespace Tests\Feature\Mailbox;

use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Services\MailClient\EmailSyncService;
use App\Services\MailClient\FetchedAttachment;
use App\Services\MailClient\FetchedMessage;
use App\Services\MailClient\FetchResult;
use App\Services\MailClient\MailFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Plan B3 — the inbound-sync LOGIC, fixture-tested end-to-end with a stub fetcher
 * (no live IMAP server): dedup, header threading, attachments, cursor advance.
 */
class EmailSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): EmailSyncService
    {
        return new EmailSyncService();
    }

    private function fetcherReturning(FetchResult $result): MailFetcher
    {
        return new class($result) implements MailFetcher {
            public function __construct(private readonly FetchResult $result)
            {
            }

            public function fetch(EmailAccount $account): FetchResult
            {
                return $this->result;
            }
        };
    }

    /**
     * @param  list<FetchedAttachment>  $attachments
     */
    private function msg(
        string $id,
        ?string $inReplyTo = null,
        ?string $references = null,
        string $from = 'ann@example.com',
        ?string $subject = 'Subject',
        array $attachments = [],
    ): FetchedMessage {
        return new FetchedMessage(
            messageId: $id,
            inReplyTo: $inReplyTo,
            references: $references,
            from: $from,
            to: ['me@work.test'],
            cc: [],
            subject: $subject,
            bodyHtml: '<p>Body</p>',
            bodyText: 'Body',
            date: now(),
            attachments: $attachments,
        );
    }

    private function fetchResult(array $messages, int $lastUid = 1, int $uidValidity = 1): FetchResult
    {
        return new FetchResult($messages, ['inbox' => ['uidvalidity' => $uidValidity, 'last_uid' => $lastUid]]);
    }

    public function test_a_new_message_creates_a_thread_and_advances_the_cursor(): void
    {
        $account = EmailAccount::factory()->create();

        $new = $this->service()->sync($account, $this->fetcherReturning(
            $this->fetchResult([$this->msg('<root@example.com>')], lastUid: 7),
        ));

        $this->assertSame(1, $new);
        $this->assertSame(1, EmailThread::where('email_account_id', $account->id)->count());
        $thread = EmailThread::first();
        $this->assertSame(1, $thread->messages()->count());
        $this->assertSame(1, $thread->unread_count);
        // message_id normalised (brackets stripped) for consistent dedup/threading.
        $this->assertSame('root@example.com', $thread->messages()->first()->message_id);
        $this->assertSame(['inbox' => ['uidvalidity' => 1, 'last_uid' => 7]], $account->fresh()->sync_state);
        $this->assertNotNull($account->fresh()->last_synced_at);
    }

    public function test_a_reply_joins_the_existing_thread_by_in_reply_to(): void
    {
        $account = EmailAccount::factory()->create();
        $svc = $this->service();

        $svc->sync($account, $this->fetcherReturning($this->fetchResult([$this->msg('<root@example.com>')])));
        $svc->sync($account, $this->fetcherReturning($this->fetchResult([
            $this->msg('<reply@example.com>', inReplyTo: '<root@example.com>'),
        ], lastUid: 2)));

        $this->assertSame(1, EmailThread::where('email_account_id', $account->id)->count());
        $this->assertSame(2, EmailThread::first()->messages()->count());
        $this->assertSame(2, EmailThread::first()->unread_count);
    }

    public function test_a_reply_threads_by_the_references_chain(): void
    {
        $account = EmailAccount::factory()->create();
        $svc = $this->service();

        $svc->sync($account, $this->fetcherReturning($this->fetchResult([$this->msg('<root@example.com>')])));
        $svc->sync($account, $this->fetcherReturning($this->fetchResult([
            $this->msg('<deep@example.com>', references: '<unknown@z.com> <root@example.com>'),
        ], lastUid: 2)));

        $this->assertSame(1, EmailThread::where('email_account_id', $account->id)->count());
        $this->assertSame(2, EmailThread::first()->messages()->count());
    }

    public function test_an_unrelated_message_starts_a_new_thread(): void
    {
        $account = EmailAccount::factory()->create();
        $svc = $this->service();

        $svc->sync($account, $this->fetcherReturning($this->fetchResult([$this->msg('<one@example.com>', subject: 'A')])));
        $svc->sync($account, $this->fetcherReturning($this->fetchResult([$this->msg('<two@example.com>', subject: 'B')], lastUid: 2)));

        $this->assertSame(2, EmailThread::where('email_account_id', $account->id)->count());
    }

    public function test_a_redelivered_message_is_not_duplicated(): void
    {
        // Simulates a full UIDVALIDITY re-sync re-emitting an already-stored message.
        $account = EmailAccount::factory()->create();
        $svc = $this->service();
        $fetch = fn () => $this->fetcherReturning($this->fetchResult([$this->msg('<dup@example.com>')]));

        $svc->sync($account, $fetch());
        $secondRun = $svc->sync($account, $fetch());

        $this->assertSame(0, $secondRun, 'a redelivered message stores nothing new');
        $this->assertSame(1, EmailMessage::where('email_account_id', $account->id)->count());
        $this->assertSame(1, EmailThread::where('email_account_id', $account->id)->count());
    }

    public function test_attachments_are_written_to_the_private_disk(): void
    {
        Storage::fake('local');
        $account = EmailAccount::factory()->create();

        $this->service()->sync($account, $this->fetcherReturning($this->fetchResult([
            $this->msg('<att@example.com>', attachments: [
                new FetchedAttachment('report.pdf', 'application/pdf', 'PDF-BYTES'),
            ]),
        ])));

        $message = EmailMessage::first();
        $this->assertTrue($message->has_attachments);
        $this->assertCount(1, $message->attachments);
        $attachment = $message->attachments->first();
        $this->assertSame('report.pdf', $attachment->filename);
        $this->assertSame(strlen('PDF-BYTES'), $attachment->size);
        Storage::disk('local')->assertExists($attachment->path);
    }
}
