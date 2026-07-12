{{-- Live chat thread (WhatsApp-style bubbles). Polls so inbound replies
     appear without a manual refresh. --}}
<div class="bg-[#e5ddd5] rounded-xl p-4 space-y-3" style="min-height: 60vh;" wire:poll.5s>
    @forelse($timeline as $item)
        @if($item instanceof \App\Models\CallLog)
            @include('conversations._call_card', ['call' => $item])
        @else
            @php /** @var \App\Models\ConversationMessage $message */ $message = $item; @endphp
            <div class="flex {{ $message->isInbound() ? 'justify-start' : 'justify-end' }}">
                <div class="max-w-md rounded-lg p-3 shadow {{ $message->isInbound() ? 'bg-white' : 'bg-[#dcf8c6]' }}">

                    {{-- Media --}}
                    @if($message->hasMedia())
                        @php $mime = $message->media_mime ?? ''; $mediaUrl = $message->displayMediaUrl(); @endphp
                        @if(str_starts_with($mime, 'image/'))
                            <a href="{{ $mediaUrl }}" target="_blank" class="block mb-2">
                                <img src="{{ $mediaUrl }}" alt="" class="rounded max-w-full max-h-64">
                            </a>
                        @elseif(str_starts_with($mime, 'audio/'))
                            <audio controls class="w-full mb-2">
                                <source src="{{ $mediaUrl }}" type="{{ $mime }}">
                            </audio>
                        @elseif(str_starts_with($mime, 'video/'))
                            <video controls class="w-full max-h-64 mb-2 rounded">
                                <source src="{{ $mediaUrl }}" type="{{ $mime }}">
                            </video>
                        @else
                            <a href="{{ $mediaUrl }}" target="_blank"
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

                    {{-- Footer (timestamp + sender + delivery ticks for outbound) --}}
                    <div class="flex items-center justify-end gap-1 mt-1 text-xs text-gray-500">
                        @if(! $message->isInbound() && $message->sentBy)
                            <span class="text-gray-400">{{ $message->sentBy->name }} ·</span>
                        @endif
                        <span>{{ $message->created_at->format('H:i') }}</span>
                        @if(! $message->isInbound())
                            @php $st = strtoupper((string) ($message->status ?? 'SENT')); @endphp
                            @if($st === 'FAILED')
                                <span class="text-red-500 font-bold" title="{{ __('Failed to send') }}">!</span>
                            @elseif($st === 'READ')
                                <span class="text-sky-500 font-semibold" title="{{ __('Read') }}">✓✓</span>
                            @elseif($st === 'DELIVERED')
                                <span class="text-gray-400 font-semibold" title="{{ __('Delivered') }}">✓✓</span>
                            @else
                                <span class="text-gray-400 font-semibold" title="{{ __('Sent') }}">✓</span>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        @endif
    @empty
        <p class="text-center text-gray-500 text-sm py-12">{{ __('No messages or calls yet.') }}</p>
    @endforelse
</div>
