<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('conversations.index') }}" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </a>
                <div>
                    <h2 class="font-semibold text-lg text-gray-800 leading-tight">
                        {{ $conversation->contact->name ?? $conversation->contact->phone }}
                    </h2>
                    <p class="text-xs text-gray-500 flex items-center gap-2">
                        <span>{{ $conversation->contact->phone }} · via {{ $conversation->whatsappInstance->instance_name }}</span>

                        @can('conversations.assign')
                            {{-- Assignment dropdown for managers/admins --}}
                            <span class="inline-flex items-center gap-1" x-data="{ open: false }">
                                ·
                                <button type="button"
                                        @click="open = !open"
                                        @click.outside="open = false"
                                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded {{ $conversation->assignedTo ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700' }} hover:opacity-80">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    {{ $conversation->assignedTo ? $conversation->assignedTo->name : __('Unassigned') }}
                                </button>

                                <div x-show="open" x-cloak x-transition
                                     class="absolute mt-6 right-0 z-30 bg-white border border-gray-200 rounded-lg shadow-lg p-2 w-64 max-h-80 overflow-y-auto">
                                    <p class="text-[10px] uppercase tracking-wide text-gray-400 px-2 mb-1">{{ __('Assign to') }}</p>

                                    {{-- Self-assign quick action --}}
                                    @if($conversation->assigned_to_user_id !== auth()->id())
                                        <form method="POST" action="{{ route('conversations.assign', $conversation) }}">
                                            @csrf
                                            <input type="hidden" name="user_id" value="{{ auth()->id() }}">
                                            <button type="submit" class="w-full text-left px-2 py-1.5 rounded hover:bg-gray-50 text-sm font-medium text-[#25D366]">
                                                {{ __('Take this conversation (assign to me)') }}
                                            </button>
                                        </form>
                                        <hr class="my-1">
                                    @endif

                                    {{-- Other staff --}}
                                    @foreach($assignableStaff as $staff)
                                        @if($staff->id !== auth()->id() && $staff->id !== $conversation->assigned_to_user_id)
                                            <form method="POST" action="{{ route('conversations.assign', $conversation) }}">
                                                @csrf
                                                <input type="hidden" name="user_id" value="{{ $staff->id }}">
                                                <button type="submit" class="w-full text-left px-2 py-1.5 rounded hover:bg-gray-50 text-sm text-gray-800">
                                                    {{ $staff->name }} <span class="text-xs text-gray-400">· {{ $staff->email }}</span>
                                                </button>
                                            </form>
                                        @endif
                                    @endforeach

                                    @if($conversation->assignedTo)
                                        <hr class="my-1">
                                        <form method="POST" action="{{ route('conversations.assign', $conversation) }}">
                                            @csrf
                                            <input type="hidden" name="user_id" value="">
                                            <button type="submit" class="w-full text-left px-2 py-1.5 rounded hover:bg-red-50 text-sm text-red-600">
                                                {{ __('Unassign') }}
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </span>
                        @else
                            @if($conversation->assignedTo)
                                · {{ __('Assigned to') }} {{ $conversation->assignedTo->name }}
                            @endif
                        @endcan
                    </p>
                </div>
            </div>

            @can('conversations.call')
                <div x-data="{ open: false }">
                    <button type="button"
                            @click="open = true"
                            class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-emerald-100 text-emerald-700 hover:bg-emerald-200 transition"
                            title="Call {{ $conversation->contact->name }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                        </svg>
                    </button>

                    <template x-teleport="body">
                        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
                             @click.self="open = false">
                            <div class="absolute inset-0 bg-black/50"></div>

                            <div class="relative bg-white rounded-xl shadow-xl max-w-md w-full p-6">
                                <h3 class="text-lg font-semibold text-gray-900 mb-2">Call {{ $conversation->contact->name }}?</h3>
                                <dl class="text-sm space-y-1 mb-4">
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">Number:</dt>
                                        <dd class="text-gray-900 font-mono">{{ $conversation->contact->phone }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">From:</dt>
                                        <dd class="text-gray-900">{{ $conversation->whatsappInstance->display_name ?? $conversation->whatsappInstance->instance_name }}</dd>
                                    </div>
                                </dl>
                                <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2 mb-4">
                                    This will count toward your daily Meta call quota. Audio will ring on the device where this WhatsApp Business number is registered.
                                </p>
                                <div class="flex justify-end gap-2">
                                    <button type="button" @click="open = false"
                                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                        Cancel
                                    </button>
                                    <form method="POST" action="{{ route('conversations.initiateCall', $conversation) }}">
                                        @csrf
                                        <button type="submit"
                                                class="inline-flex items-center px-5 py-2 text-sm font-medium text-white bg-emerald-600 rounded-md hover:bg-emerald-700">
                                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                                            </svg>
                                            Call now
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            @endcan

            {{-- 24h window indicator --}}
            <div class="text-right">
                @if($conversation->isWindowOpen())
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700">
                        <span class="w-1.5 h-1.5 mr-1.5 rounded-full bg-emerald-500"></span>
                        {{ __('Reply window open') }} · {{ $conversation->windowHoursLeft() }}h left
                    </span>
                @else
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-amber-50 text-amber-700">
                        <span class="w-1.5 h-1.5 mr-1.5 rounded-full bg-amber-500"></span>
                        {{ __('Window expired — template required') }}
                    </span>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">

            {{-- Flash messages --}}
            @if(session('error'))
                <div class="rounded-lg bg-red-50 border border-red-200 p-3 text-sm text-red-800">{{ session('error') }}</div>
            @endif

            {{-- Chat thread (WhatsApp-style bubbles) --}}
            <div class="bg-[#e5ddd5] rounded-xl p-4 space-y-3" style="min-height: 60vh;">
                @forelse($timeline as $item)
                    @if($item instanceof \App\Models\CallLog)
                        @include('conversations._call_card', ['call' => $item])
                    @else
                        @php /** @var \App\Models\ConversationMessage $message */ $message = $item; @endphp
                        <div class="flex {{ $message->isInbound() ? 'justify-start' : 'justify-end' }}">
                            <div class="max-w-md rounded-lg p-3 shadow {{ $message->isInbound() ? 'bg-white' : 'bg-[#dcf8c6]' }}">

                                {{-- Media --}}
                                @if($message->hasMedia())
                                    @php $mime = $message->media_mime ?? ''; @endphp
                                    @if(str_starts_with($mime, 'image/'))
                                        <a href="{{ route('conversations.media', $message) }}" target="_blank" class="block mb-2">
                                            <img src="{{ route('conversations.media', $message) }}" alt="" class="rounded max-w-full max-h-64">
                                        </a>
                                    @elseif(str_starts_with($mime, 'audio/'))
                                        <audio controls class="w-full mb-2">
                                            <source src="{{ route('conversations.media', $message) }}" type="{{ $mime }}">
                                        </audio>
                                    @elseif(str_starts_with($mime, 'video/'))
                                        <video controls class="w-full max-h-64 mb-2 rounded">
                                            <source src="{{ route('conversations.media', $message) }}" type="{{ $mime }}">
                                        </video>
                                    @else
                                        <a href="{{ route('conversations.media', $message) }}" target="_blank"
                                           class="flex items-center gap-2 text-sm text-blue-600 hover:underline mb-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                            {{ __('Download') }} ({{ round(($message->media_size_bytes ?? 0) / 1024) }}KB)
                                        </a>
                                    @endif
                                @endif

                                {{-- Body / caption --}}
                                @if($message->body)
                                    <p class="text-sm text-gray-800 whitespace-pre-wrap break-words">{{ $message->body }}</p>
                                @endif

                                {{-- Footer (timestamp + sender for outbound) --}}
                                <div class="flex items-center justify-end gap-1 mt-1 text-xs text-gray-500">
                                    @if(! $message->isInbound() && $message->sentBy)
                                        <span class="text-gray-400">{{ $message->sentBy->name }} ·</span>
                                    @endif
                                    <span>{{ $message->created_at->format('H:i') }}</span>
                                </div>
                            </div>
                        </div>
                    @endif
                @empty
                    <p class="text-center text-gray-500 text-sm py-12">{{ __('No messages or calls yet.') }}</p>
                @endforelse
            </div>

            {{-- Reply form --}}
            @can('conversations.reply')
                <div class="bg-white rounded-xl shadow-sm p-4">
                    @if($conversation->isWindowOpen())
                        {{-- Freeform reply (within 24h window) --}}
                        <form method="POST" action="{{ route('conversations.reply', $conversation) }}" class="space-y-3">
                            @csrf
                            <textarea name="body" rows="3" required
                                      placeholder="{{ __('Type your reply...') }}"
                                      class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]"></textarea>
                            <div class="flex justify-end">
                                <button type="submit"
                                        class="inline-flex items-center gap-2 px-4 py-2 bg-[#25D366] text-white text-sm font-semibold rounded-lg hover:bg-[#1da851]">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                    {{ __('Send') }}
                                </button>
                            </div>
                        </form>
                    @else
                        {{-- Template-only mode (window expired) --}}
                        <div class="space-y-3">
                            <p class="text-sm text-gray-600">
                                {{ __('Window expired. Send an approved template to re-open the conversation:') }}
                            </p>

                            @if($templates->isEmpty())
                                <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded p-3">
                                    {{ __('No approved templates available. Sync templates from the Templates page first.') }}
                                </p>
                            @else
                                <form method="POST" action="{{ route('conversations.reply', $conversation) }}" class="space-y-3">
                                    @csrf
                                    <select name="message_template_id" required
                                            class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                                        <option value="">{{ __('Pick a template...') }}</option>
                                        @foreach($templates as $template)
                                            <option value="{{ $template->id }}">{{ $template->name }} · {{ $template->language }}</option>
                                        @endforeach
                                    </select>
                                    <div class="flex justify-end">
                                        <button type="submit"
                                                class="inline-flex items-center gap-2 px-4 py-2 bg-[#25D366] text-white text-sm font-semibold rounded-lg hover:bg-[#1da851]">
                                            {{ __('Send Template') }}
                                        </button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>
            @endcan
        </div>
    </div>
</x-app-layout>
