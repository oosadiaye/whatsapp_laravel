<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Edit Template') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8"
             x-data="templateEditor()">

            <form method="POST" action="{{ route('templates.update', $template) }}" enctype="multipart/form-data">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
                    {{-- Form Panel --}}
                    <div class="lg:col-span-3 bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="p-6 space-y-6">

                            {{-- Name --}}
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">{{ __('Template Name') }}</label>
                                <input type="text"
                                       id="name"
                                       name="name"
                                       value="{{ old('name', $template->name) }}"
                                       x-model="name"
                                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366] sm:text-sm"
                                       placeholder="e.g. Welcome Message">
                                @error('name')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            {{-- Category --}}
                            <div>
                                <label for="category" class="block text-sm font-medium text-gray-700 mb-1">{{ __('Category') }}</label>
                                <select id="category"
                                        name="category"
                                        x-model="category"
                                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366] sm:text-sm">
                                    <option value="">{{ __('-- Select category --') }}</option>
                                    <option value="promotional" {{ old('category', $template->category) === 'promotional' ? 'selected' : '' }}>{{ __('Promotional') }}</option>
                                    <option value="transactional" {{ old('category', $template->category) === 'transactional' ? 'selected' : '' }}>{{ __('Transactional') }}</option>
                                    <option value="reminder" {{ old('category', $template->category) === 'reminder' ? 'selected' : '' }}>{{ __('Reminder') }}</option>
                                </select>
                                @error('category')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            {{-- Token Helpers --}}
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Insert Token') }}</label>
                                <div class="flex flex-wrap gap-2">
                                    @foreach(['{'.'{name}}', '{'.'{phone}}', '{'.'{first_name}}', '{'.'{custom_field_1}}', '{'.'{date}}', '{'.'{campaign_name}}'] as $token)
                                        <button type="button"
                                                @click="insertToken('{{ $token }}')"
                                                class="inline-flex items-center px-3 py-1.5 bg-gray-100 hover:bg-gray-200 border border-gray-200 rounded-md text-xs font-mono text-gray-700 transition-colors duration-150">
                                            {{ $token }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Content --}}
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label for="content" class="block text-sm font-medium text-gray-700">{{ __('Message Content') }}</label>
                                    <span class="text-xs"
                                          :class="content.length > 4096 ? 'text-red-500 font-semibold' : 'text-gray-400'">
                                        <span x-text="content.length"></span> / 4096
                                    </span>
                                </div>
                                <textarea id="content"
                                          name="content"
                                          x-ref="content"
                                          x-model="content"
                                          rows="10"
                                          class="w-full rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366] sm:text-sm font-mono"
                                          placeholder="Type your message here. Use tokens like @{{name}} to personalize.">{{ old('content', $template->content) }}</textarea>
                                @error('content')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            {{-- Current Media --}}
                            @if($template->media_path)
                                <div class="bg-gray-50 rounded-lg p-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Current Media') }}</label>
                                    <div class="flex items-center gap-3">
                                        <div class="flex items-center gap-2 px-3 py-2 bg-white border border-gray-200 rounded-md">
                                            <svg class="w-5 h-5 text-[#25D366]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                                            </svg>
                                            <span class="text-sm text-gray-700">{{ basename($template->media_path) }}</span>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            {{-- Media Upload --}}
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    {{ $template->media_path ? __('Replace Media') : __('Media Attachment (optional)') }}
                                </label>
                                <input type="file"
                                       name="media"
                                       accept="image/*,application/pdf,audio/*"
                                       class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-[#25D366] hover:file:bg-green-100 file:transition file:duration-150 file:cursor-pointer">
                                <p class="mt-1 text-xs text-gray-400">{{ __('Supported: Images, PDF, Audio. Leave empty to keep current file.') }}</p>
                                @error('media')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            {{-- Actions --}}
                            <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-200">
                                <a href="{{ route('templates.index') }}"
                                   class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition ease-in-out duration-150">
                                    {{ __('Cancel') }}
                                </a>
                                <button type="submit"
                                        class="inline-flex items-center px-6 py-2 bg-[#25D366] border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-[#1da851] focus:outline-none focus:ring-2 focus:ring-[#25D366] focus:ring-offset-2 transition ease-in-out duration-150">
                                    {{ __('Update Template') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- Live Preview Panel --}}
                    <div class="lg:col-span-2">
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg sticky top-6">
                            <div class="px-5 py-3 border-b border-gray-200">
                                <h3 class="text-sm font-semibold text-gray-700">{{ __('Live Preview') }}</h3>
                            </div>
                            <div class="p-5">
                                {{-- Phone frame --}}
                                <div class="bg-[#e5ddd5] rounded-lg p-4 min-h-[200px]"
                                     style="background-image: url('data:image/svg+xml,%3Csvg width=&quot;60&quot; height=&quot;60&quot; viewBox=&quot;0 0 60 60&quot; xmlns=&quot;http://www.w3.org/2000/svg&quot;%3E%3Cg fill=&quot;none&quot; fill-rule=&quot;evenodd&quot;%3E%3Cg fill=&quot;%23ccc4b7&quot; fill-opacity=&quot;0.15&quot;%3E%3Cpath d=&quot;M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z&quot;/%3E%3C/g%3E%3C/g%3E%3C/svg%3E');">
                                    <template x-if="content.trim().length > 0">
                                        <div class="bg-[#dcf8c6] rounded-lg p-3 shadow-sm max-w-xs ml-auto">
                                            <p class="text-sm text-gray-800 whitespace-pre-wrap break-words" x-text="renderedPreview()"></p>
                                            <div class="flex items-center justify-end mt-1.5 gap-1">
                                                <span class="text-xs text-gray-500">{{ now()->format('H:i') }}</span>
                                                <svg class="w-4 h-4 text-blue-500" fill="currentColor" viewBox="0 0 24 24">
                                                    <path d="M18 7l-1.41-1.41-6.34 6.34 1.41 1.41L18 7zm4.24-1.41L11.66 16.17 7.48 12l-1.41 1.41L11.66 19l12-12-1.42-1.41zM.41 13.41L6 19l1.41-1.41L1.83 12 .41 13.41z"/>
                                                </svg>
                                            </div>
                                        </div>
                                    </template>
                                    <template x-if="content.trim().length === 0">
                                        <div class="flex items-center justify-center h-32 text-sm text-gray-400">
                                            {{ __('Start typing to see preview...') }}
                                        </div>
                                    </template>
                                </div>

                                {{-- Sample data info --}}
                                <div class="mt-4 text-xs text-gray-400">
                                    <p class="font-medium text-gray-500 mb-1">{{ __('Sample tokens:') }}</p>
                                    <ul class="space-y-0.5">
                                        <li><span class="font-mono">@{{name}}</span> &rarr; John Doe</li>
                                        <li><span class="font-mono">@{{phone}}</span> &rarr; 628123456789</li>
                                        <li><span class="font-mono">@{{first_name}}</span> &rarr; John</li>
                                        <li><span class="font-mono">@{{custom_field_1}}</span> &rarr; VIP</li>
                                        <li><span class="font-mono">@{{date}}</span> &rarr; {{ now()->format('d M Y') }}</li>
                                        <li><span class="font-mono">@{{campaign_name}}</span> &rarr; Spring Sale</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
    <script>
        function templateEditor() {
            return {
                name: @js(old('name', $template->name)),
                category: @js(old('category', $template->category)),
                content: @js(old('content', $template->content)),

                sampleData: {
                    '@{{name}}': 'John Doe',
                    '@{{phone}}': '628123456789',
                    '@{{first_name}}': 'John',
                    '@{{custom_field_1}}': 'VIP',
                    '@{{date}}': '{{ now()->format("d M Y") }}',
                    '@{{campaign_name}}': 'Spring Sale',
                },

                insertToken(token) {
                    const ta = this.$refs.content;
                    const start = ta.selectionStart;
                    const end = ta.selectionEnd;
                    const before = this.content.substring(0, start);
                    const after = this.content.substring(end);
                    this.content = before + token + after;
                    this.$nextTick(() => {
                        ta.selectionStart = ta.selectionEnd = start + token.length;
                        ta.focus();
                    });
                },

                renderedPreview() {
                    let result = this.content;
                    for (const [token, value] of Object.entries(this.sampleData)) {
                        result = result.replaceAll(token, value);
                    }
                    return result;
                },
            };
        }
    </script>
    @endpush
</x-app-layout>
