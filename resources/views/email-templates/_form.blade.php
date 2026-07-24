<form method="POST" action="{{ $action }}"
      x-data="{ body: @js(old('body_html', $template?->body_html ?? ($preselectHtml ?? ''))) }"
      class="space-y-6">
    @csrf
    @if($template)
        @method('PUT')
    @endif

    <div class="grid sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">{{ __('Template name') }} *</label>
            <input type="text" name="name" value="{{ old('name', $template?->name) }}" required
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                   placeholder="{{ __('e.g. Monthly newsletter') }}">
            @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">{{ __('Default subject (optional)') }}</label>
            <input type="text" name="subject" value="{{ old('subject', $template?->subject) }}"
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                   placeholder="{{ __('Suggested subject line') }}">
            @error('subject')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
    </div>

    @if(! empty($starters ?? []))
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Start from a design') }}</label>
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
                @foreach($starters as $s)
                    <button type="button"
                            @click="body = document.getElementById('starter-{{ $s['key'] }}').innerHTML.trim()"
                            class="rounded-lg border border-gray-200 bg-white overflow-hidden hover:border-gray-300 hover:shadow-sm transition focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        <span class="block h-1.5" style="background: {{ $s['accent'] }}"></span>
                        <span class="block px-2 py-1.5 text-xs font-semibold text-gray-700">{{ $s['name'] }}</span>
                    </button>
                @endforeach
            </div>
            @foreach($starters as $s)
                <template id="starter-{{ $s['key'] }}">{!! $s['html'] !!}</template>
            @endforeach
        </div>
    @endif

    <div class="grid lg:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">{{ __('HTML') }} *</label>
            <textarea name="body_html" x-model="body" rows="18" required
                      class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm font-mono text-xs focus:border-indigo-500 focus:ring-indigo-500"></textarea>
            <p class="mt-1 text-xs text-gray-400">{{ __('Personalize with') }} <code>@{{name}}</code> {{ __('and') }} <code>@{{email}}</code>.</p>
            @error('body_html')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">{{ __('Live preview') }}</label>
            {{-- Sandboxed (no scripts/same-origin), safe under CSP frame-src 'self'. --}}
            <iframe sandbox :srcdoc="body" title="{{ __('Preview') }}"
                    class="mt-1 w-full h-[420px] rounded-lg border border-gray-200 bg-white"></iframe>
        </div>
    </div>

    <div class="flex items-center gap-2">
        <button type="submit"
                class="inline-flex items-center px-4 py-2 bg-[#4f46e5] text-white rounded-lg text-sm font-semibold hover:bg-[#4338ca] transition">
            {{ $template ? __('Update template') : __('Save template') }}
        </button>
        <a href="{{ route('email-templates.index') }}" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">{{ __('Cancel') }}</a>
    </div>
</form>
