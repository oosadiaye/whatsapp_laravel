<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Create Campaign</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
            <form action="{{ route('campaigns.store') }}" method="POST" enctype="multipart/form-data"
                  x-data="{ tab: 'basic', message: '', previewMessage: '' }"
                  class="space-y-6">
                @csrf

                {{-- Tab Navigation --}}
                <div class="border-b border-gray-200">
                    <nav class="-mb-px flex space-x-8">
                        @foreach(['basic' => 'Basic Info', 'recipients' => 'Recipients', 'message' => 'Message', 'schedule' => 'Schedule'] as $key => $label)
                        <button type="button" @click="tab = '{{ $key }}'"
                                :class="tab === '{{ $key }}' ? 'border-[#25D366] text-[#25D366]' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                class="whitespace-nowrap border-b-2 px-1 py-4 text-sm font-medium">
                            {{ $label }}
                        </button>
                        @endforeach
                    </nav>
                </div>

                {{-- Tab 1: Basic Info --}}
                <div x-show="tab === 'basic'" class="rounded-xl bg-white p-6 shadow-sm">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Campaign Name *</label>
                            <input type="text" name="name" value="{{ old('name') }}" required
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
                <div x-show="tab === 'recipients'" class="rounded-xl bg-white p-6 shadow-sm">
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
                <div x-show="tab === 'message'" x-data="{ templatePicked: !!@js(old('message_template_id')) }" class="rounded-xl bg-white p-6 shadow-sm">

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
                                                    {{ old('message_template_id') == $template->id ? 'selected' : '' }}>
                                                {{ $template->name }} · {{ $template->language }} · {{ $template->status }}
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

                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700">Attach Media (optional)</label>
                                <input type="file" name="media" accept="image/*,.pdf,.mp3,.ogg"
                                       class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:rounded-lg file:border-0 file:bg-[#25D366] file:px-4 file:py-2 file:text-sm file:text-white hover:file:bg-[#1da851]">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Preview</label>
                            <div class="mt-1 rounded-lg bg-[#e5ddd5] p-4" style="min-height: 200px;">
                                <div class="max-w-xs rounded-lg bg-white p-3 shadow">
                                    <p class="whitespace-pre-wrap text-sm text-gray-800" x-text="message.replace(/\{\{name\}\}/g, 'John').replace(/\{\{phone\}\}/g, '2348012345678').replace(/\{\{first_name\}\}/g, 'John').replace(/\{\{date\}\}/g, '{{ now()->format('d F Y') }}').replace(/\{\{campaign_name\}\}/g, 'My Campaign').replace(/\{\{custom_field_1\}\}/g, 'Value 1') || 'Your message preview will appear here...'"></p>
                                    <p class="mt-1 text-right text-xs text-gray-400">{{ now()->format('H:i') }}</p>
                                </div>
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

                {{-- Submit --}}
                <div class="flex justify-end gap-3">
                    <a href="{{ route('campaigns.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
                    <button type="submit" name="status" value="DRAFT" class="rounded-lg bg-gray-600 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">Save as Draft</button>
                    <button type="submit" name="status" value="QUEUED" class="rounded-lg bg-[#25D366] px-4 py-2 text-sm font-medium text-white hover:bg-[#1da851]">Save & Launch</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
