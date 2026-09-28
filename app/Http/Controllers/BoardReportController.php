<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Board;
use App\Support\BoardReport;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The board's numbers.
 *
 * Deliberately a plain controller and a plain Blade view, not a Livewire
 * component. Nothing on this page is interactive, so a component would add a
 * round trip and a re-render path to a screen that only ever reads.
 */
class BoardReportController extends Controller
{
    /**
     * Weeks of throughput to show. Read from the query string so the window is
     * linkable and shareable, and clamped rather than trusted.
     */
    private const DEFAULT_WEEKS = 8;

    private const MAX_WEEKS = 26;

    public function show(Request $request, Board $board): View
    {
        $weeks = $this->weeks($request);
        $range = $this->resolveRange($request);

        $report = new BoardReport($board, $range['from'], $range['to']);

        $throughput = $range['from'] !== null && $range['to'] !== null
            ? $report->throughputRange($range['from'], $range['to'])
            : $report->throughput($weeks);

        return view('boards.report', [
            'board' => $board,
            'statusCounts' => $report->statusCounts(),
            'throughput' => $throughput,
            'timeInColumn' => $report->timeInColumn(),
            'assigneeLoad' => $report->assigneeLoad(),
            'coverage' => $report->coverage(),
            'weeks' => $weeks,
            'from' => $range['from']?->toDateString(),
            'to' => $range['to']?->toDateString(),
        ]);
    }

    /**
     * The same four reports as a CSV download.
     *
     * CSV rather than a richer format on purpose: the data is four flat tables,
     * the consumer is almost always a spreadsheet, and anything that tried to
     * preserve the page's styling would be harder to diff and harder to import.
     * The `weeks` window is honoured so the exported throughput matches the
     * on-screen one. The structure — section title, header row, rows, blank line —
     * is plain enough that a downstream job can find each section by its title.
     */
    public function export(Request $request, Board $board): StreamedResponse
    {
        $weeks = $this->weeks($request);
        $range = $this->resolveRange($request);

        $report = new BoardReport($board, $range['from'], $range['to']);

        $statusCounts = $report->statusCounts();
        $throughput = $range['from'] !== null && $range['to'] !== null
            ? $report->throughputRange($range['from'], $range['to'])
            : $report->throughput($weeks);
        $timeInColumn = $report->timeInColumn();
        $assigneeLoad = $report->assigneeLoad();
        $coverage = $report->coverage();

        $filename = 'board-'.$board->slug.'-report.csv';

        return response()->streamDownload(function () use ($statusCounts, $throughput, $timeInColumn, $assigneeLoad, $coverage) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Cards per column']);
            fputcsv($out, ['Column', 'Cards', 'Limit', 'Over']);
            foreach ($statusCounts as $row) {
                fputcsv($out, [
                    // Column names are user-set (TaskStatusController, no char limit)
                    // — neutralize spreadsheet formula/DDE injection on open.
                    Csv::safe($row['name']),
                    $row['count'],
                    $row['limit'] ?? '',
                    $row['over'] ? 'yes' : 'no',
                ]);
            }

            fputcsv($out, []);
            fputcsv($out, ['Finished per week']);
            fputcsv($out, ['Week', 'Finished']);
            foreach ($throughput as $row) {
                fputcsv($out, [$row['label'], $row['count']]);
            }

            fputcsv($out, []);
            fputcsv($out, ['Average time in column']);
            fputcsv($out, ['Column', 'Average', 'Open intervals']);
            foreach ($timeInColumn as $row) {
                fputcsv($out, [Csv::safe($row['name']), $row['average_label'], $row['open']]);
            }

            fputcsv($out, []);
            fputcsv($out, ['Load by assignee']);
            fputcsv($out, ['Person', 'Open', 'Overdue', 'Finished']);
            foreach ($assigneeLoad as $row) {
                // Assignee name is the user's own profile display name (attacker-set).
                fputcsv($out, [Csv::safe($row['name']), $row['open'], $row['overdue'], $row['completed']]);
            }

            fputcsv($out, []);
            fputcsv($out, ['Coverage']);
            fputcsv($out, ['Live cards', $coverage['live_cards']]);
            fputcsv($out, ['Cards with no recorded moves', $coverage['cards_without_movements']]);
            fputcsv($out, [
                'History starts',
                $coverage['history_starts_at'] instanceof \DateTimeInterface
                    ? $coverage['history_starts_at']->toDateString()
                    : 'no history',
            ]);

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function weeks(Request $request): int
    {
        $requested = $request->integer('weeks', self::DEFAULT_WEEKS);

        // Clamped rather than trusted: the series renders one row per week, so
        // an unbounded value from the query string is a page that cannot load.
        // Anything below 1 falls back to the default rather than being clamped up
        // to 1, because "weeks=0" reads as a mistake worth correcting silently
        // rather than a request for one week.
        if ($requested < 1) {
            return self::DEFAULT_WEEKS;
        }

        return min($requested, self::MAX_WEEKS);
    }

    /**
     * Resolve an optional `from`/`to` date window from the query string.
     *
     * Either side may be omitted: a lone `from` runs to today, a lone `to` runs
     * back DEFAULT_WEEKS, and a malformed date is dropped (the report falls back
     * to the weeks window) rather than 500-ing. The two are ordered so the
     * earlier date is always `from`.
     *
     * @return array{from: ?Carbon, to: ?Carbon}
     */
    private function resolveRange(Request $request): array
    {
        $from = $this->parseDate($request->query('from'));
        $to = $this->parseDate($request->query('to'));

        if ($from === null && $to === null) {
            return ['from' => null, 'to' => null];
        }

        if ($from !== null && $to === null) {
            $to = Carbon::today();
        } elseif ($from === null && $to !== null) {
            $from = Carbon::today()->subWeeks(self::DEFAULT_WEEKS);
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return ['from' => $from, 'to' => $to];
    }

    private function parseDate(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        // Carbon 3 throws on a format mismatch rather than returning false, so a
        // hand-typed "not-a-date" in the query string must fall back to the
        // weeks window, not 500 the report.
        try {
            return Carbon::createFromFormat('Y-m-d', $raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
