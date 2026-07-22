<div wire:key="mailbox-inbox">
    @if(session('mailbox_status'))
        <div class="mb-3 rounded-md bg-green-50 border border-green-200 px-3 py-2 text-sm text-green-800">
            {{ session('mailbox_status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- Left: thread list --}}
        <div class="lg:col-span-1 bg-white shadow-sm rounded-lg p-4">
            <div class="flex items-center justify-between mb-3">
                <div class="flex gap-2 text-xs">
                    @foreach(['inbox' => __('Inbox'), 'sent' => __('Sent'), 'archive' => __('Archive')] as $key => $label)
                        <button type="button" wire:click="$set('folder', '{{ $key }}')"
                            class="px-2 py-1 rounded {{ $folder === $key ? 'bg-[#25D366] text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">{{ $label }}</button>
                    @endforeach
                </div>
                @if($myAccounts->isNotEmpty())
                    <button type="button" wire:click="startCompose"
                        class="text-xs font-medium px-2 py-1 rounded bg-gray-800 text-white hover:bg-gray-700">{{ __('Compose') }}</button>
                @endif
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

        {{-- Right: selected thread + composer --}}
        <div class="lg:col-span-2 bg-white shadow-sm rounded-lg p-4">
            @if($selected)
                <div class="flex items-center justify-between mb-4 gap-2">
                    <h3 class="text-lg font-semibold text-gray-800 break-words">{{ $selected->subject ?: __('(no subject)') }}</h3>
                    @if($canSendFromSelected && $selected->messages->isNotEmpty())
                        <button type="button" wire:click="startReply({{ $selected->messages->last()->id }})"
                            class="shrink-0 text-xs font-medium px-2 py-1 rounded bg-[#25D366] text-white hover:bg-[#1da851]">{{ __('Reply') }}</button>
                    @endif
                </div>

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
                                        <a href="{{ route('mailbox.attachments.download', $att) }}"
                                            class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600 hover:bg-gray-200">
                                            &#128206; {{ $att->filename }}
                                        </a>
                                    @endforeach
                                </div>
                            @endif

                            @if($canSendFromSelected)
                                <div class="mt-2 flex gap-3 text-xs">
                                    <button type="button" wire:click="startReply({{ $message->id }})" class="text-gray-500 hover:text-gray-800">{{ __('Reply') }}</button>
                                    <button type="button" wire:click="startReply({{ $message->id }}, true)" class="text-gray-500 hover:text-gray-800">{{ __('Reply all') }}</button>
                                    <button type="button" wire:click="startForward({{ $message->id }})" class="text-gray-500 hover:text-gray-800">{{ __('Forward') }}</button>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @elseif(! $composing)
                <div class="py-16 text-center text-sm text-gray-400">{{ __('Select a conversation to read.') }}</div>
            @endif

            {{-- B5b composer — rendered into the B4 seam. Only reachable when the
                 user owns a sending identity (Compose) or owns the thread's account
                 (reply/forward buttons are gated by $canSendFromSelected). --}}
            @if($composing)
                <form wire:submit.prevent="send" class="mt-4 border-t border-gray-100 pt-4 space-y-3">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">
                        {{ ['reply' => __('Reply'), 'reply_all' => __('Reply all'), 'forward' => __('Forward'), 'new' => __('New message')][$composeMode] ?? __('Message') }}
                    </div>

                    @if($composeMode === 'new' && $myAccounts->count() > 1)
                        <select wire:model="composeAccountId" class="w-full text-sm rounded-md border-gray-300 shadow-sm">
                            @foreach($myAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->email }}</option>
                            @endforeach
                        </select>
                    @endif

                    <div>
                        <input type="text" wire:model="composeTo" placeholder="{{ __('To') }}"
                            class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]" />
                        @error('composeTo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <input type="text" wire:model="composeCc" placeholder="{{ __('Cc (optional)') }}"
                        class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]" />

                    <div>
                        <input type="text" wire:model="composeSubject" placeholder="{{ __('Subject') }}"
                            class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]" />
                        @error('composeSubject') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <textarea wire:model="composeBody" rows="6" placeholder="{{ __('Write your message…') }}"
                        class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]"></textarea>

                    <div>
                        <input type="file" wire:model="composeFiles" multiple class="text-xs text-gray-600" />
                        <span wire:loading wire:target="composeFiles" class="ml-2 text-xs text-gray-400">{{ __('Uploading…') }}</span>
                        @error('composeFiles.*') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @if(! empty($composeFiles))
                            <div class="mt-1 flex flex-wrap gap-2">
                                @foreach($composeFiles as $file)
                                    <span class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $file->getClientOriginalName() }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="submit" wire:loading.attr="disabled" wire:target="send"
                            class="text-sm font-medium px-3 py-1.5 rounded bg-[#25D366] text-white hover:bg-[#1da851] disabled:opacity-50">{{ __('Send') }}</button>
                        <button type="button" wire:click="cancelCompose"
                            class="text-sm px-3 py-1.5 rounded bg-gray-100 text-gray-600 hover:bg-gray-200">{{ __('Cancel') }}</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>
