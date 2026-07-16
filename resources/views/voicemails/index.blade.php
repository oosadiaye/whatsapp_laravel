<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Voicemail') }}</h2>
            @if($unheardCount > 0)
                <span class="inline-flex items-center justify-center min-w-[1.5rem] h-6 px-2 rounded-full bg-red-600 text-white text-xs font-bold">{{ $unheardCount }}</span>
            @endif
        </div>
    </x-slot>

    <div class="py-6 max-w-4xl mx-auto sm:px-6 lg:px-8">
        @if(session('success'))
            <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif

        <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
            <div class="divide-y divide-gray-50">
                @forelse($voicemails as $vm)
                    <div class="flex items-center gap-4 px-5 py-4 {{ $vm->is_heard ? '' : 'bg-amber-50/40' }}">
                        <span class="grid place-items-center w-10 h-10 rounded-full shrink-0 {{ $vm->is_heard ? 'bg-gray-100 text-gray-400' : 'bg-amber-100 text-amber-700' }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z"/></svg>
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-semibold text-sm text-gray-900 truncate">{{ $vm->contact?->name ?? $vm->from_phone ?? __('Unknown caller') }}</span>
                                @unless($vm->is_heard)<span class="text-[10px] font-bold uppercase text-red-600">{{ __('New') }}</span>@endunless
                            </div>
                            <p class="text-xs text-gray-400">
                                {{ $vm->from_phone }} · {{ $vm->created_at?->diffForHumans() }}
                                @if($vm->duration_seconds) · {{ sprintf('%d:%02d', intdiv($vm->duration_seconds, 60), $vm->duration_seconds % 60) }} @endif
                                @if($vm->is_heard && $vm->heardBy) · {{ __('heard by') }} {{ $vm->heardBy->name }} @endif
                            </p>
                        </div>
                        @if($vm->recording_url)
                            <audio controls preload="none" class="h-9 max-w-[220px]" src="{{ route('voicemails.download', $vm) }}"></audio>
                        @endif
                        @unless($vm->is_heard)
                            <form method="POST" action="{{ route('voicemails.markHeard', $vm) }}">
                                @csrf
                                <button type="submit" class="text-xs font-semibold text-[#128C7E] hover:underline whitespace-nowrap">{{ __('Mark heard') }}</button>
                            </form>
                        @endunless
                    </div>
                @empty
                    <div class="px-5 py-16 text-center text-sm text-gray-400">
                        {{ __('No voicemails. Messages left by inbound callers appear here.') }}
                    </div>
                @endforelse
            </div>
            @if($voicemails->hasPages())
                <div class="px-5 py-4 border-t border-gray-100">{{ $voicemails->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
