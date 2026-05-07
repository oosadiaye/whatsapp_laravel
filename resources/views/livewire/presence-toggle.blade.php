<div x-data="{ open: false }" class="relative mb-2">
    {{-- Trigger: colored dot + status label + chevron --}}
    <button type="button"
            @click="open = !open"
            @click.outside="open = false"
            class="w-full flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-50 transition text-sm"
            @if($setAt)
                title="Set {{ $setAt->diffForHumans() }}"
            @endif>
        <span class="flex-shrink-0 w-2.5 h-2.5 rounded-full
            @if($status === \App\Models\User::PRESENCE_AVAILABLE) bg-green-500
            @elseif($status === \App\Models\User::PRESENCE_BUSY) bg-orange-500
            @else bg-gray-400 @endif"></span>
        <span class="flex-1 text-left text-gray-700 capitalize">
            @if($status === \App\Models\User::PRESENCE_AVAILABLE) Available
            @elseif($status === \App\Models\User::PRESENCE_BUSY) Busy
            @else Away @endif
        </span>
        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"
             :class="open ? 'rotate-180' : ''">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
        </svg>
    </button>

    {{-- Dropdown menu --}}
    <div x-show="open" x-cloak x-transition
         class="absolute bottom-full left-0 right-0 mb-1 bg-white border border-gray-200 rounded-lg shadow-lg overflow-hidden z-10">
        <button type="button"
                wire:click="setStatus('{{ \App\Models\User::PRESENCE_AVAILABLE }}')"
                @click="open = false"
                class="w-full flex items-center gap-2 px-3 py-2 hover:bg-gray-50 text-sm text-left">
            <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
            <span class="text-gray-700">Available</span>
        </button>
        <button type="button"
                wire:click="setStatus('{{ \App\Models\User::PRESENCE_BUSY }}')"
                @click="open = false"
                class="w-full flex items-center gap-2 px-3 py-2 hover:bg-gray-50 text-sm text-left">
            <span class="w-2.5 h-2.5 rounded-full bg-orange-500"></span>
            <span class="text-gray-700">Busy</span>
        </button>
        <button type="button"
                wire:click="setStatus('{{ \App\Models\User::PRESENCE_AWAY }}')"
                @click="open = false"
                class="w-full flex items-center gap-2 px-3 py-2 hover:bg-gray-50 text-sm text-left">
            <span class="w-2.5 h-2.5 rounded-full bg-gray-400"></span>
            <span class="text-gray-700">Away</span>
        </button>
    </div>
</div>
