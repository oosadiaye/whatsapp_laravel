{{-- Searchable OpenRouter model picker (resources/js/model-picker.js).
     Config rides in data-* attributes, not x-data, so quotes in values can't break the component.
     Lives inside the AI card's x-data, whose `provider` disables this input when Gemini is chosen
     (a disabled input isn't submitted, so only the active provider's field is saved). --}}
<div x-data="bqModelPicker()"
     data-name="{{ $name }}"
     data-value="{{ $value }}"
     data-url="{{ route('settings.openrouter-models') }}"
     @keydown.escape="open = false"
     @click.outside="open = false"
     class="relative">
    <label class="block text-xs font-medium text-gray-700 mb-1">{{ $label }}</label>

    <p x-show="loading" class="text-xs text-gray-500 py-2">{{ __('Loading models…') }}</p>

    {{-- OpenRouter unreachable: fall back to typing the id so the page never blocks. --}}
    <div x-show="!loading && failed" x-cloak>
        <input type="text" :name="name" x-model="value" :disabled="provider !== 'openrouter'" placeholder="vendor/model-name"
               class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand focus:ring-brand font-data text-sm">
        <p class="mt-1 text-xs text-gray-500">
            {{ __('Could not load the model list.') }}
            <button type="button" @click="load()" class="text-brand-text underline">{{ __('Retry') }}</button>
        </p>
    </div>

    <div x-show="!loading && !failed" x-cloak>
        <input type="hidden" :name="name" :value="value" :disabled="provider !== 'openrouter'">

        <button type="button" @click="open = !open" aria-haspopup="listbox" :aria-expanded="open"
                class="flex w-full items-center justify-between gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-left text-sm shadow-sm hover:border-gray-400 focus:border-brand focus:ring-brand">
            <span class="min-w-0">
                <span class="block truncate text-gray-900" x-text="label || '{{ __('Choose a model') }}'"></span>
                <span class="block truncate font-data text-xs text-gray-500" x-show="value" x-text="value"></span>
            </span>
            <svg class="h-4 w-4 shrink-0 text-gray-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
        </button>

        <div x-show="open" x-cloak x-transition.opacity
             class="absolute z-30 mt-1 w-full min-w-[20rem] rounded-xl border border-gray-200 bg-white shadow-lg">
            <div class="space-y-2 border-b border-gray-100 p-3">
                <input type="search" x-model.debounce.150ms="query" x-effect="if (open) $nextTick(() => $el.focus({ preventScroll: true }))"
                       placeholder="{{ __('Search models…') }}" aria-label="{{ __('Search models') }}"
                       class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" @click="onlyFree = !onlyFree" :aria-pressed="onlyFree"
                            :class="onlyFree ? 'bg-brand-soft text-brand-text border-brand' : 'bg-white text-gray-700 border-gray-300'"
                            class="rounded-full border px-3 py-1 text-xs font-medium">{{ __('Free') }}</button>
                    <button type="button" @click="onlyVision = !onlyVision" :aria-pressed="onlyVision"
                            :class="onlyVision ? 'bg-brand-soft text-brand-text border-brand' : 'bg-white text-gray-700 border-gray-300'"
                            class="rounded-full border px-3 py-1 text-xs font-medium">{{ __('Accepts images') }}</button>
                    <span class="ml-auto text-xs text-gray-500" x-text="filtered.length + ' / ' + models.length"></span>
                </div>
            </div>

            <ul role="listbox" class="max-h-72 overflow-y-auto py-1">
                <li x-show="customCandidate">
                    <button type="button" @click="choose(customCandidate)" class="w-full px-3 py-2 text-left text-sm hover:bg-gray-50">
                        {{ __('Use') }} <span class="font-data" x-text="customCandidate"></span>
                    </button>
                </li>
                <template x-for="m in visible" :key="m.id">
                    <li role="option" :aria-selected="m.id === value">
                        <button type="button" @click="choose(m.id)"
                                :class="m.id === value ? 'bg-brand-soft' : 'hover:bg-gray-50'"
                                class="w-full px-3 py-2 text-left">
                            <span class="flex items-center gap-2">
                                <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900" x-text="m.name"></span>
                                <span x-show="m.free" class="rounded-full bg-brand-soft px-2 py-0.5 text-[11px] font-semibold text-brand-text">{{ __('Free') }}</span>
                                <span x-show="m.vision" class="rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700">{{ __('Image') }}</span>
                            </span>
                            <span class="mt-0.5 flex items-center gap-2 text-xs text-gray-500">
                                <span class="min-w-0 flex-1 truncate font-data" x-text="m.id"></span>
                                <span x-text="context(m.context)"></span>
                            </span>
                            <span x-show="!m.free" class="block text-xs text-gray-500" x-text="price(m)"></span>
                        </button>
                    </li>
                </template>
                <li x-show="filtered.length === 0 && !customCandidate" class="px-3 py-6 text-center text-sm text-gray-500">{{ __('No models match.') }}</li>
                <li x-show="hiddenCount > 0" class="px-3 py-2 text-center text-xs text-gray-500" x-text="hiddenCount + ' {{ __('more — keep typing to narrow the list') }}'"></li>
            </ul>
        </div>
    </div>
</div>
