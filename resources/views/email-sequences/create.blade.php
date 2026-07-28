<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Create Sequence') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <form method="POST" action="{{ route('email-sequences.store') }}" class="space-y-5">
                    @csrf

                    <div>
                        <x-input-label for="name" :value="__('Sequence name')" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" placeholder="{{ __('e.g. Enterprise cold outreach') }}" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="email_account_id" :value="__('Send from account (optional)')" />
                        <select id="email_account_id" name="email_account_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">{{ __('Any active account') }}</option>
                            @foreach($accounts as $account)
                                <option value="{{ $account->id }}" @selected(old('email_account_id') == $account->id)>{{ $account->email }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('email_account_id')" class="mt-2" />
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                        <a href="{{ route('email-sequences.index') }}" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Cancel') }}</a>
                        <button type="submit" class="px-5 py-2 bg-blue-600 text-white text-sm font-semibold rounded-md hover:bg-blue-700">{{ __('Create & Add Steps') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
