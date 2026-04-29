<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Inbox') }}</h2>
            @can('conversations.view_all')
                <div class="flex gap-2 text-xs">
                    <a href="{{ route('conversations.index') }}"
                       class="px-3 py-1.5 rounded-full {{ ! $currentFilter ? 'bg-[#25D366] text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50' }}">
                        {{ __('All') }}
                    </a>
                    <a href="{{ route('conversations.index', ['filter' => 'unassigned']) }}"
                       class="px-3 py-1.5 rounded-full {{ $currentFilter === 'unassigned' ? 'bg-[#25D366] text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50' }}">
                        {{ __('Unassigned') }}
                    </a>
                </div>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm rounded-lg overflow-hidden">
                @forelse($conversations as $conversation)
                    <a href="{{ route('conversations.show', $conversation) }}"
                       class="flex items-center gap-4 px-6 py-4 border-b border-gray-100 hover:bg-gray-50 transition">
                        {{-- Avatar --}}
                        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-700 font-semibold uppercase">
                            {{ Str::substr($conversation->contact->name ?? $conversation->contact->phone, 0, 2) }}
                        </div>

                        {{-- Body --}}
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold text-gray-900 truncate">
                                    {{ $conversation->contact->name ?? $conversation->contact->phone }}
                                </h3>
                                <span class="text-xs text-gray-400 flex-shrink-0">
                                    {{ $conversation->last_message_at?->diffForHumans(short: true) ?? '—' }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between gap-2 mt-1">
                                <p class="text-xs text-gray-500 truncate">
                                    via {{ $conversation->whatsappInstance->display_name ?? $conversation->whatsappInstance->instance_name }}
                                    @if($conversation->assignedTo)
                                        · {{ __('Assigned to') }} <span class="font-medium">{{ $conversation->assignedTo->name }}</span>
                                    @elseif(auth()->user()->can('conversations.view_all'))
                                        · <span class="text-amber-600">{{ __('Unassigned') }}</span>
                                    @endif
                                </p>
                                @if($conversation->unread_count > 0)
                                    <span class="flex-shrink-0 inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full bg-[#25D366] text-white text-xs font-semibold">
                                        {{ $conversation->unread_count }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="p-12 text-center text-gray-400">
                        <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.068.157 2.148.279 3.238.364.466.037.893.281 1.153.671L12 21l2.652-3.978c.26-.39.687-.634 1.153-.67 1.09-.086 2.17-.208 3.238-.365 1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/>
                        </svg>
                        <p class="font-medium">{{ __('No conversations yet') }}</p>
                        <p class="text-sm mt-1">{{ __('Inbound replies from your contacts will appear here.') }}</p>
                    </div>
                @endforelse

                @if($conversations->hasPages())
                    <div class="px-6 py-3 border-t border-gray-100">{{ $conversations->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
