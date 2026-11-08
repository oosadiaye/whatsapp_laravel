<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Team access') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <p class="text-sm text-gray-600">
                {{ __('Choose who works in a workspace of their own. A restricted person sees only the contacts, campaigns, templates, email campaigns, sequences, boards and leads they created. Everyone\'s mailbox is private to them either way.') }}
            </p>

            <div class="bg-white shadow-sm rounded-lg overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Person') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Role') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Sees') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($people as $person)
                            <tr>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    {{ $person->name }}
                                    <span class="block text-xs text-gray-500">{{ $person->email }}</span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700">{{ $person->roles->first()?->name }}</td>
                                <td class="px-6 py-4 text-sm">
                                    @if($person->restrict_to_own)
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-sky-100 text-sky-800">{{ __('Only their own') }}</span>
                                    @else
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">{{ __('Everyone\'s') }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <form method="POST" action="{{ route('team-access.update', $person) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="restrict_to_own" value="{{ $person->restrict_to_own ? 0 : 1 }}">
                                        <button type="submit" class="text-sm font-medium text-brand hover:text-brand-hover">
                                            {{ $person->restrict_to_own ? __('Lift restriction') : __('Restrict to own') }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-6 py-8 text-center text-sm text-gray-500">{{ __('No users or agents yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
