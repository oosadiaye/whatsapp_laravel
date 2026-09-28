<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Board;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskStatus;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Read-only numbers about one board.
 *
 * Everything here is a query over what the app already stores, so nothing in
 * this class writes. The four reports are deliberately separate methods rather
 * than one configurable query: they answer different questions, and a single
 * parameterised query would have to express all four badly to share any code.
 *
 * The one genuinely hard number is time-in-column, and the reason is written
 * out on that method. The other three are counting, and the care they need is
 * the ordinary kind: show the zeros, and never report a number without saying
 * what it does not cover.
 */
class BoardReport
{
    /** @var Collection<int, Task>|null */
    private ?Collection $liveTasks = null;

    /**
     * Rows that describe a card changing column, oldest first within a card.
     *
     * Created and status_changed only. A comment does not move a card, so
     * including it would put a phantom entry in the timeline.
     *
     * @var Collection<int, object>|null
     */
    private ?Collection $movements = null;

    public function __construct(
        private readonly Board $board,
        private readonly ?CarbonInterface $from = null,
        private readonly ?CarbonInterface $to = null,
    ) {}

    /**
     * How many cards sit in each column right now.
     *
     * Keyed by slug over every column, including the ones with no cards. A
     * column missing from the table reads as "this column was deleted", when the
     * truth people care about is usually "nobody has put anything here yet" —
     * which is exactly the signal that makes a WIP limit worth setting.
     *
     * `limit` and `over` carry the column's advisory ceiling (3.5) so this
     * table answers "which columns are over?" without the reader cross-checking
     * each count against the settings screen. `over` is false rather than
     * absent for a column with no ceiling, so the view needs no null check.
     *
     * @return array<string, array{name: string, color: string, count: int, completed: bool, known: bool, limit: int|null, over: bool}>
     */
    public function statusCounts(): array
    {
        $counts = $this->liveTasks()
            ->countBy('status')
            ->map(fn (int $n): int => $n)
            ->all();

        $rows = [];

        foreach (TaskStatus::columns() as $slug => $column) {
            $count = (int) ($counts[$slug] ?? 0);
            $limit = isset($column['limit']) ? (int) $column['limit'] : null;

            $rows[$slug] = [
                'name' => (string) $column['name'],
                'color' => (string) $column['color'],
                'count' => $count,
                'completed' => (bool) $column['completed'],
                'known' => true,
                'limit' => $limit,
                'over' => $limit !== null && $count > $limit,
            ];

            unset($counts[$slug]);
        }

        // Cards in a column that no longer exists. Possible, because deleting a
        // column with cards still in it is a decision this app allows, and the
        // alternative is dropping those cards out of the report entirely — which
        // would make the column total disagree with the card total. label()
        // title-cases an unknown slug, so these read sanely rather than raw.
        foreach ($counts as $slug => $count) {
            $rows[(string) $slug] = [
                'name' => TaskStatus::label((string) $slug),
                'color' => 'gray',
                'count' => (int) $count,
                'completed' => false,
                // Rendered as a warning: this is a column with no definition, not
                // a column that was never there.
                'known' => false,
                // A retired column keeps no ceiling, so there is nothing to be
                // over — flagged separately, not silently compliant.
                'limit' => null,
                'over' => false,
            ];
        }

        return $rows;
    }

    /**
     * Cards that reached a completed column, per week.
     *
     * Counted as distinct cards per week rather than distinct transitions. A
     * card dragged into "done", dragged back out, and finished again next week
     * is one card finished next week, not two completions — and a team asking
     * "how much did we get done" wants the former number.
     *
     * Weeks with nothing are present as zeros. A series that silently omits
     * empty weeks draws a straight line across the gap and reads as steady
     * output, which is the opposite of what happened.
     *
     * @return array<int, array{week_start: string, label: string, count: int, task_ids: array<int, int>}>
     */
    public function throughput(int $weeks = 8): array
    {
        $this->assertWeeks($weeks);

        if (TaskStatus::completedSlugs() === []) {
            return $this->emptyWeekSeries($weeks);
        }

        return $this->applyWeeklyCompletions($this->emptyWeekSeries($weeks));
    }

    /**
     * Throughput bounded by an explicit window instead of "the last N weeks".
     *
     * The series runs from the week containing `$to` back to the week containing
     * `$from`, clamped to MAX_WEEKS so a year-long range does not render a
     * thousand rows — the most recent MAX_WEEKS weeks before `$to` win, which is
     * the same "newest bucket last" convention emptyWeekSeries() uses. The
     * movement filter (set on the constructor) still keeps every count inside
     * the window, so a clamped range never shows a completion from outside it.
     *
     * @return array<int, array{week_start: string, label: string, count: int, task_ids: array<int, int>}>
     */
    public function throughputRange(CarbonInterface $from, CarbonInterface $to): array
    {
        // copy() because startOfWeek()/subWeeks() mutate: the $from/$to handed in
        // are also the constructor's stored boundaries used by movements(), and
        // clobbering them would silently shrink the window.
        $fromStart = $from->copy()->startOfWeek();
        $toStart = $to->copy()->startOfWeek();

        $weeks = (int) min(self::MAX_WEEKS, max(1, $fromStart->diffInWeeks($toStart) + 1));
        $start = $toStart->copy()->subWeeks($weeks - 1);

        $series = [];
        for ($i = 0; $i < $weeks; $i++) {
            $week = $start->copy()->addWeeks($i)->startOfWeek();

            $series[] = [
                'week_start' => $week->toDateString(),
                'label' => $week->format('j M'),
                'count' => 0,
                'task_ids' => [],
            ];
        }

        if (TaskStatus::completedSlugs() === []) {
            return $series;
        }

        return $this->applyWeeklyCompletions($series);
    }

    /**
     * Fold the completed-column movements into a week series, counting each card
     * once per week even if it finished twice.
     *
     * @param  array<int, array{week_start: string, label: string, count: int, task_ids: array<int, int>}>  $series
     * @return array<int, array{week_start: string, label: string, count: int, task_ids: array<int, int>}>
     */
    private function applyWeeklyCompletions(array $series): array
    {
        $completed = TaskStatus::completedSlugs();

        // startOfWeek() is locale-dependent, so the bucket boundary is whatever
        // Carbon says the week starts on. Recorded here because the series is
        // only comparable to itself if every row uses the same boundary.
        $perWeek = $this->movements()
            ->filter(fn (object $row): bool => in_array((string) $row->to_status, $completed, true))
            ->groupBy(fn (object $row): string => Carbon::parse($row->created_at)->startOfWeek()->toDateString());

        foreach ($perWeek as $weekStart => $rows) {
            foreach ($series as $index => $entry) {
                if ($entry['week_start'] === $weekStart) {
                    // unique() so a card that completed twice in one week counts once.
                    $ids = $rows->pluck('task_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
                    $series[$index]['count'] = count($ids);
                    $series[$index]['task_ids'] = $ids;
                    break;
                }
            }
        }

        return $series;
    }

    /**
     * Average and total time cards have spent in each column.
     *
     * This is the two-sided calculation, and the naive version of it is wrong in
     * a way that matters. "For each transition, the gap to the next one" only
     * ever closes an interval — it measures the columns a card has *left*. The
     * column a card is sitting in right now has no next transition yet, so that
     * query drops it, and it drops exactly the column a team most wants to watch
     * (the one everything is piling up in). So every card contributes its final
     * interval too, measured up to now.
     *
     * Two things are deliberately *not* faked:
     *
     * - A card whose only recorded rows are status changes has an unknown entry
     *    time for the column it started in, so the time before its first logged
     *    move is left out rather than back-filled from tasks.created_at. That
     *    would be a guess presented as a measurement. Those cards are counted
     *    in coverage() so the shortfall is visible.
     * - If the last logged move does not end in the card's current column — which
     *    means some move went unrecorded — the trailing interval is skipped
     *    rather than closed at the current column, since its length is unknown.
     *
     * @return array<string, array{name: string, color: string, completed: bool, intervals: int, total_seconds: int, average_seconds: int, average_label: string, open: int}>
     */
    public function timeInColumn(): array
    {
        $now = now();
        $totals = [];

        $live = $this->liveTasks()->keyBy('id');

        foreach ($this->movements()->groupBy('task_id') as $taskId => $rows) {
            $task = $live->get((int) $taskId);

            if ($task === null) {
                continue;
            }

            $entries = $this->entriesFor($rows->all());

            if ($entries === []) {
                continue;
            }

            $last = count($entries) - 1;

            foreach ($entries as $index => $entry) {
                $isOpen = $index === $last;

                // The open interval is only knowable when the card is still where
                // the log last put it. See the method docblock.
                if ($isOpen && $entry['column'] !== $task->status) {
                    continue;
                }

                $end = $isOpen ? $now : $entries[$index + 1]['at'];

                // Negative durations are impossible from this data, but a
                // clock change or a hand-edited row could produce one, and a
                // negative average would be worse than no average. Cast because
                // Carbon 3 returns diffInSeconds() as a float, and letting one
                // through turns every later intdiv() on the total into a
                // TypeError.
                $seconds = (int) max(0, $end->diffInSeconds($entry['at'], absolute: true));

                $bucket = $totals[$entry['column']] ?? [
                    'intervals' => 0,
                    'total_seconds' => 0,
                    'open' => 0,
                ];

                $bucket['intervals']++;
                $bucket['total_seconds'] += $seconds;

                if ($isOpen) {
                    $bucket['open']++;
                }

                $totals[$entry['column']] = $bucket;
            }
        }

        $rows = [];

        foreach (TaskStatus::columns() as $slug => $column) {
            $bucket = $totals[$slug] ?? ['intervals' => 0, 'total_seconds' => 0, 'open' => 0];
            $average = $bucket['intervals'] > 0
                ? intdiv($bucket['total_seconds'], $bucket['intervals'])
                : 0;

            $rows[$slug] = [
                'name' => (string) $column['name'],
                'color' => (string) $column['color'],
                'completed' => (bool) $column['completed'],
                'intervals' => $bucket['intervals'],
                'total_seconds' => $bucket['total_seconds'],
                'average_seconds' => $average,
                'average_label' => $this->formatDuration($average),
                // How many of those intervals are still open, i.e. counted up to
                // now rather than to a real departure. A column whose average is
                // mostly open intervals is a different measurement from one that
                // is mostly closed, and the two should not be compared silently.
                'open' => $bucket['open'],
            ];

            unset($totals[$slug]);
        }

        // A column that was deleted mid-flight still has real time in it. It
        // gets a row rather than being folded into a neighbour, because "where
        // did the time go" is the question this table exists to answer.
        foreach ($totals as $slug => $bucket) {
            $average = $bucket['intervals'] > 0 ? intdiv($bucket['total_seconds'], $bucket['intervals']) : 0;

            $rows[(string) $slug] = [
                'name' => TaskStatus::label((string) $slug),
                'color' => 'gray',
                'completed' => false,
                'intervals' => $bucket['intervals'],
                'total_seconds' => $bucket['total_seconds'],
                'average_seconds' => $average,
                'average_label' => $this->formatDuration($average),
                'open' => $bucket['open'],
            ];
        }

        return $rows;
    }

    /**
     * Open, overdue and finished counts per person, plus the unassigned pile.
     *
     * Unassigned is a row of its own rather than a footnote. Work with nobody on
     * it is a real and common failure state, and burying it under the named
     * people is how it stays invisible.
     *
     * @return array<int, array{user: ?User, name: string, open: int, overdue: int, completed: int, total: int}>
     */
    public function assigneeLoad(): array
    {
        $completed = TaskStatus::completedSlugs();

        // Eager loaded rather than queried per card: assigneeLoad() is the only
        // report that needs them, so the load belongs here and not on the shared
        // liveTasks() query the other three use.
        $live = $this->liveTasks()->load('assignees');

        $rows = [];
        $unassigned = ['open' => 0, 'overdue' => 0, 'completed' => 0, 'total' => 0];

        // Takes and returns the bucket rather than mutating by reference: the
        // caller has to read $byUser[$id] first, and a by-reference parameter
        // cannot be fed an array-access expression like that.
        $bump = function (array $bucket, Task $task) use ($completed): array {
            $bucket['total']++;

            if (in_array((string) $task->status, $completed, true)) {
                $bucket['completed']++;

                return $bucket;
            }

            $bucket['open']++;

            if ($task->due_at !== null && $task->due_at->isPast()) {
                $bucket['overdue']++;
            }

            return $bucket;
        };

        $empty = ['open' => 0, 'overdue' => 0, 'completed' => 0, 'total' => 0];

        $unassigned = $empty;

        /** @var array<int, array{open:int,overdue:int,completed:int,total:int}> $byUser */
        $byUser = [];

        foreach ($live as $task) {
            // Always the loaded collection now. $task->assignees() would hand
            // back the relation, and a relation has no modelKeys() — which is a
            // BadMethodCallException at runtime rather than anything a test
            // catches early.
            $assigneeIds = $task->assignees->modelKeys();

            if ($assigneeIds === []) {
                $unassigned = $bump($unassigned, $task);

                continue;
            }

            // A card with three assignees counts once for each of them, so the
            // per-person open counts sum to more than the board total. That is
            // the honest reading of "load" — it is effort-weighted, not
            // card-weighted — and the board total is shown alongside for scale.
            foreach ($assigneeIds as $userId) {
                $byUser[(int) $userId] = $bump($byUser[(int) $userId] ?? $empty, $task);
            }
        }

        $users = User::query()
            ->whereIn('id', array_keys($byUser))
            ->get(['id', 'name'])
            ->keyBy('id');

        foreach ($byUser as $userId => $bucket) {
            $rows[] = [
                'user' => $users->get($userId),
                'name' => (string) ($users->get($userId)?->name ?? 'Unknown user'),
                ...$bucket,
            ];
        }

        if ($unassigned['total'] > 0) {
            $rows[] = [
                'user' => null,
                'name' => 'Unassigned',
                ...$unassigned,
            ];
        }

        // Busiest first; name as the tiebreak so the order is stable between
        // renders rather than depending on the database's row order.
        usort($rows, fn (array $a, array $b): int => [$b['open'], $b['total'], $a['name']]
            <=> [$a['open'], $a['total'], $b['name']]);

        return $rows;
    }

    /**
     * What these numbers do not cover.
     *
     * The activity log starts empty on an upgraded install and only fills in as
     * cards are touched, so a board with six months of work behind it can show a
     * fortnight of throughput and no obvious sign that anything is missing. A
     * report that cannot be wrong is not the claim being made here; this is the
     * receipt for what it saw.
     *
     * @return array{history_starts_at: ?CarbonInterface, cards_without_movements: int, live_cards: int}
     */
    public function coverage(): array
    {
        $movements = $this->movements();

        // Membership, not a count: a card that has been commented on but never
        // moved has an activity row and still no measurable column history, so
        // filtering on "has any activity" would quietly report it as covered.
        // Keyed so the check stays O(1) per card instead of scanning the
        // movement list once per card on the board.
        $movedTaskIds = $movements->keyBy('task_id');

        $withoutMovements = $this->liveTasks()
            ->reject(fn (Task $task): bool => $movedTaskIds->has($task->id))
            ->count();

        return [
            // Earliest *movement*, not earliest row of any type: the first
            // comment on a card is not the start of the timeline this report
            // measures, and dating the report from it would overstate coverage.
            'history_starts_at' => $movements->isEmpty()
                ? null
                : Carbon::parse($movements->min('created_at')),
            'cards_without_movements' => $withoutMovements,
            'live_cards' => $this->liveTasks()->count(),
        ];
    }

    /**
     * Live, non-deleted cards on this board.
     *
     * Every report in this class is about the board as it stands, so soft-deleted
     * cards are out of scope everywhere. Their history rows still exist and are
     * still correct — they are simply not part of a report on current health.
     * Going through the Task model rather than the table is what applies that
     * scope, and is why there is no withTrashed() anywhere in this file.
     *
     * @return Collection<int, Task>
     */
    private function liveTasks(): Collection
    {
        return $this->liveTasks ??= Task::query()
            ->where('board_id', $this->board->id)
            // No withCount('activities') here: a card can have comments and
            // never move, so "has activity rows" is not "has movements", and
            // coverage() needs the movement set rather than a per-card count.
            // Counting the wrong thing was a bug this file already had once.
            ->get(['id', 'status', 'due_at', 'created_at']);
    }

    /**
     * The column-change timeline, in one query, shared by three reports.
     *
     * @return Collection<int, object>
     */
    private function movements(): Collection
    {
        return $this->movements ??= TaskActivity::query()
            ->whereIn('task_id', $this->liveTasks()->modelKeys())
            ->whereIn('type', [TaskActivity::TYPE_CREATED, TaskActivity::TYPE_STATUS_CHANGED])
            // A custom date range bounds the history-based reports (throughput,
            // time-in-column, coverage) to a window without touching the current
            // snapshots (status counts, assignee load). The boundary is inclusive
            // on both ends: a move at midnight on `to` still counts. copy() so the
            // stored from/to are not mutated by startOfDay()/endOfDay().
            ->when($this->from !== null, fn ($q) => $q->where('created_at', '>=', $this->from->copy()->startOfDay()))
            ->when($this->to !== null, fn ($q) => $q->where('created_at', '<=', $this->to->copy()->endOfDay()))
            // id as the final tiebreak because created_at is only second
            // resolution. Two moves inside the same second are ordered by insert,
            // which is the truth, rather than left to the database to shuffle.
            ->orderBy('task_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['task_id', 'type', 'to_status', 'created_at']);
    }

    /**
     * Collapse rows into "entered this column at this moment" entries.
     *
     * @param  array<int, object>  $rows
     * @return array<int, array{column: string, at: CarbonInterface}>
     */
    private function entriesFor(array $rows): array
    {
        $entries = [];

        foreach ($rows as $row) {
            $column = (string) $row->to_status;

            // to_status is null for a status change row only if the row is
            // malformed. Skipping is better than inventing a column named "".
            if ($column === '') {
                continue;
            }

            $at = Carbon::parse($row->created_at);
            $lastIndex = count($entries) - 1;

            // Two entries for the same column at the same instant mean the card
            // moved and came straight back, or a row pair was written together.
            // Either way the interval between them is zero, so keeping only the
            // later one is equivalent and stops a double-count from inflating
            // the average.
            if ($lastIndex >= 0
                && $entries[$lastIndex]['column'] === $column
                && $entries[$lastIndex]['at']->equalTo($at)
            ) {
                $entries[$lastIndex]['at'] = $at;
            } else {
                $entries[] = ['column' => $column, 'at' => $at];
            }
        }

        return $entries;
    }

    /**
     * @return array<int, array{week_start: string, label: string, count: int, task_ids: array<int, int>}>
     */
    private function emptyWeekSeries(int $weeks): array
    {
        $series = [];

        // Built backwards from now so the newest bucket is the current week, then
        // reversed. Constructed this way rather than by offsetting a start date
        // so that "the last N weeks" cannot accidentally mean N weeks plus the
        // partial one we are standing in.
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $start = Carbon::now()->subWeeks($i)->startOfWeek();

            $series[] = [
                'week_start' => $start->toDateString(),
                'label' => $start->format('j M'),
                'count' => 0,
                'task_ids' => [],
            ];
        }

        return $series;
    }

    /**
     * Human duration for an average.
     *
     * Days and hours only: a report of averages does not need minutes, and
     * showing them invites reading precision into a number that is a mean over
     * however many cards happened to be involved.
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '—';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);

        if ($days === 0) {
            return $hours === 0 ? '<1h' : $hours.'h';
        }

        return $hours === 0 ? $days.'d' : $days.'d '.$hours.'h';
    }

    private const MAX_WEEKS = 52;

    private function assertWeeks(int $weeks): void
    {
        // Bounded because the series is rendered as a table of one row per week.
        // An unbounded value here is a page that cannot load, not a bigger report.
        if ($weeks < 1 || $weeks > self::MAX_WEEKS) {
            throw new \InvalidArgumentException('throughput() weeks must be between 1 and '.self::MAX_WEEKS.'.');
        }
    }
}
