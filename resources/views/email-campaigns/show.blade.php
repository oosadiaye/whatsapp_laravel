<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ $campaign->name }}</h2>
            <a href="{{ route('email-campaigns.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; {{ __('All campaigns') }}</a>
        </div>
    </x-slot>

    @php
        $statusMeta = [
            'draft' => ['bg-gray-100 text-gray-600', 'Draft'],
            'scheduled' => ['bg-indigo-100 text-indigo-700', 'Scheduled'],
            'queued' => ['bg-amber-100 text-amber-700', 'Queued'],
            'sending' => ['bg-amber-100 text-amber-700', 'Sending'],
            'sent' => ['bg-emerald-100 text-emerald-700', 'Sent'],
            'failed' => ['bg-red-100 text-red-700', 'Failed'],
            'cancelled' => ['bg-gray-100 text-gray-500', 'Cancelled'],
        ];
        $meta = $statusMeta[$campaign->status] ?? ['bg-gray-100 text-gray-600', ucfirst($campaign->status)];
        $editable = in_array($campaign->status, \App\Models\EmailCampaign::EDITABLE_STATUSES, true);
        $sendable = in_array($campaign->status, ['draft', 'scheduled', 'paused'], true);
    @endphp

    <div class="py-6 max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @if(session('success'))
            <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif

        <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold {{ $meta[0] }}">{{ $meta[1] }}</span>
                    <h3 class="mt-2 text-lg font-bold text-gray-900">{{ $campaign->subject }}</h3>
                    <p class="text-sm text-gray-500">
                        {{ __('From') }} {{ $campaign->from_name ?: config('mail.from.name') }}
                        @if($campaign->reply_to) · {{ __('reply-to') }} {{ $campaign->reply_to }} @endif
                    </p>
                    @if($campaign->status === 'scheduled' && $campaign->scheduled_at)
                        <p class="mt-1 text-sm text-indigo-600">{{ __('Scheduled for') }} {{ $campaign->scheduled_at->format('M j, Y g:i A') }}@if($campaign->isRecurring()) · {{ __('repeats') }} {{ $campaign->recurrence }}@endif</p>
                    @endif
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    @can('email.edit')
                        @if($editable)<a href="{{ route('email-campaigns.edit', $campaign) }}" class="px-3 py-1.5 text-sm rounded-lg border border-gray-300 hover:bg-gray-50">{{ __('Edit') }}</a>@endif
                    @endcan
                    @can('email.send')
                        @if($sendable)
                            <form method="POST" action="{{ route('email-campaigns.launch', $campaign) }}" onsubmit="return confirm('Send this campaign now to {{ $recipientCount }} recipients?')">
                                @csrf
                                <button type="submit" class="px-4 py-1.5 text-sm rounded-lg bg-[#4f46e5] text-white font-semibold hover:bg-[#4338ca]">{{ __('Send now') }}</button>
                            </form>
                        @endif
                        @if(in_array($campaign->status, ['scheduled','queued','sending']))
                            <form method="POST" action="{{ route('email-campaigns.cancel', $campaign) }}" onsubmit="return confirm('Cancel this campaign?')">
                                @csrf
                                <button type="submit" class="px-3 py-1.5 text-sm rounded-lg border border-red-200 text-red-600 hover:bg-red-50">{{ __('Cancel') }}</button>
                            </form>
                        @endif
                    @endcan
                </div>
            </div>
        </div>

        {{-- Stats --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            @foreach ([
                ['label' => __('Recipients'), 'value' => $campaign->status === 'draft' || $campaign->status === 'scheduled' ? $recipientCount : $campaign->total_recipients],
                ['label' => __('Sent'), 'value' => $campaign->sent_count, 'accent' => 'text-emerald-600'],
                ['label' => __('Failed'), 'value' => $campaign->failed_count, 'accent' => $campaign->failed_count > 0 ? 'text-red-600' : 'text-gray-900'],
                ['label' => __('Groups'), 'value' => $campaign->contactGroups->count()],
            ] as $tile)
                <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-4">
                    <div class="text-2xl font-bold {{ $tile['accent'] ?? 'text-gray-900' }} tabular-nums">{{ $tile['value'] }}</div>
                    <div class="text-[11px] uppercase tracking-wide text-gray-400 mt-0.5">{{ $tile['label'] }}</div>
                </div>
            @endforeach
        </div>

        {{-- Preview. The body is operator-authored HTML; render it in a sandboxed
             iframe (no scripts, no same-origin) so it can't touch this page. --}}
        <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100 text-sm font-bold text-gray-700">{{ __('Preview') }}</div>
            <iframe sandbox title="{{ __('Email preview') }}" class="w-full min-h-[320px] border-0 bg-white"
                    srcdoc="{{ $campaign->body_html }}"></iframe>
        </div>
    </div>
</x-app-layout>
