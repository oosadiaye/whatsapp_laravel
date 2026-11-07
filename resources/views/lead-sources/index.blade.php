<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Lead sources</h2>
    </x-slot>

    <div class="py-6 max-w-4xl mx-auto px-4 lg:px-8 space-y-6">

        {{-- Global kill switch: one button halts pulling, qualifying and every automatic send. --}}
        <section class="rounded-xl border p-5 {{ $killSwitch ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-white' }} shadow-sm">
            <form method="POST" action="{{ route('lead-sources.kill-switch') }}" class="flex flex-wrap items-center justify-between gap-4">
                @csrf
                <input type="hidden" name="on" value="{{ $killSwitch ? 0 : 1 }}">
                <div class="min-w-0">
                    <h3 class="font-display text-base font-bold text-gray-900">
                        {{ $killSwitch ? 'Lead automation is PAUSED' : 'Lead automation is running' }}
                    </h3>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ $killSwitch
                            ? 'Nothing is being pulled, qualified or sent. Resume when you are ready.'
                            : 'Pause to stop all pulling and automatic sending immediately, for every lead profile.' }}
                    </p>
                </div>
                <button type="submit"
                        class="rounded-lg px-4 py-2 text-sm font-semibold {{ $killSwitch ? 'bg-brand text-white' : 'border border-red-300 bg-white text-red-700 hover:bg-red-50' }}">
                    {{ $killSwitch ? 'Resume automation' : 'Pause all automation' }}
                </button>
            </form>
        </section>

        {{-- Default autonomy for every profile that does not choose its own. --}}
        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm" aria-labelledby="autonomy-default">
            <h3 id="autonomy-default" class="font-display text-lg font-bold text-gray-900">How much can the AI do on its own?</h3>
            <p class="mt-1 text-sm text-gray-600">The default for every lead profile. A profile can choose its own level on its settings page.</p>
            <form method="POST" action="{{ route('lead-sources.autonomy') }}" class="mt-4 space-y-3">
                @csrf
                @foreach(\App\Services\Leads\Outreach\AutonomyPolicy::LABELS as $level => $label)
                    <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-3 text-sm text-gray-800 has-[:checked]:border-brand has-[:checked]:bg-brand-soft">
                        <input type="radio" name="level" value="{{ $level }}" class="mt-0.5" @checked($autonomyDefault === $level)>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
                <p class="text-xs text-gray-500">Unsubscribe requests are always honoured, and sensitive or unclear replies always come to a person, at every level.</p>
                <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">Save default</button>
            </form>
        </section>

        @foreach($sources as $key => $source)
            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm" aria-labelledby="source-{{ $key }}">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h3 id="source-{{ $key }}" class="font-display text-lg font-bold text-gray-900">{{ $source->label() }}</h3>
                    @if($source->isConfigured())
                        <span class="rounded-full bg-brand-soft px-2.5 py-0.5 text-xs font-semibold text-brand-text">Key saved</span>
                    @else
                        <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-600">No key</span>
                    @endif
                </div>

                <form method="POST" action="{{ route('lead-sources.update', $key) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    @method('PUT')
                    <div class="min-w-[16rem] flex-1">
                        <label for="key-{{ $key }}" class="mb-1 block text-xs font-medium text-gray-600">API key</label>
                        <input id="key-{{ $key }}" type="password" name="api_key" autocomplete="off"
                               placeholder="{{ $source->isConfigured() ? 'Paste a new key to replace the saved one' : 'Paste the key' }}"
                               class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
                        @error('api_key') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">Save key</button>
                </form>

                {{-- Separate form: a form nested inside the one above is discarded by browsers. --}}
                <form method="POST" action="{{ route('lead-sources.test', $key) }}" class="mt-3">
                    @csrf
                    <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Test connection</button>
                </form>

                <p class="mt-3 text-xs text-gray-500">The key is stored encrypted and is never shown again. Search is free; revealing an email spends the platform's credits.</p>
            </section>
        @endforeach
    </div>
</x-app-layout>
