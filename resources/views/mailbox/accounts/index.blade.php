<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Mailbox — Connected Accounts') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm rounded-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-sm text-gray-600">{{ __('Connect your email account to read and reply from here.') }}</p>
                    <a href="{{ route('mailbox.accounts.create') }}" class="px-4 py-2 bg-[#25D366] text-white text-sm font-semibold rounded-md hover:bg-[#1da851]">{{ __('Connect account') }}</a>
                </div>

                @if($accounts->isEmpty())
                    <p class="text-sm text-gray-500 py-8 text-center">{{ __('No accounts connected yet.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-gray-500">
                                    <th class="py-2 pr-4 font-medium">{{ __('Email') }}</th>
                                    <th class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                                    <th class="py-2 pr-4 font-medium">{{ __('Last synced') }}</th>
                                    <th class="py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($accounts as $account)
                                    <tr>
                                        <td class="py-2 pr-4">
                                            <div class="font-medium text-gray-800">{{ $account->email }}</div>
                                            @can('mailbox.view_all')
                                                <div class="text-xs text-gray-400">{{ $account->user?->name }}</div>
                                            @endcan
                                        </td>
                                        <td class="py-2 pr-4">
                                            @if($account->needs_reauth)
                                                <span class="inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-xs text-red-700">{{ __('Needs reconnect') }}</span>
                                            @elseif($account->is_active)
                                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">{{ __('Connected') }}</span>
                                            @else
                                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ __('Inactive') }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 text-gray-500">{{ $account->last_synced_at?->diffForHumans() ?? '—' }}</td>
                                        <td class="py-2 text-right">
                                            <form method="POST" action="{{ route('mailbox.accounts.destroy', $account) }}" data-confirm="{{ __('Disconnect this mailbox?') }}" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-red-600 hover:text-red-800 text-xs font-medium">{{ __('Disconnect') }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
