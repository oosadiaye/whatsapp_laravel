{{--
    The signed-in person's Google Calendar connection: status plus Connect / Sync now / Test / Disconnect.

    Needs: $return ('meetings' | 'settings') — where to come back to afterwards.
    The POST buttons point at hidden forms rendered once per page by meetings/_google-forms (a <form> cannot nest
    inside the Settings form), so they use the HTML5 form= attribute.
--}}
@php
    $gcConfigured = \App\Services\Google\GoogleConfig::isConfigured();
    $gcConnection = \App\Models\GoogleConnection::query()->where('user_id', auth()->id())->first();
    $gcNeedsReauth = $gcConnection && ! $gcConnection->isUsable();
@endphp

<div x-data class="space-y-3">
    @if(! $gcConfigured)
        <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-700">
            Google Calendar is not set up yet.
            @if($return === 'settings')
                Enter the Client ID and Client secret above, press <strong>Save Settings</strong>, then come back here to connect.
            @else
                An administrator needs to enter the Google Client ID and secret in <strong>Settings &rarr; Google Calendar</strong>.
            @endif
        </p>
    @elseif($gcConnection === null)
        <p class="text-sm text-gray-700">Connect your Google account to create Google Meet meetings from BlastIQ and to see your Google Calendar here.</p>
        <a href="{{ route('google.connect', ['return' => $return]) }}"
           class="inline-flex items-center gap-2 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-ink hover:bg-brand-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
            Connect Google Calendar
        </a>
    @else
        <div class="flex flex-wrap items-center gap-2">
            @if($gcNeedsReauth)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700">
                    <span class="h-2 w-2 rounded-full bg-amber-500" aria-hidden="true"></span> Needs reconnecting
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-700">
                    <span class="h-2 w-2 rounded-full bg-green-500" aria-hidden="true"></span> Connected
                </span>
            @endif
            <span class="text-sm text-gray-900">{{ $gcConnection->google_email }}</span>
        </div>

        <p class="text-xs text-gray-500">
            @if($gcConnection->last_synced_at)
                Last synced {{ $gcConnection->last_synced_at->diffForHumans() }} &middot; {{ $gcConnection->last_import_count }} {{ \Illuminate\Support\Str::plural('event', $gcConnection->last_import_count) }} from Google on your calendar.
            @else
                Not synced yet.
            @endif
            It refreshes by itself every 10 minutes.
        </p>

        @if($gcConnection->last_error)
            <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ $gcConnection->last_error }}</p>
        @endif

        <div class="flex flex-wrap items-center gap-2">
            @if($gcNeedsReauth)
                <a href="{{ route('google.connect', ['return' => $return]) }}"
                   class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-brand-ink hover:bg-brand-hover">Reconnect</a>
            @else
                <button type="submit" form="gcal-sync-{{ $return }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Sync now</button>
            @endif
            <button type="submit" form="gcal-test-{{ $return }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Test connection</button>
            <button type="button"
                    @click.prevent="if (confirm('Disconnect Google Calendar? Meetings imported from Google will be removed from this calendar. Meetings you made in BlastIQ stay.')) document.getElementById('gcal-disconnect-{{ $return }}').submit()"
                    class="rounded-lg border border-red-300 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50">Disconnect</button>
        </div>
    @endif
</div>
