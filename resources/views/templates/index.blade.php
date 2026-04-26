<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Message Templates') }}
            </h2>
            <div class="flex items-center gap-2" x-data="{ syncOpen: false }">
                {{-- Sync from WhatsApp --}}
                <div class="relative">
                    <button type="button"
                            @click="syncOpen = !syncOpen"
                            @click.outside="syncOpen = false"
                            class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-[#25D366] focus:ring-offset-2 transition ease-in-out duration-150"
                            @if($instances->isEmpty()) disabled title="{{ __('Connect a WhatsApp instance first') }}" @endif>
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        {{ __('Sync from WhatsApp') }}
                    </button>

                    @if($instances->isNotEmpty())
                        <div x-show="syncOpen"
                             x-transition
                             class="absolute right-0 mt-2 w-72 bg-white border border-gray-200 rounded-lg shadow-lg z-20 p-3"
                             style="display: none;">
                            <p class="text-xs text-gray-500 mb-2">{{ __('Pick an instance to fetch its approved templates from Meta:') }}</p>
                            @foreach($instances as $inst)
                                <form method="POST" action="{{ route('templates.sync') }}" class="mb-1 last:mb-0">
                                    @csrf
                                    <input type="hidden" name="whatsapp_instance_id" value="{{ $inst->id }}">
                                    <button type="submit"
                                            class="w-full text-left px-3 py-2 rounded hover:bg-gray-50 flex items-center justify-between">
                                        <span class="text-sm text-gray-800">{{ $inst->instance_name }}</span>
                                        <span class="text-xs text-gray-400">{{ $inst->phone_number ?? $inst->status }}</span>
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    @endif
                </div>

                <a href="{{ route('templates.create') }}"
                   class="inline-flex items-center px-4 py-2 bg-[#25D366] border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-[#1da851] focus:outline-none focus:ring-2 focus:ring-[#25D366] focus:ring-offset-2 transition ease-in-out duration-150">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    {{ __('Create Template') }}
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-12" x-data="{ previewOpen: false, previewTemplate: null }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @if($templates->isEmpty())
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-12 text-center">
                        <svg class="w-12 h-12 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/>
                        </svg>
                        <p class="text-gray-500 text-sm">{{ __('No templates yet. Create your first message template to get started.') }}</p>
                    </div>
                </div>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($templates as $template)
                        <div class="bg-white rounded-lg shadow-sm border border-gray-200 hover:shadow-md transition-shadow duration-200 flex flex-col">
                            <div class="p-5 flex-1">
                                {{-- Header --}}
                                <div class="flex items-start justify-between mb-3">
                                    <h3 class="text-base font-semibold text-gray-900 truncate pr-2">
                                        {{ $template->name }}
                                    </h3>
                                    @if($template->media_path)
                                        <span class="flex-shrink-0 text-gray-400" title="{{ __('Has media') }}">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                                            </svg>
                                        </span>
                                    @endif
                                </div>

                                {{-- Status + Category badges --}}
                                <div class="mb-3 flex flex-wrap items-center gap-2">
                                    @if($template->isRemote())
                                        @php
                                            $statusClass = match($template->status) {
                                                'APPROVED' => 'bg-emerald-100 text-emerald-800',
                                                'PENDING' => 'bg-amber-100 text-amber-800',
                                                'REJECTED' => 'bg-red-100 text-red-800',
                                                default => 'bg-gray-100 text-gray-700',
                                            };
                                        @endphp
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide {{ $statusClass }}">
                                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2a10 10 0 100 20 10 10 0 000-20zm-1 15l-5-5 1.4-1.4L11 14.2l6.6-6.6L19 9z"/></svg>
                                            {{ $template->status }}
                                        </span>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-gray-100 text-gray-600">
                                            {{ $template->language }}
                                        </span>
                                        @if($template->whatsappInstance)
                                            <span class="text-[10px] text-gray-400">{{ $template->whatsappInstance->instance_name }}</span>
                                        @endif
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-slate-100 text-slate-600">
                                            {{ __('Local') }}
                                        </span>
                                    @endif

                                    @switch($template->category)
                                        @case('promotional')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                {{ __('Promotional') }}
                                            </span>
                                            @break
                                        @case('transactional')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                {{ __('Transactional') }}
                                            </span>
                                            @break
                                        @case('reminder')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                {{ __('Reminder') }}
                                            </span>
                                            @break
                                        @default
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                                                {{ ucfirst($template->category) }}
                                            </span>
                                    @endswitch
                                </div>

                                {{-- Content preview --}}
                                <p class="text-sm text-gray-600 leading-relaxed">
                                    {{ Str::limit($template->content, 100) }}
                                </p>
                            </div>

                            {{-- Actions --}}
                            <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 rounded-b-lg flex items-center justify-end gap-3">
                                <button type="button"
                                        @click="previewTemplate = {{ Js::from($template) }}; previewOpen = true"
                                        class="text-sm font-medium text-gray-500 hover:text-gray-700 transition-colors duration-150">
                                    {{ __('Preview') }}
                                </button>
                                <a href="{{ route('templates.edit', $template) }}"
                                   class="text-sm font-medium text-[#25D366] hover:text-[#1da851] transition-colors duration-150">
                                    {{ __('Edit') }}
                                </a>
                                <form method="POST"
                                      action="{{ route('templates.destroy', $template) }}"
                                      onsubmit="return confirm('{{ __('Are you sure you want to delete this template?') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="text-sm font-medium text-red-600 hover:text-red-800 transition-colors duration-150">
                                        {{ __('Delete') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Preview Modal --}}
        <div x-show="previewOpen"
             x-transition:enter="ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 z-50 overflow-y-auto"
             style="display: none;">
            <div class="flex items-center justify-center min-h-screen px-4">
                {{-- Backdrop --}}
                <div class="fixed inset-0 bg-black/50 transition-opacity" @click="previewOpen = false"></div>

                {{-- Modal panel --}}
                <div class="relative bg-white rounded-xl shadow-xl max-w-lg w-full z-10"
                     x-transition:enter="ease-out duration-200"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100"
                     x-transition:leave="ease-in duration-150"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95"
                     @click.outside="previewOpen = false">

                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900" x-text="previewTemplate?.name"></h3>
                        <button @click="previewOpen = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <div class="p-6">
                        {{-- WhatsApp-style message bubble --}}
                        <div class="bg-[#dcf8c6] rounded-lg p-4 shadow-sm max-w-sm">
                            <p class="text-sm text-gray-800 whitespace-pre-wrap" x-text="previewTemplate?.content"></p>
                            <div class="flex items-center justify-end mt-2 gap-1">
                                <span class="text-xs text-gray-500">12:00</span>
                                <svg class="w-4 h-4 text-blue-500" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M18 7l-1.41-1.41-6.34 6.34 1.41 1.41L18 7zm4.24-1.41L11.66 16.17 7.48 12l-1.41 1.41L11.66 19l12-12-1.42-1.41zM.41 13.41L6 19l1.41-1.41L1.83 12 .41 13.41z"/>
                                </svg>
                            </div>
                        </div>

                        <template x-if="previewTemplate?.media_path">
                            <div class="mt-4 flex items-center gap-2 text-sm text-gray-500">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                                </svg>
                                <span>{{ __('This template includes a media attachment.') }}</span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
