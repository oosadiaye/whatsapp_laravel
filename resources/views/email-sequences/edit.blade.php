<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Edit Sequence') }}: {{ $sequence->name }}</h2>
            <span class="text-xs px-2 py-0.5 rounded-full font-medium
                @switch($sequence->status)
                    @case('active') bg-green-100 text-green-800 @break
                    @case('draft') bg-gray-100 text-gray-600 @break
                    @case('paused') bg-amber-100 text-amber-800 @break
                    @case('completed') bg-blue-100 text-blue-800 @break
                    @default bg-gray-100 text-gray-600
                @endswitch">{{ __(ucfirst($sequence->status)) }}</span>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Settings --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-sm font-bold text-gray-700 mb-4">{{ __('Settings') }}</h3>
                <form method="POST" action="{{ route('email-sequences.update', $sequence) }}" class="space-y-4">
                    @csrf @method('PUT')
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="name" :value="__('Name')" />
                            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $sequence->name)" required />
                        </div>
                        <div>
                            <x-input-label for="email_account_id" :value="__('Send from account')" />
                            <select id="email_account_id" name="email_account_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">{{ __('Any active account') }}</option>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}" @selected(($sequence->email_account_id ?? '') == $account->id)>{{ $account->email }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="px-4 py-2 text-sm font-semibold bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition">{{ __('Save') }}</button>
                    </div>
                </form>
            </div>

            {{-- Steps --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-sm font-bold text-gray-700 mb-4">{{ __('Steps') }}</h3>

                @if($sequence->steps->isEmpty())
                    <p class="text-sm text-gray-400 mb-4">{{ __('No steps yet. Add your first email step below.') }}</p>
                @else
                    <div class="space-y-3 mb-6">
                        @foreach($sequence->steps as $index => $step)
                            <div class="border border-gray-200 rounded-lg p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs font-bold text-gray-500 uppercase tracking-wide">{{ __('Step') }} {{ $index + 1 }}</span>
                                    <form method="POST" action="{{ route('email-sequences.steps.destroy', [$sequence, $step]) }}" data-confirm="{{ __('Delete this step?') }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs text-red-500 hover:text-red-700">{{ __('Remove') }}</button>
                                    </form>
                                </div>

                                <form method="POST" action="{{ route('email-sequences.steps.update', [$sequence, $step]) }}" class="space-y-3">
                                    @csrf @method('PUT')

                                    <div>
                                        <input type="text" name="subject" value="{{ old('subject', $step->subject) }}" placeholder="{{ __('Subject') }}"
                                               class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required />
                                    </div>

                                    <div>
                                        <textarea name="body_text" rows="4" placeholder="{{ __('Email body (plain text)') }}"
                                                  class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('body_text', $step->body_text) }}</textarea>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <x-input-label for="delay_days_{{ $step->id }}" :value="__('Delay after previous (days)')" />
                                            <input id="delay_days_{{ $step->id }}" name="delay_days" type="number" min="0" max="365" value="{{ old('delay_days', $step->delay_days) }}"
                                                   class="mt-1 block w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                        </div>
                                        <div>
                                            <x-input-label for="delay_hours_{{ $step->id }}" :value="__('Delay (hours)')" />
                                            <input id="delay_hours_{{ $step->id }}" name="delay_hours" type="number" min="0" max="72" value="{{ old('delay_hours', $step->delay_hours) }}"
                                                   class="mt-1 block w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                        </div>
                                    </div>

                                    <div class="flex justify-end">
                                        <button type="submit" class="px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">{{ __('Update') }}</button>
                                    </div>
                                </form>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Add new step form --}}
                <div class="border-t border-gray-100 pt-4">
                    <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ __('Add Step') }}</h4>
                    <form method="POST" action="{{ route('email-sequences.steps.store', $sequence) }}" class="space-y-3">
                        @csrf
                        <div>
                            <input type="text" name="subject" placeholder="{{ __('Subject') }}"
                                   class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required />
                        </div>
                        <div>
                            <textarea name="body_text" rows="4" placeholder="{{ __('Email body (plain text)') }}"
                                      class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <x-input-label for="delay_days" :value="__('Delay (days)')" />
                                <input id="delay_days" name="delay_days" type="number" min="0" max="365" value="0"
                                       class="mt-1 block w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            </div>
                            <div>
                                <x-input-label for="delay_hours" :value="__('Delay (hours)')" />
                                <input id="delay_hours" name="delay_hours" type="number" min="0" max="72" value="0"
                                       class="mt-1 block w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            </div>
                        </div>
                        <p class="text-xs text-gray-400">{{ __('Delay = time to wait before this step sends after the previous one. Step 1 sends immediately when the sequence runs.') }}</p>
                        <div class="flex justify-end">
                            <button type="submit" class="px-4 py-2 text-sm font-semibold bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">{{ __('Add Step') }}</button>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Recipients / enrolment --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-bold text-gray-700">{{ __('Recipients') }}</h3>
                    <span class="text-xs font-semibold text-gray-500">{{ number_format($sequence->recipients_count) }} {{ __('enrolled') }}</span>
                </div>
                <p class="text-xs text-gray-400 mb-4">{{ __('Enrol contacts who have an email address. They start at step 1 and progress through the delays once the sequence is active. Enrolling again only adds new contacts — no duplicates.') }}</p>
                <form method="POST" action="{{ route('email-sequences.enroll', $sequence) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <div class="flex-1 min-w-[200px]">
                        <x-input-label for="group_id" :value="__('Audience')" />
                        <select id="group_id" name="group_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm">
                            <option value="">{{ __('All contacts with an email') }}</option>
                            @foreach($groups as $group)
                                <option value="{{ $group->id }}">{{ $group->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="px-4 py-2 text-sm font-semibold bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition">{{ __('Enrol contacts') }}</button>
                </form>
            </div>

            {{-- Danger zone --}}
            @if(in_array($sequence->status, ['draft', 'paused']))
                <div class="bg-white rounded-xl shadow-sm border border-red-200 p-6">
                    <h3 class="text-sm font-bold text-red-700 mb-2">{{ __('Danger Zone') }}</h3>
                    <p class="text-xs text-gray-500 mb-3">{{ __('Once launched, recipients progress through steps automatically based on delays.') }}</p>
                    <div class="flex gap-3">
                        <form method="POST" action="{{ route('email-sequences.launch', $sequence) }}">
                            @csrf
                            <button type="submit" class="px-4 py-2 text-sm font-semibold bg-green-600 text-white rounded-lg hover:bg-green-700 transition">{{ __('Launch Sequence') }}</button>
                        </form>
                        <form method="POST" action="{{ route('email-sequences.cancel', $sequence) }}" data-confirm="{{ __('Cancel this sequence? In-flight sends will stop.') }}">
                            @csrf
                            <button type="submit" class="px-4 py-2 text-sm font-semibold bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition">{{ __('Cancel') }}</button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
