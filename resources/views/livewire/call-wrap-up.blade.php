<div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100">
        <h3 class="text-sm font-bold text-gray-700">{{ __('Call Wrap-Up') }}</h3>
    </div>

    <form wire:submit="save" class="p-5 space-y-4">
        {{-- Disposition --}}
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">{{ __('Disposition') }}</label>
            <div class="grid grid-cols-2 gap-2">
                @foreach($dispositions as $value => $label)
                    <label class="flex items-center gap-2 p-2 rounded-lg border cursor-pointer transition-colors @if($disposition === $value) border-blue-400 bg-blue-50 @else border-gray-200 hover:border-gray-300 @endif">
                        <input type="radio" name="disposition" value="{{ $value }}" wire:model.live="disposition" class="sr-only">
                        <span class="w-4 h-4 rounded-full border-2 flex items-center justify-center shrink-0 @if($disposition === $value) border-blue-500 @else border-gray-300 @endif">
                            @if($disposition === $value)
                                <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                            @endif
                        </span>
                        <span class="text-sm text-gray-700">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        {{-- Wrap-up note --}}
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">{{ __('Note') }}</label>
            <textarea wire:model="wrapUpNote" rows="3"
                      class="w-full rounded-lg border-gray-200 text-sm resize-none focus:border-blue-400 focus:ring focus:ring-blue-200"
                      placeholder="{{ __('Add a note about this call...') }}"></textarea>
        </div>

        {{-- Actions --}}
        <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
            <button type="button" @click="$dispatch('close-wrap-up')"
                    class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-800 transition-colors">
                {{ __('Close') }}
            </button>
            <button type="submit" wire:loading.attr="disabled"
                    class="px-4 py-2 text-sm font-semibold text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors">
                <span wire:loading.remove>{{ __('Save') }}</span>
                <span wire:loading>{{ __('Saving...') }}</span>
            </button>
        </div>
    </form>
</div>
