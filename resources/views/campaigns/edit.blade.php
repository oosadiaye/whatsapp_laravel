<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Edit Campaign: {{ $campaign->name }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
            <form action="{{ route('campaigns.update', $campaign) }}" method="POST" enctype="multipart/form-data"
                  x-data="{
                      message: @js(old('message', $campaign->message)),
                      templatePicked: !!@js(old('message_template_id', $campaign->message_template_id)),
                      headerMediaFormat: @js(old('_initial_header_format', $campaign->messageTemplate?->headerMediaFormat() ?? '')),
                      headerMediaUrl: @js(old('header_media_url', $campaign->header_media_url ?? '')),
                      headerImageBroken: false,
                  }"
                  class="space-y-6">
                @csrf
                @method('PUT')

                <div class="rounded-xl bg-white p-6 shadow-sm space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Campaign Name *</label>
                        <input type="text" name="name" value="{{ old('name', $campaign->name) }}" required
                               class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">WhatsApp Instance</label>
                        <select name="instance_id" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                            <option value="">Select instance...</option>
                            @foreach($instances as $instance)
                            <option value="{{ $instance->id }}" {{ old('instance_id', $campaign->instance_id) == $instance->id ? 'selected' : '' }}>
                                {{ $instance->display_name ?? $instance->instance_name }}
                            </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Contact Groups *</label>
                        @php $selectedGroups = old('groups', $campaign->groups->pluck('id')->toArray()); @endphp
                        @foreach($groups as $group)
                        <label class="mt-2 flex items-center">
                            <input type="checkbox" name="groups[]" value="{{ $group->id }}"
                                   {{ in_array($group->id, $selectedGroups) ? 'checked' : '' }}
                                   class="rounded border-gray-300 text-[#25D366]">
                            <span class="ml-2 text-sm">{{ $group->name }} ({{ $group->contacts_count ?? $group->contact_count }})</span>
                        </label>
                        @endforeach
                    </div>

                    {{-- Template + Message + Header Media + Preview — laid out two-column on lg --}}
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 pt-2">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ __('Template') }}</label>
                                @php
                                    $remoteTemplates = $templates->filter(fn ($t) => $t->isRemote());
                                    $localTemplates = $templates->filter(fn ($t) => ! $t->isRemote());
                                @endphp
                                <select name="message_template_id"
                                        class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm"
                                        @change="
                                            const opt = $event.target.selectedOptions[0];
                                            templatePicked = !!opt.value;
                                            headerMediaFormat = opt.dataset.headerFormat || '';
                                            if (opt.value) {
                                                message = opt.dataset.content;
                                                $refs.messageArea.value = message;
                                                $refs.langInput.value = opt.dataset.language || 'en_US';
                                            } else {
                                                $refs.langInput.value = '';
                                            }
                                        ">
                                    <option value="">{{ __('Compose from scratch (24h window only)') }}</option>
                                    @if($remoteTemplates->isNotEmpty())
                                        <optgroup label="{{ __('Approved by Meta — sendable any time') }}">
                                            @foreach($remoteTemplates as $template)
                                                <option value="{{ $template->id }}"
                                                        data-content="{{ $template->content }}"
                                                        data-language="{{ $template->language }}"
                                                        data-header-format="{{ $template->headerMediaFormat() ?? '' }}"
                                                        {{ old('message_template_id', $campaign->message_template_id) == $template->id ? 'selected' : '' }}>
                                                    {{ $template->name }} · {{ $template->language }} · {{ $template->status }}
                                                    @if($template->headerMediaFormat()) · {{ $template->headerMediaFormat() }} HEADER @endif
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                    @if($localTemplates->isNotEmpty())
                                        <optgroup label="{{ __('Local — only fills the message body') }}">
                                            @foreach($localTemplates as $template)
                                                <option value=""
                                                        data-content="{{ $template->content }}"
                                                        data-language="{{ $template->language ?? 'en_US' }}">
                                                    {{ $template->name }}
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                </select>
                                <input type="hidden" name="template_language" x-ref="langInput"
                                       value="{{ old('template_language', $campaign->template_language) }}">
                            </div>

                            {{-- Conditional: Header Media URL when picked template has IMAGE/VIDEO/DOCUMENT header --}}
                            <div x-show="headerMediaFormat" x-cloak class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                                <label class="block text-sm font-medium text-amber-900">
                                    {{ __('Header Media URL') }} <span x-text="'(' + headerMediaFormat + ')'" class="text-xs"></span>
                                </label>
                                <input type="url" name="header_media_url"
                                       x-model="headerMediaUrl"
                                       @input="headerImageBroken = false"
                                       placeholder="https://example.com/header-image.jpg"
                                       class="mt-1 block w-full rounded-md border-amber-300 text-sm focus:border-[#25D366] focus:ring-[#25D366]">
                                <p class="mt-1 text-xs text-amber-800">
                                    {{ __('This template requires a media URL for its header. Must be a publicly-reachable HTTPS URL.') }}
                                </p>
                                @error('header_media_url')
                                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Message *</label>
                                <textarea name="message" x-ref="messageArea" x-model="message" rows="6" required
                                          class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]"></textarea>
                                @error('message') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Media (optional, in-message attachment)</label>
                                @if($campaign->media_path)
                                <p class="text-xs text-gray-500 mt-1">Current: {{ basename($campaign->media_path) }}</p>
                                @endif
                                <input type="file" name="media" accept="image/*,.pdf,.mp3,.ogg"
                                       class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:rounded-lg file:border-0 file:bg-[#25D366] file:px-4 file:py-2 file:text-sm file:text-white">
                            </div>
                        </div>

                        {{-- WhatsApp-faithful preview — same component shape as create.blade.php --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('Preview') }}</label>
                            <p class="text-xs text-gray-500 mb-2">{{ __('Updates live as you type. Personalization tokens shown as sample values.') }}</p>

                            <div class="rounded-xl bg-[#e5ddd5] p-3 sm:p-4 lg:sticky lg:top-20" style="min-height: 280px;">
                                <div class="ml-auto max-w-[280px] rounded-lg bg-[#dcf8c6] shadow overflow-hidden">

                                    <template x-if="headerMediaFormat === 'IMAGE' && headerMediaUrl && !headerImageBroken">
                                        <img :src="headerMediaUrl"
                                             x-on:error="headerImageBroken = true"
                                             alt="Header"
                                             class="w-full h-40 object-cover bg-gray-200">
                                    </template>

                                    <template x-if="headerMediaFormat === 'IMAGE' && headerMediaUrl && headerImageBroken">
                                        <div class="w-full h-40 bg-gray-200 flex flex-col items-center justify-center text-gray-400 text-xs gap-1">
                                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                            <span>{{ __('Image cannot be loaded') }}</span>
                                            <span class="text-[10px] text-gray-400">{{ __('check the URL is publicly reachable') }}</span>
                                        </div>
                                    </template>

                                    <template x-if="headerMediaFormat === 'VIDEO' && headerMediaUrl">
                                        <div class="relative w-full h-40 bg-black flex items-center justify-center">
                                            <video :src="headerMediaUrl" class="w-full h-full object-cover" muted></video>
                                            <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                                                <div class="w-12 h-12 rounded-full bg-black/40 flex items-center justify-center">
                                                    <svg class="w-6 h-6 text-white ml-0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                                </div>
                                            </div>
                                        </div>
                                    </template>

                                    <template x-if="headerMediaFormat === 'DOCUMENT' && headerMediaUrl">
                                        <div class="w-full bg-white border-b border-gray-200 p-3 flex items-center gap-3">
                                            <div class="flex-shrink-0 w-10 h-10 bg-red-100 rounded flex items-center justify-center">
                                                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium text-gray-900 truncate" x-text="headerMediaUrl.split('/').pop() || 'document.pdf'"></p>
                                                <p class="text-xs text-gray-500">{{ __('Document') }}</p>
                                            </div>
                                        </div>
                                    </template>

                                    <div class="p-3">
                                        <p class="whitespace-pre-wrap text-sm text-gray-800 break-words"
                                           x-text="message.replace(/\{\{name\}\}/g, 'John').replace(/\{\{phone\}\}/g, '2348012345678').replace(/\{\{first_name\}\}/g, 'John').replace(/\{\{date\}\}/g, '{{ now()->format('d F Y') }}').replace(/\{\{campaign_name\}\}/g, 'My Campaign').replace(/\{\{custom_field_1\}\}/g, 'Value 1') || '{{ __('Your message preview will appear here...') }}'"></p>

                                        <div class="flex items-center justify-end gap-1 mt-1">
                                            <span class="text-[10px] text-gray-500">{{ now()->format('H:i') }}</span>
                                            <svg class="w-3.5 h-3.5 text-blue-500" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M18 7l-1.41-1.41-6.34 6.34 1.41 1.41L18 7zm4.24-1.41L11.66 16.17 7.48 12l-1.41 1.41L11.66 19l12-12-1.42-1.41zM.41 13.41L6 19l1.41-1.41L1.83 12 .41 13.41z"/>
                                            </svg>
                                        </div>
                                    </div>
                                </div>

                                <p class="mt-3 text-center text-[10px] text-gray-500 italic">
                                    {{ __('Visual preview only — actual rendering depends on the recipient device.') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-4 pt-2 border-t border-gray-100">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Rate (msgs/min)</label>
                            <input type="number" name="rate_per_minute" value="{{ old('rate_per_minute', $campaign->rate_per_minute) }}" min="1" max="60"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Min Delay (s)</label>
                            <input type="number" name="delay_min" value="{{ old('delay_min', $campaign->delay_min) }}" min="1"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Delay (s)</label>
                            <input type="number" name="delay_max" value="{{ old('delay_max', $campaign->delay_max) }}" min="1"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Schedule (optional)</label>
                        <input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', $campaign->scheduled_at?->format('Y-m-d\TH:i')) }}"
                               class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                    </div>
                </div>

                <div class="flex justify-end gap-3">
                    <a href="{{ route('campaigns.show', $campaign) }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
                    <button type="submit" class="rounded-lg bg-[#25D366] px-4 py-2 text-sm font-medium text-white hover:bg-[#1da851]">Update Campaign</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
