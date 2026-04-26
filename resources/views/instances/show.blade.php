<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ $instance->display_name ?? $instance->instance_name }}
                </h2>
                @if($instance->isCloud())
                    <span class="inline-flex items-center text-[10px] uppercase tracking-wide bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full font-semibold">
                        Cloud API
                    </span>
                @else
                    <span class="inline-flex items-center text-[10px] uppercase tracking-wide bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-semibold">
                        Evolution
                    </span>
                @endif
            </div>
            <a href="{{ route('instances.index') }}"
               class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                {{ __('Back to Instances') }}
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Instance Details --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('Instance Details') }}</h3>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">{{ __('Internal Name') }}</dt>
                            <dd class="mt-1 text-sm text-gray-900 font-mono">{{ $instance->instance_name }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">{{ __('Display Name') }}</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $instance->display_name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">{{ __('Phone Number') }}</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ $instance->business_phone_number ?? $instance->phone_number ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">{{ __('Status') }}</dt>
                            <dd class="mt-1">
                                @php
                                    $statusClass = match($status) {
                                        'CONNECTED', 'open' => 'bg-green-100 text-green-800',
                                        'DISCONNECTED', 'close' => 'bg-red-100 text-red-800',
                                        'QR_REQUIRED', 'connecting' => 'bg-yellow-100 text-yellow-800',
                                        'CREDENTIALS_INVALID', 'UNREACHABLE' => 'bg-red-100 text-red-800',
                                        'PENDING_VERIFICATION' => 'bg-blue-100 text-blue-800',
                                        default => 'bg-gray-100 text-gray-800',
                                    };
                                @endphp
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $statusClass }}">
                                    {{ $status }}
                                </span>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            @if($instance->isCloud())
                {{-- Cloud API metadata --}}
                <div class="bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('Cloud API Health') }}</h3>
                        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">{{ __('Quality Rating') }}</dt>
                                <dd class="mt-1">
                                    @php
                                        $qualityClass = match(strtoupper((string) $instance->quality_rating)) {
                                            'GREEN' => 'bg-emerald-100 text-emerald-800',
                                            'YELLOW' => 'bg-amber-100 text-amber-800',
                                            'RED' => 'bg-red-100 text-red-800',
                                            default => 'bg-gray-100 text-gray-700',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold {{ $qualityClass }}">
                                        {{ $instance->quality_rating ?? 'UNKNOWN' }}
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">{{ __('Messaging Tier') }}</dt>
                                <dd class="mt-1 text-sm text-gray-900 font-mono">{{ $instance->messaging_limit_tier ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">{{ __('WABA ID') }}</dt>
                                <dd class="mt-1 text-sm text-gray-900 font-mono truncate" title="{{ $instance->waba_id }}">
                                    {{ $instance->waba_id ?? '—' }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>

                {{-- Webhook setup helper --}}
                <div class="bg-white shadow-sm sm:rounded-lg" x-data="{ copied: '' }">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-2">{{ __('Webhook configuration') }}</h3>
                        <p class="text-sm text-gray-600 mb-4">
                            {{ __('Paste these into your Meta App dashboard → WhatsApp → Configuration → Webhooks.') }}
                        </p>

                        <div class="space-y-3">
                            <div>
                                <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Callback URL') }}</label>
                                <div class="mt-1 flex">
                                    <input type="text" readonly value="{{ $cloudWebhookUrl }}"
                                           class="flex-1 font-mono text-xs border-gray-300 rounded-l-md bg-gray-50 focus:ring-0 focus:border-gray-300" />
                                    <button type="button"
                                            @click="navigator.clipboard.writeText('{{ $cloudWebhookUrl }}'); copied = 'url'; setTimeout(() => copied = '', 1500)"
                                            class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 border border-l-0 border-gray-300 rounded-r-md text-xs font-medium">
                                        <span x-show="copied !== 'url'">{{ __('Copy') }}</span>
                                        <span x-show="copied === 'url'" x-cloak class="text-emerald-600">{{ __('Copied!') }}</span>
                                    </button>
                                </div>
                            </div>

                            <div>
                                <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Verify Token') }}</label>
                                <div class="mt-1 flex">
                                    <input type="text" readonly value="{{ $instance->webhook_verify_token }}"
                                           class="flex-1 font-mono text-xs border-gray-300 rounded-l-md bg-gray-50 focus:ring-0 focus:border-gray-300" />
                                    <button type="button"
                                            @click="navigator.clipboard.writeText('{{ $instance->webhook_verify_token }}'); copied = 'token'; setTimeout(() => copied = '', 1500)"
                                            class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 border border-l-0 border-gray-300 rounded-r-md text-xs font-medium">
                                        <span x-show="copied !== 'token'">{{ __('Copy') }}</span>
                                        <span x-show="copied === 'token'" x-cloak class="text-emerald-600">{{ __('Copied!') }}</span>
                                    </button>
                                </div>
                            </div>

                            <div class="bg-blue-50 border border-blue-200 rounded-md p-3 text-xs text-blue-900">
                                <p class="font-semibold">{{ __('Subscribed fields:') }}</p>
                                <p class="mt-1">{{ __('Tick at least') }} <code class="bg-white px-1 rounded">messages</code> {{ __('in the Meta dashboard so delivery receipts reach this server.') }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                @if($phoneInfo)
                    <details class="bg-white shadow-sm sm:rounded-lg">
                        <summary class="cursor-pointer p-6 text-sm font-medium text-gray-700">
                            {{ __('Raw phone-number metadata from Meta') }}
                        </summary>
                        <pre class="px-6 pb-6 text-xs text-gray-600 font-mono overflow-x-auto">{{ json_encode($phoneInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                @endif
            @endif

            @if($instance->isEvolution() && in_array($status, ['QR_REQUIRED', 'DISCONNECTED', 'close', 'connecting'], true))
                {{-- Evolution-only: QR scan flow --}}
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg"
                     x-data="{
                         qr: null,
                         status: @js($status),
                         polling: null,
                         error: false
                     }"
                     x-init="
                         polling = setInterval(async () => {
                             try {
                                 const res = await fetch('{{ route('instances.qrStatus', $instance) }}');
                                 const data = await res.json();
                                 qr = data.qr_code;
                                 status = data.status;
                                 error = false;
                                 if (status === 'CONNECTED' || status === 'open') clearInterval(polling);
                             } catch (e) {
                                 error = true;
                             }
                         }, 5000)
                     "
                     x-on:beforeunmount.window="clearInterval(polling)">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('Connect WhatsApp') }}</h3>

                        <div x-show="status === 'CONNECTED' || status === 'open'" x-cloak class="text-center py-8">
                            <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-green-100 mb-4">
                                <svg class="h-8 w-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                            </div>
                            <h4 class="text-lg font-medium text-green-800">{{ __('Connected!') }}</h4>
                            <p class="mt-1 text-sm text-gray-500">{{ __('Your WhatsApp instance is active.') }}</p>
                        </div>

                        <div x-show="status !== 'CONNECTED' && status !== 'open'" class="text-center py-6">
                            <template x-if="qr">
                                <div>
                                    <div class="inline-block p-4 bg-white border-2 border-gray-200 rounded-xl shadow-sm">
                                        <img :src="'data:image/png;base64,' + qr" alt="WhatsApp QR Code" class="w-64 h-64" />
                                    </div>
                                    <p class="mt-4 text-sm text-gray-600">{{ __('Scan with WhatsApp → Settings → Linked Devices.') }}</p>
                                </div>
                            </template>
                            <template x-if="!qr && !error">
                                <div class="py-8 inline-flex items-center gap-2 text-sm text-gray-500">
                                    <svg class="animate-spin h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                    </svg>
                                    {{ __('Waiting for QR code...') }}
                                </div>
                            </template>
                            <template x-if="error">
                                <p class="py-8 text-sm text-red-600">{{ __('Failed to fetch QR code. Retrying...') }}</p>
                            </template>
                            <p class="mt-3 text-xs text-gray-400">{{ __('Polling every 5 seconds') }}</p>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Danger zone --}}
            <div class="bg-white shadow-sm sm:rounded-lg border border-red-100">
                <div class="p-6">
                    <h3 class="text-sm font-medium text-red-700 mb-2">{{ __('Danger zone') }}</h3>
                    <form method="POST" action="{{ route('instances.destroy', $instance) }}"
                          onsubmit="return confirm('{{ __('Delete this instance? Campaigns using it will lose their connection.') }}')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm text-red-600 hover:text-red-800 font-medium">
                            {{ __('Delete instance') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
