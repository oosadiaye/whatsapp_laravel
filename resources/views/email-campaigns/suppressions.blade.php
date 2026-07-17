<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Suppression list') }}</h2>
            <a href="{{ route('email-campaigns.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; {{ __('Email campaigns') }}</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @if(session('success'))
            <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif

        <p class="text-sm text-gray-500">{{ __('Addresses here are never emailed. Unsubscribes are added automatically; add bounces or complaints manually.') }}</p>

        @can('email.edit')
            <form method="POST" action="{{ route('email-suppressions.store') }}" class="bg-white border border-gray-200 rounded-xl shadow-sm p-4 flex flex-wrap items-end gap-3">
                @csrf
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('Email') }}</label>
                    <input type="email" name="email" required value="{{ old('email') }}" placeholder="name@example.com"
                           class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('Reason') }}</label>
                    <select name="reason" class="rounded-lg border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="manual">{{ __('Manual') }}</option>
                        <option value="bounce">{{ __('Bounce') }}</option>
                        <option value="complaint">{{ __('Complaint') }}</option>
                        <option value="unsubscribe">{{ __('Unsubscribe') }}</option>
                    </select>
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-[#4f46e5] text-white text-sm font-semibold hover:bg-[#4338ca]">{{ __('Suppress') }}</button>
            </form>
        @endcan

        <form method="GET" action="{{ route('email-suppressions.index') }}">
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('Search addresses…') }}"
                   class="block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
        </form>

        <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
            <div class="divide-y divide-gray-50">
                @forelse($suppressions as $s)
                    <div class="flex items-center gap-3 px-5 py-3">
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium text-gray-900 truncate">{{ $s->email }}</div>
                            <div class="text-xs text-gray-400">{{ ucfirst($s->reason) }} · {{ $s->created_at?->diffForHumans() }}</div>
                        </div>
                        @can('email.edit')
                            <form method="POST" action="{{ route('email-suppressions.destroy', $s) }}" data-confirm="Remove {{ $s->email }} from the suppression list? They may be emailed again.">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs font-semibold text-red-600 hover:underline">{{ __('Remove') }}</button>
                            </form>
                        @endcan
                    </div>
                @empty
                    <div class="px-5 py-12 text-center text-sm text-gray-400">{{ __('No suppressed addresses.') }}</div>
                @endforelse
            </div>
            @if($suppressions->hasPages())
                <div class="px-5 py-4 border-t border-gray-100">{{ $suppressions->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
