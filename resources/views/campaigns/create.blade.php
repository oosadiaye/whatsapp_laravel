<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Create Campaign</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
            @php
                // Auto-jump to the first tab containing a validation error so the user
                // sees what's wrong instead of landing on Basic with nothing visible.
                $tabFieldMap = [
                    'basic' => ['name', 'instance_id'],
                    'recipients' => ['groups', 'groups.*'],
                    'message' => ['message', 'message_template_id', 'template_language', 'header_media'],
                    'schedule' => ['scheduled_at', 'rate_per_minute', 'delay_min', 'delay_max'],
                ];
                $initialTab = 'basic';
                foreach ($tabFieldMap as $tabKey => $fields) {
                    foreach ($fields as $field) {
                        if ($errors->has($field)) {
                            $initialTab = $tabKey;
                            break 2;
                        }
                    }
                }
            @endphp

            <form action="{{ route('campaigns.store') }}" method="POST" enctype="multipart/form-data"
                  x-data="{
                      tabs: ['basic', 'recipients', 'message', 'schedule'],
                      tab: '{{ $initialTab }}',
                      message: @js(old('message', '')),
                      previewMessage: '',
                      currentIndex() { return this.tabs.indexOf(this.tab); },
                      isFirst() { return this.currentIndex() === 0; },
                      isLast() { return this.currentIndex() === this.tabs.length - 1; },
                      next() {
                          // Per-tab client-side validation: prevent advancing past required fields.
                          // Server validates again, this just helps the user fix mistakes early.
                          //
                          // Selectors use Alpine $refs — scoped to this x-data root (the form),
                          // safer than querySelector because they don't depend on attribute-
                          // selector parsing rules or DOM-naming-collision quirks. Null-guarded
                          // so a missing ref flashes through instead of throwing.
                          if (this.tab === 'basic') {
                              const el = this.$refs.nameField;
                              if (el && !el.value.trim()) { el.focus(); el.reportValidity(); return; }
                          }
                          if (this.tab === 'recipients') {
                              const container = this.$refs.recipientsTab;
                              const checked = container ? container.querySelectorAll('input:checked') : [];
                              if (checked.length === 0) {
                                  alert('Pick at least one contact group before continuing.');
                                  return;
                              }
                          }
                          if (this.tab === 'message') {
                              // Message textarea lives inside a nested x-data scope, so $refs
                              // declared there are NOT visible to this outer scope. Fall back
                              // to a quoted attribute selector — 'message' has no reserved-
                              // word collisions like 'name' did, and we tag-anchor with
                              // `textarea` for specificity.
                              const el = this.$el.querySelector(`textarea[name="message"]`);
                              if (el && !el.value.trim()) { el.focus(); el.reportValidity(); return; }
                          }
                          this.tab = this.tabs[this.currentIndex() + 1];
                          window.scrollTo({ top: 0, behavior: 'smooth' });
                      },
                      prev() {
                          this.tab = this.tabs[this.currentIndex() - 1];
                          window.scrollTo({ top: 0, behavior: 'smooth' });
                      },
                  }"
                  class="space-y-6">
                @csrf

                {{-- Tab Navigation. A red dot beside the label flags any tab that
                     has a server-side validation error so the user can jump to fix it. --}}
                <div class="border-b border-gray-200">
                    <nav class="-mb-px flex space-x-8">
                        @foreach(['basic' => 'Basic Info', 'recipients' => 'Recipients', 'message' => 'Message', 'schedule' => 'Schedule'] as $key => $label)
                        @php
                            $tabHasError = false;
                            foreach ($tabFieldMap[$key] as $field) {
                                if ($errors->has($field)) { $tabHasError = true; break; }
                            }
                        @endphp
                        <button type="button" @click="tab = '{{ $key }}'"
                                :class="tab === '{{ $key }}' ? 'border-[#25D366] text-[#25D366]' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                class="whitespace-nowrap border-b-2 px-1 py-4 text-sm font-medium inline-flex items-center gap-1.5">
                            {{ $label }}
                            @if($tabHasError)
                                <span class="inline-block w-2 h-2 rounded-full bg-red-500" title="This tab has errors that need fixing"></span>
                            @endif
                        </button>
                        @endforeach
                    </nav>
                </div>

                {{-- Tab 1: Basic Info --}}
                <div x-show="tab === 'basic'" class="rounded-xl bg-white p-6 shadow-sm">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Campaign Name *</label>
                            <input type="text" name="name" x-ref="nameField" value="{{ old('name') }}" required
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">WhatsApp Instance</label>
                            <select name="instance_id" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                                <option value="">Select instance...</option>
                                @foreach($instances as $instance)
                                <option value="{{ $instance->id }}" {{ old('instance_id') == $instance->id ? 'selected' : '' }}>
                                    {{ $instance->display_name ?? $instance->instance_name }} ({{ $instance->status }})
                                </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                {{-- Tab 2: Recipients --}}
                <div x-show="tab === 'recipients'" x-ref="recipientsTab" class="rounded-xl bg-white p-6 shadow-sm">
                    <p class="mb-4 text-sm text-gray-600">Select contact groups to send to:</p>
                    <div class="space-y-3">
                        @foreach($groups as $group)
                        <label class="flex items-center rounded-lg border p-4 hover:bg-gray-50">
                            <input type="checkbox" name="groups[]" value="{{ $group->id }}"
                                   {{ in_array($group->id, old('groups', [])) ? 'checked' : '' }}
                                   class="rounded border-gray-300 text-[#25D366] focus:ring-[#25D366]">
                            <span class="ml-3">
                                <span class="font-medium text-gray-900">{{ $group->name }}</span>
                                <span class="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $group->contacts_count ?? $group->contact_count }} contacts</span>
                            </span>
                        </label>
                        @endforeach
                    </div>
                    @error('groups') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Tab 3: Message --}}
                <div x-show="tab === 'message'"
                     x-data="{
                         templatePicked: !!@js(old('message_template_id')),
                         headerMediaFormat: @js(old('_initial_header_format', '')),
                         headerMediaUrl: @js(old('header_media_url', '')),
                         headerImageBroken: false,
                     }"
                     class="rounded-xl bg-white p-6 shadow-sm">

                    {{-- Warning banner when no template selected --}}
                    <div x-show="!templatePicked" x-cloak
                         class="mb-4 rounded-lg bg-amber-50 border border-amber-200 p-3 text-sm text-amber-900">
                        <p class="font-semibold mb-1">{{ __('Sending without a template') }}</p>
                        <p>
                            {{ __('Freeform messages only deliver to contacts who messaged you within the last 24 hours. For marketing blasts to fresh contacts, pick a Meta-approved template above.') }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('Template') }}</label>
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
                                @php
                                    $remoteTemplates = $templates->filter(fn ($t) => $t->isRemote());
                                    $localTemplates = $templates->filter(fn ($t) => ! $t->isRemote());
                                @endphp
                                @if($remoteTemplates->isNotEmpty())
                                    <optgroup label="{{ __('Approved by Meta — sendable any time') }}">
                                        @foreach($remoteTemplates as $template)
                                            <option value="{{ $template->id }}"
                                                    data-content="{{ $template->content }}"
                                                    data-language="{{ $template->language }}"
                                                    data-header-format="{{ $template->headerMediaFormat() ?? '' }}"
                                                    {{ old('message_template_id') == $template->id ? 'selected' : '' }}>
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
                            <input type="hidden" name="template_language" x-ref="langInput" value="{{ old('template_language', '') }}">
                            <p class="mt-1 text-xs text-gray-500">
                                {{ __('Approved templates send via Meta\'s template API. Local templates just paste their content into the message body.') }}
                            </p>
                            @error('message_template_id')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror

                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700">Message *</label>
                                <div class="mb-2 flex flex-wrap gap-1">
                                    @php $tokens = ['{'.'{name}}', '{'.'{phone}}', '{'.'{first_name}}', '{'.'{custom_field_1}}', '{'.'{date}}', '{'.'{campaign_name}}']; @endphp
                                    @foreach($tokens as $token)
                                    <button type="button"
                                            @click="const ta = $refs.messageArea; const s = ta.selectionStart; const e = ta.selectionEnd; ta.value = ta.value.substring(0, s) + '{{ $token }}' + ta.value.substring(e); ta.focus(); message = ta.value;"
                                            class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-600 hover:bg-gray-200">
                                        {{ $token }}
                                    </button>
                                    @endforeach
                                </div>
                                <textarea name="message" x-ref="messageArea" x-model="message" rows="8" required
                                          class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]"
                                          placeholder="Type your message here...">{{ old('message') }}</textarea>
                                @error('message') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>

                            {{-- Single header-media uploader. Replaces the old "Header Media URL"
                                 text field + separate "Attach Media" file input. The selected file
                                 is stored on the public disk by the controller, and its absolute URL
                                 is written to campaigns.header_media_url for SendWhatsAppMessage.

                                 Live preview wires the file via FileReader → data URL so the right-
                                 side bubble updates instantly without a round-trip. --}}
                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700">
                                    {{ __('Header media') }}
                                    <template x-if="headerMediaFormat">
                                        <span class="ml-1 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800"
                                              x-text="headerMediaFormat + ' required'"></span>
                                    </template>
                                </label>
                                <input type="file" name="header_media"
                                       x-bind:accept="headerMediaFormat === 'VIDEO' ? 'video/mp4,video/quicktime' : (headerMediaFormat === 'DOCUMENT' ? 'application/pdf' : 'image/jpeg,image/png,image/gif')"
                                       x-on:change="
                                           const f = $event.target.files[0];
                                           if (!f) { headerMediaUrl = ''; headerImageBroken = false; return; }
                                           const reader = new FileReader();
                                           reader.onload = e => { headerMediaUrl = e.target.result; headerImageBroken = false; };
                                           reader.readAsDataURL(f);
                                       "
                                       class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:rounded-lg file:border-0 file:bg-[#25D366] file:px-4 file:py-2 file:text-sm file:text-white hover:file:bg-[#1da851]">
                                <p class="mt-1 text-xs text-gray-500">
                                    <template x-if="headerMediaFormat">
                                        <span x-text="'This template requires a ' + headerMediaFormat.toLowerCase() + ' for its header. Max 16MB.'"></span>
                                    </template>
                                    <template x-if="!headerMediaFormat">
                                        <span>{{ __('Optional. Attach an image, video, or PDF to send with this campaign.') }}</span>
                                    </template>
                                </p>
                                @error('header_media')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('Preview') }}</label>
                            <p class="text-xs text-gray-500 mb-2">{{ __('Updates live as you type. Personalization tokens shown as sample values.') }}</p>

                            {{-- WhatsApp-faithful preview: tan chat backdrop, light-green outgoing bubble,
                                 header media at top, body below, time + double-tick at bottom right.
                                 Matches the styling in conversations/show.blade.php for visual consistency. --}}
                            <div class="mt-1 rounded-xl bg-[#e5ddd5] p-3 sm:p-4" style="min-height: 280px;">
                                <div class="ml-auto max-w-[280px] rounded-lg bg-[#dcf8c6] shadow overflow-hidden">

                                    {{-- Header media preview (only when a media-header template is selected) --}}
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

                                    {{-- Body text — the existing personalization replacement preserved --}}
                                    <div class="p-3">
                                        <p class="whitespace-pre-wrap text-sm text-gray-800 break-words"
                                           x-text="message.replace(/\{\{name\}\}/g, 'John').replace(/\{\{phone\}\}/g, '2348012345678').replace(/\{\{first_name\}\}/g, 'John').replace(/\{\{date\}\}/g, '{{ now()->format('d F Y') }}').replace(/\{\{campaign_name\}\}/g, 'My Campaign').replace(/\{\{custom_field_1\}\}/g, 'Value 1') || '{{ __('Your message preview will appear here...') }}'"></p>

                                        {{-- Time + double-tick (sent indicator) — bottom right of bubble --}}
                                        <div class="flex items-center justify-end gap-1 mt-1">
                                            <span class="text-[10px] text-gray-500">{{ now()->format('H:i') }}</span>
                                            <svg class="w-3.5 h-3.5 text-blue-500" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M18 7l-1.41-1.41-6.34 6.34 1.41 1.41L18 7zm4.24-1.41L11.66 16.17 7.48 12l-1.41 1.41L11.66 19l12-12-1.42-1.41zM.41 13.41L6 19l1.41-1.41L1.83 12 .41 13.41z"/>
                                            </svg>
                                        </div>
                                    </div>
                                </div>

                                {{-- Disclaimer below the bubble --}}
                                <p class="mt-3 text-center text-[10px] text-gray-500 italic">
                                    {{ __('Visual preview only — actual rendering depends on the recipient device.') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Tab 4: Schedule --}}
                <div x-show="tab === 'schedule'" class="rounded-xl bg-white p-6 shadow-sm">
                    <div class="space-y-6" x-data="{ sendMode: 'immediate' }">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Send Mode</label>
                            <div class="mt-2 flex gap-4">
                                <label class="flex items-center">
                                    <input type="radio" x-model="sendMode" value="immediate" class="text-[#25D366] focus:ring-[#25D366]">
                                    <span class="ml-2 text-sm text-gray-700">Send Immediately</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="radio" x-model="sendMode" value="scheduled" class="text-[#25D366] focus:ring-[#25D366]">
                                    <span class="ml-2 text-sm text-gray-700">Schedule for Later</span>
                                </label>
                            </div>
                        </div>
                        <div x-show="sendMode === 'scheduled'">
                            <label class="block text-sm font-medium text-gray-700">Send Date & Time</label>
                            <input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at') }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Messages per Minute</label>
                                <input type="number" name="rate_per_minute" value="{{ old('rate_per_minute', 10) }}" min="1" max="60"
                                       class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                                <p class="mt-1 text-xs text-gray-500">Recommended: 10-20 to avoid bans</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Min Delay (seconds)</label>
                                <input type="number" name="delay_min" value="{{ old('delay_min', 2) }}" min="1" max="30"
                                       class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Max Delay (seconds)</label>
                                <input type="number" name="delay_max" value="{{ old('delay_max', 8) }}" min="1" max="60"
                                       class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Wizard footer — buttons adapt to current tab.
                     Always: [Cancel] (left) + [Save as Draft] (any tab, persists progress)
                     Not last tab: [Back if not first] [Save as Draft] [Next →]
                     Last tab: [Back] [Save as Draft] [Save & Launch] --}}
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-6">
                    <div class="flex items-center gap-3">
                        <a href="{{ route('campaigns.index') }}"
                           class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
                        <button type="button" x-show="!isFirst()" @click="prev()"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            ← Back
                        </button>
                    </div>

                    <div class="flex items-center gap-3">
                        {{-- Save as Draft — always available; lets the user persist work without finishing every tab. --}}
                        <button type="submit" name="status" value="DRAFT"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Save as Draft
                        </button>
                        {{-- Next: advances to next tab; client-side validates the current tab first. --}}
                        <button type="button" x-show="!isLast()" @click="next()"
                                class="rounded-lg bg-[#25D366] px-5 py-2 text-sm font-medium text-white hover:bg-[#1da851]">
                            Next →
                        </button>
                        {{-- Save & Launch: only on the last tab; this is the "I'm done, send it" submit. --}}
                        <button type="submit" name="status" value="QUEUED" x-show="isLast()" x-cloak
                                class="rounded-lg bg-[#25D366] px-5 py-2 text-sm font-medium text-white hover:bg-[#1da851]">
                            Save & Launch
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
