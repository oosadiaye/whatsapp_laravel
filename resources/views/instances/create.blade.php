<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Connect New Instance') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <form method="POST" action="{{ route('instances.store') }}" class="space-y-6">
                        @csrf

                        <div>
                            <x-input-label for="instance_name" :value="__('Instance Name')" />
                            <x-text-input id="instance_name"
                                          name="instance_name"
                                          type="text"
                                          class="mt-1 block w-full"
                                          :value="old('instance_name')"
                                          required
                                          autofocus
                                          placeholder="e.g. main_business_line" />
                            <x-input-error :messages="$errors->get('instance_name')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="display_name" :value="__('Display Name')" />
                            <x-text-input id="display_name"
                                          name="display_name"
                                          type="text"
                                          class="mt-1 block w-full"
                                          :value="old('display_name')"
                                          placeholder="e.g. Customer Support" />
                            <x-input-error :messages="$errors->get('display_name')" class="mt-2" />
                        </div>

                        <div class="flex items-center justify-end gap-4 pt-4 border-t border-gray-100">
                            <a href="{{ route('instances.index') }}"
                               class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                                Cancel
                            </a>
                            <button type="submit"
                                    class="inline-flex items-center px-5 py-2 text-sm font-medium text-white rounded-lg shadow-sm hover:opacity-90 transition"
                                    style="background-color: #25D366;">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                </svg>
                                Create & Connect
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
