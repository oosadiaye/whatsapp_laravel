<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\BoardReport;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Board reporting.
 *
 * Most of these are about not lying. The counting is easy; the two claims that
 * are easy to get subtly wrong are the time-in-column average (which silently
 * drops the column a card is sitting in right now if you write the obvious
 * query) and the coverage figures (an upgraded board's log starts empty, so a
 * report with no caveat reads as complete when it is not).
 */
class BoardReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(TaskStatusSeeder::class);
    }

    private function board(): Board
    {
        $owner = User::factory()->create();

        return Board::create([
            'user_id' => $owner->id,
            'name' => 'Support',
            'slug' => 'support-'.uniqid(),
            'color' => '#6366f1',
        ]);
    }

    private function task(Board $board, string $status = 'todo'): Task
    {
        // A unique position within the column: the schema forbids two cards
        // sharing a (board, status, position).
        $position = (int) Task::where('board_id', $board->id)->where('status', $status)->max('position') + 1;

        return Task::create([
            'user_id' => $board->user_id,
            'board_id' => $board->id,
            'title' => 'A card',
            'status' => $status,
            'position' => $position,
        ]);
    }

    /**
     * Write a movement row directly, backdated.
     *
     * Deliberately not through the Livewire action: a report test that drove
     * every move through the UI would be testing the UI, and would make the
     * time-in-column cases (which need moves at chosen timestamps) impossible
     * to express.
     *
     * created_at is set after the insert rather than passed into create(),
     * because it is not mass-assignable — and that is the model behaving
     * correctly, not an obstacle. Silently dropping a caller-supplied
     * timestamp is exactly what stops a request payload backdating history.
     */
    private function record(Board $board, int $taskId, string $type, ?string $to, ?string $from = null, int $daysAgo = 7): TaskActivity
    {
        $row = TaskActivity::create([
            'task_id' => $taskId,
            'board_id' => $board->id,
            'user_id' => null,
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
        ]);

        $row->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $row;
    }

    private function move(Board $board, Task $task, string $to, string $from = 'todo', int $daysAgo = 7): void
    {
        $this->record($board, (int) $task->id, TaskActivity::TYPE_STATUS_CHANGED, $to, $from, $daysAgo);
    }

    public function test_it_counts_cards_in_every_column_including_the_empty_ones(): void
    {
        $board = $this->board();
        $this->task($board, 'todo');
        $this->task($board, 'todo');
        $this->task($board, 'in_progress');

        $counts = (new BoardReport($board))->statusCounts();

        // Every configured column is present. An absent column reads as "deleted",
        // when the useful fact is "nobody has put anything here yet".
        $this->assertSame(array_keys(TaskStatus::columns()), array_keys($counts));
        $this->assertSame(2, $counts['todo']['count']);
        $this->assertSame(1, $counts['in_progress']['count']);
        $this->assertSame(0, $counts['review']['count']);
    }

    public function test_a_card_in_a_deleted_column_still_appears(): void
    {
        $board = $this->board();
        $this->task($board, 'a_column_that_no_longer_exists');

        $counts = (new BoardReport($board))->statusCounts();

        // Dropping it would make the column totals disagree with the card total,
        // which is the kind of report nobody trusts twice.
        $this->assertArrayHasKey('a_column_that_no_longer_exists', $counts);
        $this->assertSame(1, $counts['a_column_that_no_longer_exists']['count']);
        $this->assertFalse($counts['a_column_that_no_longer_exists']['known']);
        // A retired column keeps no ceiling, so it is not "over" - it is flagged
        // as undefined instead, which is a different problem.
        $this->assertNull($counts['a_column_that_no_longer_exists']['limit']);
        $this->assertFalse($counts['a_column_that_no_longer_exists']['over']);
    }

    public function test_column_counts_carry_the_advisory_limit(): void
    {
        $board = $this->board();
        TaskStatus::where('slug', 'review')->firstOrFail()->forceFill(['wip_limit' => 2])->save();

        $this->task($board, 'review');
        $this->task($board, 'review');
        $this->task($board, 'todo');

        $counts = (new BoardReport($board))->statusCounts();

        // So the table answers "which columns are over?" without the reader
        // cross-checking every count against the settings screen.
        $this->assertSame(2, $counts['review']['limit']);
        $this->assertFalse($counts['review']['over']);

        $this->task($board, 'review');

        $counts = (new BoardReport($board))->statusCounts();

        $this->assertSame(3, $counts['review']['count']);
        $this->assertTrue($counts['review']['over']);
    }

    public function test_a_column_without_a_limit_is_never_over(): void
    {
        $board = $this->board();
        $this->task($board, 'todo');
        $this->task($board, 'todo');
        $this->task($board, 'todo');

        $counts = (new BoardReport($board))->statusCounts();

        $this->assertNull($counts['todo']['limit']);
        $this->assertFalse($counts['todo']['over']);
    }

    public function test_a_finished_column_is_never_reported_as_over_its_limit(): void
    {
        $board = $this->board();
        // Belt and braces for the same reason as the model test: a permanently
        // red Done column would train people to ignore the flag everywhere.
        TaskStatus::where('slug', 'done')->firstOrFail()->forceFill(['wip_limit' => 1])->save();

        $this->task($board, 'done');
        $this->task($board, 'done');
        $this->task($board, 'done');

        $counts = (new BoardReport($board))->statusCounts();

        $this->assertSame(3, $counts['done']['count']);
        $this->assertNull($counts['done']['limit']);
        $this->assertFalse($counts['done']['over']);
    }

    public function test_throughput_counts_distinct_cards_per_week(): void
    {
        $board = $this->board();
        $task = $this->task($board, 'done');

        $this->record($board, (int) $task->id, TaskActivity::TYPE_STATUS_CHANGED, 'done', 'in_progress', 0);

        $series = (new BoardReport($board))->throughput(8);

        $this->assertSame(1, array_sum(array_column($series, 'count')));
    }

    public function test_throughput_counts_one_card_once_even_if_finished_twice_in_a_week(): void
    {
        $board = $this->board();
        $task = $this->task($board, 'done');

        foreach (['in_progress', 'todo', 'done'] as $from) {
            $this->record($board, (int) $task->id, TaskActivity::TYPE_STATUS_CHANGED, 'done', $from, 0);
        }

        $series = (new BoardReport($board))->throughput(8);

        // Three transitions into "done", one card finished. Counting transitions
        // would have reported a team closing the same card three times.
        $this->assertSame(1, array_sum(array_column($series, 'count')));
    }

    public function test_throughput_includes_empty_weeks(): void
    {
        $board = $this->board();

        $series = (new BoardReport($board))->throughput(8);

        // A series that omits empty weeks draws a straight line across the gap
        // and reads as steady output, which is the opposite of what happened.
        $this->assertCount(8, $series);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0], array_column($series, 'count'));
    }

    public function test_time_in_column_measures_a_card_still_sitting_in_a_column(): void
    {
        $board = $this->board();
        $task = $this->task($board, 'todo');

        // Created three days ago, never moved. The naive "gap to the next
        // transition" query returns nothing here, because there is no next
        // transition — which drops exactly the column a team most wants to watch.
        $task->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->record($board, (int) $task->id, TaskActivity::TYPE_CREATED, 'todo', null, 3);

        $time = (new BoardReport($board))->timeInColumn();

        $this->assertSame(1, $time['todo']['intervals']);
        $this->assertSame(1, $time['todo']['open'], 'An interval with no departure yet is open.');
        // ~3 days. Compared as a range because the assertion should not fail on
        // a slow machine taking four seconds between the two now() calls.
        $this->assertGreaterThanOrEqual(3 * 86400 - 60, $time['todo']['total_seconds']);
        $this->assertLessThanOrEqual(3 * 86400 + 60, $time['todo']['total_seconds']);
    }

    public function test_time_in_column_measures_a_closed_interval(): void
    {
        $board = $this->board();
        $task = $this->task($board, 'in_progress');

        $this->record($board, (int) $task->id, TaskActivity::TYPE_CREATED, 'todo', null, 10);
        $this->move($board, $task, 'in_progress', 'todo', 7);

        $time = (new BoardReport($board))->timeInColumn();

        // Three days in "todo" before the move.
        $this->assertSame(1, $time['todo']['intervals']);
        $this->assertSame(0, $time['todo']['open']);
        $this->assertGreaterThanOrEqual(3 * 86400 - 60, $time['todo']['total_seconds']);
        $this->assertLessThanOrEqual(3 * 86400 + 60, $time['todo']['total_seconds']);
    }

    public function test_time_in_column_leaves_out_time_before_the_first_logged_move(): void
    {
        $board = $this->board();
        $task = $this->task($board, 'in_progress');
        // No created row — the card predates the log. Its time in "todo" is
        // unknowable, so it must not be back-filled from tasks.created_at.
        $task->forceFill(['created_at' => now()->subDays(100)])->save();
        $this->move($board, $task, 'in_progress', 'todo');

        $time = (new BoardReport($board))->timeInColumn();

        // "todo" is shown (every column gets a row) but with no measured time.
        $this->assertArrayHasKey('todo', $time);
        $this->assertSame(0, $time['todo']['intervals'], 'A guess is not a measurement.');
    }

    public function test_a_deleted_card_is_excluded_from_the_report(): void
    {
        $board = $this->board();
        $task = $this->task($board, 'todo');
        $this->move($board, $task, 'in_progress', 'todo');
        $task->delete();

        $report = new BoardReport($board);

        // The report is about the board as it stands. The history rows still
        // exist and are still correct — they are just not current health.
        $this->assertSame(0, array_sum(array_column($report->statusCounts(), 'count')));
        $this->assertSame(0, $report->coverage()['live_cards']);
    }

    public function test_coverage_reports_when_history_began(): void
    {
        $board = $this->board();
        $task = $this->task($board, 'todo');
        $this->move($board, $task, 'in_progress', 'todo');

        $coverage = (new BoardReport($board))->coverage();

        $this->assertNotNull($coverage['history_starts_at']);
        $this->assertSame(0, $coverage['cards_without_movements']);
        $this->assertSame(1, $coverage['live_cards']);
    }

    public function test_coverage_flags_cards_with_no_recorded_movements(): void
    {
        $board = $this->board();
        $this->task($board, 'todo'); // never touched since the log shipped
        $moved = $this->task($board, 'todo');
        $this->move($board, $moved, 'in_progress', 'todo');

        $coverage = (new BoardReport($board))->coverage();

        // Without this number a board with months of history and a log that
        // started last week looks like a board that did nothing last week.
        $this->assertSame(1, $coverage['cards_without_movements']);
        $this->assertSame(2, $coverage['live_cards']);
    }

    public function test_coverage_on_a_board_that_has_never_moved_a_card(): void
    {
        $board = $this->board();
        $this->task($board, 'todo');

        $coverage = (new BoardReport($board))->coverage();

        $this->assertNull($coverage['history_starts_at']);
        $this->assertSame(1, $coverage['cards_without_movements']);
    }

    public function test_coverage_does_not_count_a_comment_as_a_movement(): void
    {
        $board = $this->board();
        $commented = $this->task($board, 'todo');

        $this->record($board, $commented->id, TaskActivity::TYPE_COMMENTED, null, null, 3);

        $coverage = (new BoardReport($board))->coverage();

        // A card can have an activity row and still no measurable column
        // history. Filtering on "has any activity" reported this as covered,
        // which is the one thing the coverage figure exists to prevent.
        $this->assertSame(1, $coverage['cards_without_movements']);
        $this->assertNull($coverage['history_starts_at']);
    }

    public function test_coverage_counts_a_card_with_only_a_comment_and_a_move_as_covered(): void
    {
        $board = $this->board();
        $task = $this->task($board, 'todo');

        $this->record($board, $task->id, TaskActivity::TYPE_COMMENTED, null, null, 5);
        $this->move($board, $task, 'in_progress', 'todo', 2);

        $coverage = (new BoardReport($board))->coverage();

        $this->assertSame(0, $coverage['cards_without_movements']);
        $this->assertNotNull($coverage['history_starts_at']);
    }

    public function test_assignee_load_counts_open_overdue_and_finished(): void
    {
        $board = $this->board();
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $open = $this->task($board, 'todo');
        $open->assignees()->attach($alice->id);
        $open->forceFill(['due_at' => now()->subDay()])->save();

        $finished = $this->task($board, 'done');
        $finished->assignees()->attach($alice->id);

        $bobs = $this->task($board, 'todo');
        $bobs->assignees()->attach($bob->id);

        $rows = collect((new BoardReport($board))->assigneeLoad())->keyBy('name');
        $aliceName = User::find($alice->id)->name;
        $bobName = User::find($bob->id)->name;

        $this->assertSame(1, $rows[$aliceName]['open']);
        $this->assertSame(1, $rows[$aliceName]['overdue']);
        $this->assertSame(1, $rows[$aliceName]['completed']);
        $this->assertSame(1, $rows[$bobName]['open']);
    }

    public function test_unassigned_cards_get_their_own_row(): void
    {
        $board = $this->board();
        $this->task($board, 'todo');

        $rows = (new BoardReport($board))->assigneeLoad();

        // Work with nobody on it is a real failure state, and burying it under
        // the named people is how it stays invisible.
        $this->assertCount(1, $rows);
        $this->assertSame('Unassigned', $rows[0]['name']);
        $this->assertNull($rows[0]['user']);
        $this->assertSame(1, $rows[0]['open']);
    }

    public function test_a_card_with_several_assignees_counts_for_each_of_them(): void
    {
        $board = $this->board();
        $one = User::factory()->create();
        $two = User::factory()->create();

        $task = $this->task($board, 'todo');
        $task->assignees()->attach([$one->id, $two->id]);

        $rows = collect((new BoardReport($board))->assigneeLoad())->keyBy('name');

        // Effort-weighted, not card-weighted: the rows deliberately sum to more
        // than the board's card count, and the view says so.
        $this->assertSame(1, $rows[User::find($one->id)->name]['open']);
        $this->assertSame(1, $rows[User::find($two->id)->name]['open']);
    }

    public function test_a_finished_card_is_never_counted_as_overdue(): void
    {
        $board = $this->board();
        $alice = User::factory()->create();

        $task = $this->task($board, 'done');
        $task->assignees()->attach($alice->id);
        $task->forceFill(['due_at' => now()->subDays(10)])->save();

        $rows = (new BoardReport($board))->assigneeLoad();

        // Same rule the board itself uses: marking finished work red is how a
        // board stops being believed, and a report that contradicts the board
        // is the first place that gets noticed.
        $this->assertSame(0, $rows[0]['overdue']);
        $this->assertSame(1, $rows[0]['completed']);
    }

    public function test_throughput_rejects_an_unbounded_window(): void
    {
        $board = $this->board();

        $this->expectException(\InvalidArgumentException::class);

        // The series is a table of one row per week, so an unbounded value is a
        // page that cannot render rather than a bigger report.
        (new BoardReport($board))->throughput(500);
    }

    public function test_throughput_range_counts_only_completions_in_the_window(): void
    {
        $board = $this->board();
        $inside = $this->task($board);
        $outside = $this->task($board);

        // Completed five days ago — inside the window.
        $this->record($board, (int) $inside->id, TaskActivity::TYPE_STATUS_CHANGED, 'done', 'todo', 5);
        // Completed forty days ago — before the window started, so it must not count.
        $this->record($board, (int) $outside->id, TaskActivity::TYPE_STATUS_CHANGED, 'done', 'todo', 40);

        $from = now()->subDays(10)->startOfDay();
        $to = now()->endOfDay();

        $series = (new BoardReport($board, $from, $to))->throughputRange($from, $to);

        // Exactly one completion in the window, regardless of weeks rendered.
        $this->assertSame(1, array_sum(array_column($series, 'count')));
        // The window is bounded to at most MAX_WEEKS rows, never unbounded.
        $this->assertLessThanOrEqual(52, count($series));
        $this->assertGreaterThanOrEqual(1, count($series));
    }

    public function test_a_custom_range_clamps_to_max_weeks(): void
    {
        $board = $this->board();

        $from = now()->subYears(5)->startOfDay();
        $to = now()->endOfDay();

        $series = (new BoardReport($board, $from, $to))->throughputRange($from, $to);

        $this->assertSame(52, count($series));
    }

    public function test_movements_range_excludes_history_outside_the_window(): void
    {
        $board = $this->board();
        $inside = $this->task($board);
        $outside = $this->task($board);

        // The only in-window move is five days ago; the older one must not pull
        // the "history starts" figure backwards or leak into time-in-column.
        $this->record($board, (int) $inside->id, TaskActivity::TYPE_STATUS_CHANGED, 'in_progress', 'todo', 5);
        $this->record($board, (int) $outside->id, TaskActivity::TYPE_STATUS_CHANGED, 'in_progress', 'todo', 40);

        $from = now()->subDays(10)->startOfDay();
        $to = now()->endOfDay();

        $coverage = (new BoardReport($board, $from, $to))->coverage();

        // History starts at the in-window move, not the 40-day-old one.
        $this->assertNotNull($coverage['history_starts_at']);
        $this->assertTrue($coverage['history_starts_at']->greaterThanOrEqualTo($from));
        $this->assertTrue($coverage['history_starts_at']->lessThanOrEqualTo($to));
    }

    public function test_the_report_route_honours_a_date_range(): void
    {
        $manager = User::factory()->create(['is_active' => true]);
        $manager->assignRole('manager');

        $board = $this->board();
        // Owned boards are needed for the route; re-point ownership at the manager.
        $board->update(['user_id' => $manager->id]);

        $task = $this->task($board, 'todo');
        $this->record($board, (int) $task->id, TaskActivity::TYPE_STATUS_CHANGED, 'done', 'todo', 5);

        $this->actingAs($manager);

        $from = now()->subDays(10)->toDateString();
        $to = now()->toDateString();

        $this->get(route('boards.report', ['board' => $board, 'from' => $from, 'to' => $to]))
            ->assertOk()
            ->assertSee($from)
            ->assertSee($to);

        // An unparseable date falls back to the weeks window rather than 500-ing.
        $this->get(route('boards.report', ['board' => $board, 'from' => 'not-a-date']))
            ->assertOk();
    }
}
