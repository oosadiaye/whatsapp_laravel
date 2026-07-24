<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Email Templates') }}</h2>
            @can('email.create')
                <a href="{{ route('email-templates.create') }}"
                   class="inline-flex items-center gap-2 px-4 py-2 bg-[#4f46e5] text-white rounded-lg text-sm font-semibold hover:bg-[#4338ca] transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    {{ __('New template') }}
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-6 max-w-6xl mx-auto sm:px-6 lg:px-8">
        <p class="text-sm text-gray-500 mb-6">
            {{ __('Reusable email designs your team builds and owns. (WhatsApp message templates are separate — those are synced from Meta under') }}
            <a href="{{ route('templates.index') }}" class="text-indigo-600 hover:underline">{{ __('Templates') }}</a>.)
        </p>

        {{-- Team-authored templates --}}
        <div class="mb-10">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">{{ __('Your templates') }}</h3>
            @if($templates->isEmpty())
                <div class="rounded-xl border border-dashed border-gray-300 bg-white px-5 py-12 text-center">
                    <p class="text-sm text-gray-500">{{ __('No saved templates yet.') }}</p>
                    @can('email.create')
                        <p class="text-xs text-gray-400 mt-1">{{ __('Create one below from a starter design, or from scratch.') }}</p>
                    @endcan
                </div>
            @else
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($templates as $tpl)
                        <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden flex flex-col">
                            <iframe sandbox srcdoc="{{ $tpl->body_html }}" title="{{ $tpl->name }}"
                                    class="w-full h-40 bg-white border-b border-gray-100 pointer-events-none" scrolling="no"></iframe>
                            <div class="p-4 flex-1 flex flex-col">
                                <div class="font-semibold text-sm text-gray-900 truncate">{{ $tpl->name }}</div>
                                <div class="text-xs text-gray-400 truncate">{{ $tpl->subject ?: __('No default subject') }}</div>
                                <div class="text-[11px] text-gray-400 mt-1">{{ __('by') }} {{ $tpl->creator?->name ?? __('—') }} · {{ $tpl->updated_at?->diffForHumans() }}</div>
                                <div class="mt-3 flex items-center gap-3 text-xs">
                                    @can('email.create')
                                        <a href="{{ route('email-campaigns.create', ['email_template' => $tpl->id]) }}" class="font-semibold text-[#4f46e5] hover:underline">{{ __('Use in campaign') }}</a>
                                    @endcan
                                    @can('email.edit')
                                        <a href="{{ route('email-templates.edit', $tpl) }}" class="text-gray-500 hover:text-gray-800">{{ __('Edit') }}</a>
                                    @endcan
                                    @can('email.delete')
                                        <form method="POST" action="{{ route('email-templates.destroy', $tpl) }}" data-confirm="{{ __('Delete this template?') }}" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:text-red-800">{{ __('Delete') }}</button>
                                        </form>
                                    @endcan
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Starter designs to build from --}}
        @can('email.create')
            @if(! empty($starters ?? []))
                <div>
                    <h3 class="text-sm font-semibold text-gray-700 mb-1">{{ __('Start from a beautiful design') }}</h3>
                    <p class="text-xs text-gray-400 mb-3">{{ __('Open the editor pre-filled, then save it as your own template.') }}</p>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                        @foreach($starters as $s)
                            <a href="{{ route('email-templates.create', ['starter' => $s['key']]) }}"
                               class="group rounded-xl border border-gray-200 bg-white overflow-hidden hover:border-gray-300 hover:shadow-md transition">
                                <span class="block h-16 relative" style="background: {{ $s['accent'] }}">
                                    <span class="absolute inset-0 grid place-items-center text-white/90 text-2xl font-black tracking-tight">{{ mb_substr($s['name'], 0, 1) }}</span>
                                </span>
                                <span class="block px-3 py-2.5">
                                    <span class="block text-sm font-semibold text-gray-800">{{ $s['name'] }}</span>
                                    <span class="block text-[11px] text-gray-400 mt-0.5 leading-snug">{{ $s['description'] }}</span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        @endcan
    </div>
</x-app-layout>
