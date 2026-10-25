@php
    use App\Services\UserSessionMonitor;
@endphp

<div wire:poll.10s class="space-y-5">

    <div class="flex flex-wrap items-center gap-3">
        <div class="bg-white border border-gray-200 rounded-xl shadow-sm px-4 py-3">
            <div class="text-2xl font-bold {{ $onlineCount > 0 ? 'text-emerald-600' : 'text-gray-900' }}">{{ $onlineCount }}</div>
            <div class="text-[11px] uppercase tracking-wide text-gray-500">{{ __('Online now') }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-xl shadow-sm px-4 py-3">
            <div class="text-2xl font-bold text-gray-900">{{ $people->count() }}</div>
            <div class="text-[11px] uppercase tracking-wide text-gray-500">{{ __('Signed-in users') }}</div>
        </div>

        @if ($canManage && ($people->count() > 1 || ($people->first() && $people->first()['sessions']->count() > 1)))
            <button type="button"
                    wire:click="logoutEveryoneElse"
                    wire:confirm="{{ __('Sign out every other signed-in user? Your own session stays signed in.') }}"
                    class="ml-auto px-4 py-2 rounded-lg border border-red-200 bg-red-50 text-red-700 text-sm font-semibold hover:bg-red-100">
                {{ __('Log out everyone else') }}
            </button>
        @endif
    </div>

    @if ($notice)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">{{ $notice }}</div>
    @endif

    <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100 flex items-center gap-2">
            <h3 class="text-sm font-bold text-gray-700">{{ __('Signed-in users') }}</h3>
            <span class="ml-auto text-xs text-gray-500">{{ __('Updates every 10 seconds') }}</span>
        </div>

        <div class="divide-y divide-gray-100">
            @forelse ($people as $person)
                @php
                    $u = $person['user'];
                    $isMe = $u->is($actor);
                    $manageable = $canManage && (! $u->hasRole(\App\Models\User::ROLE_SUPER_ADMIN) || $actor->hasRole(\App\Models\User::ROLE_SUPER_ADMIN));
                @endphp
                <div class="px-5 py-4" wire:key="person-{{ $u->id }}">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="w-2.5 h-2.5 rounded-full shrink-0 {{ $person['state'] === 'online' ? 'bg-emerald-500' : 'bg-amber-400' }}"
                              title="{{ $person['state'] === 'online' ? __('Active in the last 2 minutes') : __('Signed in but quiet') }}"></span>
                        <div class="min-w-0">
                            <div class="font-semibold text-sm text-gray-900 truncate">
                                {{ $u->name }}
                                @if ($isMe)<span class="ml-1 text-[11px] font-medium text-gray-500">({{ __('you') }})</span>@endif
                            </div>
                            <div class="text-xs text-gray-500">{{ $u->email }} · {{ str_replace('_', ' ', $u->role) }}</div>
                        </div>
                        <span class="text-xs text-gray-500">
                            {{ $person['state'] === 'online' ? __('Online') : __('Idle') }}
                            · {{ $person['last_active_at']?->diffForHumans() }}
                        </span>
                        @if ($person['sessions']->count() > 1)
                            <span class="px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 text-[11px] font-semibold"
                                  title="{{ __('Several tabs or devices. For calls this can split registration — close the extras.') }}">
                                {{ __(':n sessions', ['n' => $person['sessions']->count()]) }}
                            </span>
                        @endif

                        @if ($manageable)
                            <button type="button"
                                    wire:click="logoutUser({{ $u->id }})"
                                    wire:confirm="{{ $isMe ? __('Sign yourself out of your other sessions?') : __('Sign :name out of every device?', ['name' => $u->name]) }}"
                                    class="ml-auto px-3 py-1.5 rounded-lg border border-red-200 text-red-700 text-xs font-semibold hover:bg-red-50">
                                {{ $isMe ? __('Log out my other sessions') : __('Log out everywhere') }}
                            </button>
                        @endif
                    </div>

                    <ul class="mt-3 space-y-1.5">
                        @foreach ($person['sessions'] as $session)
                            @php $mine = $session->session_id === $currentSessionId; @endphp
                            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600" wire:key="session-{{ $session->id }}">
                                <span class="font-medium text-gray-800">{{ UserSessionMonitor::describeAgent($session->user_agent) }}</span>
                                <span class="font-mono text-gray-500">{{ $session->ip_address ?? '—' }}</span>
                                <span class="text-gray-500">{{ __('active') }} {{ $session->last_active_at?->diffForHumans() }}</span>
                                @if ($mine)
                                    <span class="px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-700 font-semibold">{{ __('this device') }}</span>
                                @elseif ($manageable)
                                    <button type="button"
                                            wire:click="logoutSession({{ $session->id }})"
                                            wire:confirm="{{ __('Sign this device out?') }}"
                                            class="ml-auto text-red-600 font-semibold hover:underline">{{ __('Log out') }}</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <div class="px-5 py-12 text-center text-sm text-gray-500">
                    {{ __('Nobody is signed in right now.') }}
                </div>
            @endforelse
        </div>
    </div>

    <p class="text-xs text-gray-500">
        {{ __('A signed-out person is returned to the login screen on their next click or within about 15 seconds. Sessions appear here once the person next uses the app after this feature was switched on.') }}
    </p>
</div>
