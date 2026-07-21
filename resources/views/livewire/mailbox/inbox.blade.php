<div wire:key="mailbox-inbox">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- Left: thread list --}}
        <div class="lg:col-span-1 bg-white shadow-sm rounded-lg p-4">
            <div class="flex gap-2 mb-3 text-xs">
                @foreach(['inbox' => __('Inbox'), 'sent' => __('Sent'), 'archive' => __('Archive')] as $key => $label)
                    <button type="button" wire:click="$set('folder', '{{ $key }}')"
                        class="px-2 py-1 rounded {{ $folder === $key ? 'bg-[#25D366] text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">{{ $label }}</button>
                @endforeach
            </div>

            <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search mail…') }}"
                class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366] mb-3" />

            <ul class="divide-y divide-gray-100">
                @forelse($threads as $thread)
                    <li>
                        <button type="button" wire:click="selectThread({{ $thread->id }})"
                            class="w-full text-left py-2 px-1 rounded hover:bg-gray-50 {{ $selectedThreadId === $thread->id ? 'bg-gray-50' : '' }}">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm truncate {{ $thread->unread_count > 0 ? 'font-semibold text-gray-900' : 'text-gray-700' }}">{{ $thread->subject ?: __('(no subject)') }}</span>
                                @if($thread->unread_count > 0)
                                    <span class="shrink-0 inline-flex items-center justify-center rounded-full bg-[#25D366] text-white text-[10px] w-4 h-4">{{ $thread->unread_count }}</span>
                                @endif
                            </div>
                            <div class="text-xs text-gray-400">{{ $thread->last_message_at?->diffForHumans() }}</div>
                        </button>
                    </li>
                @empty
                    <li class="py-8 text-center text-sm text-gray-400">{{ __('No mail.') }}</li>
                @endforelse
            </ul>

            <div class="mt-3">{{ $threads->links() }}</div>
        </div>

        {{-- Right: selected thread --}}
        <div class="lg:col-span-2 bg-white shadow-sm rounded-lg p-4">
            @if($selected)
                <h3 class="text-lg font-semibold text-gray-800 mb-4 break-words">{{ $selected->subject ?: __('(no subject)') }}</h3>

                <div class="space-y-4">
                    @foreach($selected->messages as $message)
                        <div class="border border-gray-100 rounded-md p-3">
                            <div class="flex items-center justify-between text-xs text-gray-500 mb-2 gap-2">
                                <span class="font-medium text-gray-700 truncate">{{ $message->from_email }}</span>
                                <span class="shrink-0">{{ ($message->received_at ?? $message->sent_at)?->format('M j, Y g:i A') }}</span>
                            </div>

                            {{-- Untrusted inbound HTML — sandboxed (no scripts/same-origin). --}}
                            @if($message->body_html)
                                <iframe sandbox class="w-full min-h-[120px] border-0" srcdoc="{{ $message->body_html }}" title="{{ __('Message body') }}"></iframe>
                            @else
                                <pre class="whitespace-pre-wrap text-sm text-gray-700 font-sans">{{ $message->body_text }}</pre>
                            @endif

                            @if($message->attachments->isNotEmpty())
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach($message->attachments as $att)
                                        <span class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">&#128206; {{ $att->filename }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- B5 SEAM: reply / forward composer + per-message actions (and
                     attachment downloads) render here in step B5. No such control
                     is shown until then — no dead UI. --}}
            @else
                <div class="py-16 text-center text-sm text-gray-400">{{ __('Select a conversation to read.') }}</div>
            @endif
        </div>
    </div>
</div>
