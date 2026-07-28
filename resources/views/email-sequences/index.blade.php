<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Email Sequences') }}</h2>
            <a href="{{ route('email-sequences.create') }}"
               class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                {{ __('New Sequence') }}
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            @if($sequences->isEmpty())
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-12 text-center">
                    <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 9v.906a2.25 2.25 0 01-1.183 1.981l-6.478 3.488M2.25 9v.906a2.25 2.25 0 001.183 1.981l6.478 3.488m8.839 2.51l-4.66-2.51m0 0l-1.023-.55a2.25 2.25 0 00-2.134 0l-1.022.55m0 0l-4.661 2.51m16.5 1.615a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V8.844a2.25 2.25 0 011.183-1.98l7.5-4.04a2.25 2.25 0 012.134 0l7.5 4.04a2.25 2.25 0 011.183 1.98V19.5z"/>
                    </svg>
                    <p class="text-sm text-gray-500">{{ __('No email sequences yet.') }}</p>
                    <a href="{{ route('email-sequences.create') }}" class="mt-3 inline-flex items-center text-sm font-medium text-blue-600 hover:text-blue-800">
                        {{ __('Create your first sequence →') }}
                    </a>
                </div>
            @else
                <div class="space-y-4">
                    @foreach($sequences as $seq)
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 flex items-center justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <h3 class="font-semibold text-gray-900">{{ $seq->name }}</h3>
                                    <span class="text-xs px-2 py-0.5 rounded-full font-medium
                                        @switch($seq->status)
                                            @case('active') bg-green-100 text-green-800 @break
                                            @case('draft') bg-gray-100 text-gray-600 @break
                                            @case('paused') bg-amber-100 text-amber-800 @break
                                            @case('completed') bg-blue-100 text-blue-800 @break
                                            @default bg-gray-100 text-gray-600
                                        @endswitch">{{ __(ucfirst($seq->status)) }}</span>
                                </div>
                                <p class="text-sm text-gray-500 mt-1">
                                    {{ $seq->steps->count() }} {{ __('step(s)') }}
                                    &middot; {{ $seq->recipients_count ?? 0 }} {{ __('recipient(s)') }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                @if($seq->status === 'draft')
                                    <form method="POST" action="{{ route('email-sequences.launch', $seq) }}">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 text-xs font-medium bg-green-600 text-white rounded-lg hover:bg-green-700 transition">{{ __('Launch') }}</button>
                                    </form>
                                @elseif($seq->status === 'active')
                                    <form method="POST" action="{{ route('email-sequences.pause', $seq) }}">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 text-xs font-medium bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition">{{ __('Pause') }}</button>
                                    </form>
                                @elseif($seq->status === 'paused')
                                    <form method="POST" action="{{ route('email-sequences.launch', $seq) }}">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 text-xs font-medium bg-green-600 text-white rounded-lg hover:bg-green-700 transition">{{ __('Resume') }}</button>
                                    </form>
                                @endif
                                <a href="{{ route('email-sequences.edit', $seq) }}"
                                   class="px-3 py-1.5 text-xs font-medium bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">{{ __('Edit') }}</a>
                                @if(in_array($seq->status, ['draft', 'paused', 'completed', 'cancelled']))
                                    <form method="POST" action="{{ route('email-sequences.destroy', $seq) }}" data-confirm="{{ __('Delete this sequence?') }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="px-3 py-1.5 text-xs font-medium bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition">{{ __('Delete') }}</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
