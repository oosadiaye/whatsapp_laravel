<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    {{ $board->name }} — report
                </h2>
                <p class="mt-0.5 text-sm text-gray-500">
                    <a href="{{ route('boards.show', $board) }}" class="text-indigo-600 hover:underline">Back to board</a>
                </p>
            </div>

            {{-- Weeks is a link, not a form: the window is the only control on
                 this page, and a GET link is shareable and survives a refresh
                 without a CSRF token or a POST to read a page. --}}
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label for="from" class="text-sm text-gray-600">From</label>
                <input type="date" id="from" name="from" value="{{ $from }}"
                       class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <label for="to" class="text-sm text-gray-600">To</label>
                <input type="date" id="to" name="to" value="{{ $to }}"
                       class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <label for="weeks" class="text-sm text-gray-600">or last</label>
                <select id="weeks" name="weeks" onchange="this.form.submit()"
                        class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @foreach ([4, 8, 12, 26] as $option)
                        <option value="{{ $option }}" @selected($weeks === $option)>{{ $option }} weeks</option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="rounded-md bg-gray-900 px-3 py-1.5 text-sm text-white">Go</button></noscript>
            </form>

            {{-- A plain link, not a button: the export is a GET that returns a
                 file, and a link is shareable and carries no CSRF expectation. --}}
            <a href="{{ route('boards.report.export', ['board' => $board, 'weeks' => $weeks, 'from' => $from, 'to' => $to]) }}"
               class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Export CSV
            </a>
        </div>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">

        {{-- The honesty banner. An upgraded board has months of work and a log
             that only started filling in recently; without this the tables below
             read as complete. --}}
        @if ($coverage['cards_without_movements'] > 0 || $coverage['history_starts_at'] === null)
            <div class="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                <p class="font-medium">These numbers cover less than the board does.</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5">
                    @if ($coverage['cards_without_movements'] > 0)
                        <li>
                            {{ $coverage['cards_without_movements'] }}
                            of {{ $coverage['live_cards'] }}
                            {{ Str::plural('card', $coverage['live_cards']) }}
                            {{ $coverage['cards_without_movements'] === 1 ? 'has' : 'have' }}
                            no recorded column moves, and are left out of time-in-column.
                        </li>
                    @endif
                    @if ($coverage['history_starts_at'] === null)
                        <li>No card movements have been recorded yet, so throughput and time-in-column are empty.</li>
                    @else
                        <li>Movement history starts {{ $coverage['history_starts_at']->format('j M Y') }}.</li>
                    @endif
                </ul>
            </div>
        @endif

        {{-- Status counts --}}
        <section>
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Cards per column</h3>
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                @foreach ($statusCounts as $slug => $column)
                    <div @class([
                        'rounded-lg border bg-white p-4 shadow-sm',
                        'border-amber-300 ring-1 ring-amber-200' => $column['over'],
                        'border-gray-200' => ! $column['over'],
                    ])>
                        <p class="truncate text-sm text-gray-500" title="{{ $column['name'] }}">{{ $column['name'] }}</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900">
                            {{ $column['count'] }}
                            @if ($column['limit'] !== null)
                                <span @class([
                                    'text-sm font-medium tabular-nums',
                                    'text-amber-700' => $column['over'],
                                    'text-gray-400' => ! $column['over'],
                                ])>/{{ $column['limit'] }}</span>
                            @endif
                        </p>
                        @if ($column['over'])
                            <p class="mt-1 text-xs font-medium text-amber-700">
                                {{ $column['count'] - $column['limit'] }} over limit
                            </p>
                        @endif
                        @unless ($column['known'])
                            <p class="mt-1 text-xs font-medium text-amber-700">column no longer exists</p>
                        @endunless
                    </div>
                @endforeach
            </div>
        </section>

        <div class="grid gap-8 lg:grid-cols-2">

            {{-- Throughput --}}
            <section>
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Finished per week</h3>
                <p class="mt-1 text-xs text-gray-400">
                    Cards that reached a completed column. Counted once per week even if finished twice.
                    @if ($from && $to)
                        Bounded to {{ $from }}–{{ $to }}; time-in-column and coverage use the same window.
                    @endif
                </p>
                <table class="mt-3 w-full text-sm">
                    <tbody>
                        @forelse ($throughput as $week)
                            <tr class="border-b border-gray-100 last:border-0">
                                <td class="py-1.5 pr-3 text-gray-600">{{ $week['label'] }}</td>
                                <td class="py-1.5 text-right">
                                    <span @class([
                                        'font-semibold text-gray-900',
                                        'text-gray-300' => $week['count'] === 0,
                                    ])>{{ $week['count'] }}</span>
                                </td>
                                <td class="w-1/2 py-1.5 pl-3">
                                    {{-- Proportional bar against the busiest week, so the
                                         shape is readable without a chart library. --}}
                                    @php $peak = max(array_column($throughput, 'count') ?: [0]); @endphp
                                    <div class="h-2 rounded bg-gray-100">
                                        <div class="h-2 rounded bg-emerald-500"
                                             style="width: {{ $peak > 0 ? round($week['count'] / $peak * 100) : 0 }}%"></div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="py-2 text-gray-500">Nothing finished in this window.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>

            {{-- Time in column --}}
            <section>
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Average time in column</h3>
                <p class="mt-1 text-xs text-gray-400">
                    Includes cards still in the column, measured up to now.
                </p>
                <table class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-400">
                            <th class="py-1.5 pr-3 font-medium">Column</th>
                            <th class="py-1.5 pr-3 text-right font-medium">Average</th>
                            <th class="py-1.5 pr-3 text-right font-medium">Cards</th>
                            <th class="py-1.5 text-right font-medium">Still there</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($timeInColumn as $slug => $column)
                            <tr class="border-b border-gray-100 last:border-0">
                                <td class="py-1.5 pr-3 text-gray-700">{{ $column['name'] }}</td>
                                <td class="py-1.5 pr-3 text-right font-medium text-gray-900">{{ $column['average_label'] }}</td>
                                <td class="py-1.5 pr-3 text-right text-gray-500">{{ $column['intervals'] }}</td>
                                <td class="py-1.5 text-right">
                                    @if ($column['open'] > 0)
                                        <span class="text-gray-500" title="Counted up to now rather than to a real departure">
                                            {{ $column['open'] }}
                                        </span>
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td class="py-2 text-gray-500">No columns to report on.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        </div>

        {{-- Assignee load --}}
        <section>
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Load by assignee</h3>
            <p class="mt-1 text-xs text-gray-400">
                A card with several assignees counts for each of them, so these rows sum to more than
                the board's card count.
            </p>
            <table class="mt-3 w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-400">
                        <th class="py-1.5 pr-3 font-medium">Person</th>
                        <th class="py-1.5 pr-3 text-right font-medium">Open</th>
                        <th class="py-1.5 pr-3 text-right font-medium">Overdue</th>
                        <th class="py-1.5 pr-3 text-right font-medium">Finished</th>
                        <th class="py-1.5 text-right font-medium">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($assigneeLoad as $row)
                        <tr class="border-b border-gray-100 last:border-0">
                            <td class="py-1.5 pr-3 text-gray-700">
                                {{ $row['name'] }}
                                @unless ($row['user'])
                                    <span class="ml-1 text-xs text-amber-700">nobody is on these</span>
                                @endunless
                            </td>
                            <td class="py-1.5 pr-3 text-right font-medium text-gray-900">{{ $row['open'] }}</td>
                            <td class="py-1.5 pr-3 text-right">
                                @if ($row['overdue'] > 0)
                                    <span class="font-medium text-red-600">{{ $row['overdue'] }}</span>
                                @else
                                    <span class="text-gray-300">0</span>
                                @endif
                            </td>
                            <td class="py-1.5 pr-3 text-right text-gray-500">{{ $row['completed'] }}</td>
                            <td class="py-1.5 text-right text-gray-500">{{ $row['total'] }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-2 text-gray-500">No cards on this board yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
</x-app-layout>
