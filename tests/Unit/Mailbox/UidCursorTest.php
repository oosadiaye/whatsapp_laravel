<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbox;

use App\Services\MailClient\UidCursor;
use PHPUnit\Framework\TestCase;

/** The sync pulls the newest mail first, then walks back through the history. */
final class UidCursorTest extends TestCase
{
    public function test_a_first_pass_over_a_big_folder_keeps_the_newest_and_marks_history_to_fetch(): void
    {
        $cursor = UidCursor::afterNewest(7, range(951, 1000), 50);

        $this->assertSame(['uidvalidity' => 7, 'last_uid' => 1000, 'backfill_below' => 951], $cursor);
    }

    public function test_a_short_batch_does_not_end_the_history_download(): void
    {
        // 49 of 50 came back (one message was deleted mid-fetch): older mail may still be there.
        $cursor = UidCursor::afterNewest(7, range(951, 999), 50);

        $this->assertSame(951, $cursor['backfill_below']);

        $done = UidCursor::afterIncremental(['backfill_below' => 951, 'last_uid' => 999], 7, [], [], 50);
        $this->assertSame(0, $done['backfill_below'], 'an empty history batch is the end of the folder');
    }

    public function test_a_first_pass_over_a_small_folder_has_no_history_left(): void
    {
        $cursor = UidCursor::afterNewest(7, [1, 2, 3], 50);

        $this->assertSame(0, $cursor['backfill_below']);
        $this->assertSame(3, $cursor['last_uid']);
    }

    public function test_an_empty_folder_starts_at_zero(): void
    {
        $this->assertSame(['uidvalidity' => 7, 'last_uid' => 0, 'backfill_below' => 0], UidCursor::afterNewest(7, [], 50));
    }

    public function test_history_is_pulled_backwards_until_the_start_of_the_folder(): void
    {
        $state = ['uidvalidity' => 7, 'last_uid' => 1000, 'backfill_below' => 951];

        $step = UidCursor::afterIncremental($state, 7, [], range(901, 950), 50);
        $this->assertSame(901, $step['backfill_below']);

        $last = UidCursor::afterIncremental(['backfill_below' => 30] + $state, 7, [], range(1, 29), 50);
        $this->assertSame(0, $last['backfill_below']);
    }

    public function test_new_mail_moves_the_newest_marker_up_without_touching_history_progress(): void
    {
        $state = ['uidvalidity' => 7, 'last_uid' => 1000, 'backfill_below' => 0];

        $cursor = UidCursor::afterIncremental($state, 7, [1001, 1002], [], 50);

        $this->assertSame(1002, $cursor['last_uid']);
        $this->assertSame(0, $cursor['backfill_below']);
    }

    public function test_a_full_batch_of_new_mail_is_finished_before_history_continues(): void
    {
        $state = ['uidvalidity' => 7, 'last_uid' => 1000, 'backfill_below' => 500];

        $cursor = UidCursor::afterIncremental($state, 7, range(1001, 1050), [], 50);

        $this->assertSame(500, $cursor['backfill_below']);
        $this->assertTrue(UidCursor::hasMore($cursor, 50, 50));
    }

    public function test_an_old_cursor_without_a_history_marker_is_treated_as_caught_up(): void
    {
        $cursor = UidCursor::afterIncremental(['uidvalidity' => 7, 'last_uid' => 40], 7, [41], [], 50);

        $this->assertSame(0, $cursor['backfill_below']);
        $this->assertFalse(UidCursor::hasMore($cursor, 1, 50));
    }

    public function test_a_changed_uidvalidity_or_a_missing_one_forces_a_restart(): void
    {
        $this->assertTrue(UidCursor::needsFullResync([], 7));
        $this->assertTrue(UidCursor::needsFullResync(['uidvalidity' => 6], 7));
        $this->assertFalse(UidCursor::needsFullResync(['uidvalidity' => 7], 7));
    }
}
