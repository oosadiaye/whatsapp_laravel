<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Edit Contact') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <form method="POST" action="{{ route('contacts.update', $contact) }}" class="p-6 space-y-6">
                    @csrf
                    @method('PUT')

                    {{-- Name --}}
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">{{ __('Name') }}</label>
                        <input type="text"
                               id="name"
                               name="name"
                               value="{{ old('name', $contact->name) }}"
                               class="w-full rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366] sm:text-sm"
                               placeholder="Contact name">
                        @error('name')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Phone --}}
                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">{{ __('Phone') }}</label>
                        <input type="text"
                               id="phone"
                               name="phone"
                               value="{{ old('phone', $contact->phone) }}"
                               readonly
                               class="w-full rounded-md border-gray-300 bg-gray-50 shadow-sm sm:text-sm text-gray-500 font-mono cursor-not-allowed">
                        <p class="mt-1 text-xs text-gray-400">{{ __('Phone number cannot be changed.') }}</p>
                        @error('phone')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Custom Field 1 --}}
                    <div>
                        <label for="custom_field_1" class="block text-sm font-medium text-gray-700 mb-1">{{ __('Custom Field 1') }}</label>
                        <input type="text"
                               id="custom_field_1"
                               name="custom_field_1"
                               value="{{ old('custom_field_1', $contact->custom_field_1) }}"
                               class="w-full rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366] sm:text-sm"
                               placeholder="Optional custom data">
                        @error('custom_field_1')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Custom Field 2 --}}
                    <div>
                        <label for="custom_field_2" class="block text-sm font-medium text-gray-700 mb-1">{{ __('Custom Field 2') }}</label>
                        <input type="text"
                               id="custom_field_2"
                               name="custom_field_2"
                               value="{{ old('custom_field_2', $contact->custom_field_2) }}"
                               class="w-full rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366] sm:text-sm"
                               placeholder="Optional custom data">
                        @error('custom_field_2')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Groups (read-only) --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Groups') }}</label>
                        @if($contact->groups && $contact->groups->count())
                            <div class="flex flex-wrap gap-2">
                                @foreach($contact->groups as $group)
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        {{ $group->name }}
                                    </span>
                                @endforeach
                            </div>
                        @else
                            <p class="text-sm text-gray-400">{{ __('Not assigned to any group.') }}</p>
                        @endif
                    </div>

                    {{-- Actions --}}
                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-200">
                        <a href="{{ route('contacts.index') }}"
                           class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition ease-in-out duration-150">
                            {{ __('Cancel') }}
                        </a>
                        <button type="submit"
                                class="inline-flex items-center px-4 py-2 bg-[#25D366] border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-[#1da851] focus:outline-none focus:ring-2 focus:ring-[#25D366] focus:ring-offset-2 transition ease-in-out duration-150">
                            {{ __('Save Changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
