<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $instance->name }}
            </h2>
            <a href="{{ route('instances.index') }}"
               class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to Instances
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('success'))
                <div class="rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">
                    {{ session('success') }}
                </div>
            @endif

            {{-- Instance Details --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Instance Details</h3>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Name</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $instance->name }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Display Name</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $instance->display_name ?? '---' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Phone</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $instance->phone ?? '---' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Status</dt>
                            <dd class="mt-1">
                                @if ($instance->status === 'CONNECTED')
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        <span class="w-1.5 h-1.5 mr-1.5 rounded-full bg-green-500"></span>
                                        Connected
                                    </span>
                                @elseif ($instance->status === 'DISCONNECTED')
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                        <span class="w-1.5 h-1.5 mr-1.5 rounded-full bg-red-500"></span>
                                        Disconnected
                                    </span>
                                @elseif ($instance->status === 'QR_REQUIRED')
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                        <span class="w-1.5 h-1.5 mr-1.5 rounded-full bg-yellow-500"></span>
                                        QR Required
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                        {{ $instance->status }}
                                    </span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            {{-- QR Code Section --}}
            @if (in_array($instance->status, ['QR_REQUIRED', 'DISCONNECTED']))
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg"
                     x-data="{
                         qr: null,
                         status: '{{ $instance->status }}',
                         polling: null,
                         error: false
                     }"
                     x-init="
                         polling = setInterval(async () => {
                             try {
                                 const res = await fetch('{{ route('instances.qrStatus', $instance) }}');
                                 const data = await res.json();
                                 qr = data.qr;
                                 status = data.status;
                                 error = false;
                                 if (status === 'CONNECTED') clearInterval(polling);
                             } catch (e) {
                                 error = true;
                             }
                         }, 5000)
                     "
                     x-on:beforeunmount.window="clearInterval(polling)">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Connect WhatsApp</h3>

                        {{-- Connected State --}}
                        <div x-show="status === 'CONNECTED'" x-cloak class="text-center py-8">
                            <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-green-100 mb-4">
                                <svg class="h-8 w-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                            </div>
                            <h4 class="text-lg font-medium text-green-800">Connected!</h4>
                            <p class="mt-1 text-sm text-gray-500">Your WhatsApp instance is now active and ready to send messages.</p>
                        </div>

                        {{-- QR Code Display --}}
                        <div x-show="status !== 'CONNECTED'" class="text-center py-6">
                            <template x-if="qr">
                                <div>
                                    <div class="inline-block p-4 bg-white border-2 border-gray-200 rounded-xl shadow-sm">
                                        <img :src="'data:image/png;base64,' + qr"
                                             alt="WhatsApp QR Code"
                                             class="w-64 h-64" />
                                    </div>
                                    <p class="mt-4 text-sm text-gray-600">
                                        Scan this QR code with WhatsApp on your phone to connect.
                                    </p>
                                </div>
                            </template>

                            <template x-if="!qr && !error">
                                <div class="py-8">
                                    <div class="inline-flex items-center gap-2 text-sm text-gray-500">
                                        <svg class="animate-spin h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                        </svg>
                                        Waiting for QR code...
                                    </div>
                                </div>
                            </template>

                            <template x-if="error">
                                <div class="py-8">
                                    <p class="text-sm text-red-600">Failed to fetch QR code. Retrying...</p>
                                </div>
                            </template>

                            <p class="mt-3 text-xs text-gray-400">Polling every 5 seconds</p>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
