<div wire:key="mailbox-inbox">
    @if(session('mailbox_status'))
        <div class="mb-3 rounded-md bg-green-50 border border-green-200 px-3 py-2 text-sm text-green-800">
            {{ session('mailbox_status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- Left: thread list --}}
        <div class="lg:col-span-1 bg-white shadow-sm rounded-lg p-4 flex flex-col h-[75vh]">
            <div class="flex items-center justify-between mb-3 shrink-0">
                <div class="flex gap-1 text-xs">
                    @foreach(['inbox' => __('Inbox'), 'sent' => __('Sent'), 'archive' => __('Archive'), 'trash' => __('Trash')] as $key => $label)
                        <button type="button" wire:click="$set('folder', '{{ $key }}')"
                            class="px-2 py-1 rounded transition {{ $folder === $key ? 'bg-[#25D366] text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">{{ $label }}</button>
                    @endforeach
                </div>
                @if($myAccounts->isNotEmpty())
                    <button type="button" wire:click="startCompose"
                        class="text-xs font-medium px-2 py-1 rounded bg-gray-800 text-white hover:bg-gray-700 transition">{{ __('Compose') }}</button>
                @endif
            </div>

            <div class="relative mb-3 shrink-0">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search mail…') }}"
                    aria-label="{{ __('Search mail') }}"
                    class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366] pl-8" />
                <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                <span wire:loading.delay wire:target="search, selectThread"
                    class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-gray-400">{{ __('Loading…') }}</span>
            </div>

            <ul class="divide-y divide-gray-100 overflow-y-auto flex-1 -mx-4 px-4">
                @forelse($threads as $thread)
                    <li>
                        <button type="button" wire:click="selectThread({{ $thread->id }})"
                            class="w-full text-left py-3 px-2 rounded-lg transition hover:bg-gray-50 {{ $selectedThreadId === $thread->id ? 'bg-[#25D366]/5 ring-1 ring-[#25D366]/20' : '' }}">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm truncate {{ $thread->unread_count > 0 ? 'font-semibold text-gray-900' : 'text-gray-700' }}">
                                    {{ $thread->getSenderPreview() ?: ($thread->subject ?: __('(no subject)')) }}
                                </span>
                                @if($thread->unread_count > 0)
                                    <span class="shrink-0 inline-flex items-center justify-center rounded-full bg-[#25D366] text-white text-[10px] min-w-[1rem] h-4 px-1">{{ $thread->unread_count < 100 ? $thread->unread_count : '99+' }}</span>
                                @endif
                            </div>
                            @if($thread->subject)
                                <div class="text-xs text-gray-500 mt-0.5 truncate">{{ $thread->subject }}</div>
                            @endif
                            <div class="text-[11px] text-gray-400 mt-1">{{ $thread->last_message_at?->diffForHumans() }}</div>
                        </button>
                    </li>
                @empty
                    <li class="py-12 text-center text-sm text-gray-400">
                        @if($myAccounts->isEmpty())
                            <span class="block mb-2">{{ __('No mailbox connected yet.') }}</span>
                            <a href="{{ route('mailbox.accounts.index') }}"
                               class="inline-flex items-center font-medium text-[#25D366] hover:text-[#1da851]">{{ __('Connect a mailbox to get started →') }}</a>
                        @else
                            <span class="block mb-1">{{ __('No mail in this folder.') }}</span>
                            @if($search)
                                <span class="text-xs">{{ __('Try a different search term.') }}</span>
                            @endif
                        @endif
                    </li>
                @endforelse
            </ul>

            <div class="mt-3 shrink-0 border-t border-gray-100 pt-2">{{ $threads->links() }}</div>
        </div>

        {{-- Right: selected thread + composer --}}
        <div class="lg:col-span-2 bg-white shadow-sm rounded-lg p-4 overflow-y-auto max-h-[75vh]">
            @if($selected)
                <div class="flex items-start justify-between mb-4 gap-2">
                    <div class="min-w-0 flex-1">
                        <h3 class="text-lg font-semibold text-gray-800 break-words">{{ $selected->subject ?: __('(no subject)') }}</h3>
                        <p class="text-xs text-gray-400 mt-1">
                            {{ $selected->messages->first()?->from_email ? __('From:').' '.$selected->messages->first()->from_email : '' }}
                            &middot; {{ $selected->messages->count() }} {{ __('message(s)') }}
                        </p>
                    </div>
                    @if($canSendFromSelected && $selected->messages->isNotEmpty())
                        <button type="button" wire:click="startReply({{ $selected->messages->last()->id }})"
                            class="shrink-0 text-xs font-medium px-3 py-1.5 rounded bg-[#25D366] text-white hover:bg-[#1da851] transition">{{ __('Reply') }}</button>
                    @endif
                </div>

                <div class="space-y-3">
                    @foreach($selected->messages as $message)
                        <div class="border border-gray-100 rounded-lg p-4 {{ $message->direction === 'inbound' ? 'bg-white' : 'bg-gray-50/50' }}">
                            <div class="flex items-center justify-between text-xs text-gray-500 mb-2 gap-2">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="w-6 h-6 rounded-full {{ $message->direction === 'inbound' ? 'bg-sky-100 text-sky-700' : 'bg-violet-100 text-violet-700' }} flex items-center justify-center text-[10px] font-bold shrink-0">
                                        {{ strtoupper(substr($message->from_email ?? ($message->account->email ?? '?'), 0, 1)) }}
                                    </span>
                                    <span class="font-medium text-gray-700 truncate">{{ $message->from_email ?: ($message->account->email ?? '') }}</span>
                                    @if($message->direction === 'outbound')
                                        <span class="text-[10px] text-gray-400 bg-gray-200 px-1.5 rounded">{{ __('Sent') }}</span>
                                    @endif
                                </div>
                                <span class="shrink-0">{{ ($message->received_at ?? $message->sent_at)?->format('M j, Y g:i A') }}</span>
                            </div>

                            @if($message->body_html)
                                <iframe sandbox class="w-full min-h-[120px] border rounded-md" srcdoc="{{ $message->body_html }}" title="{{ __('Message body') }}"></iframe>
                            @elseif($message->body_text)
                                <div class="prose prose-sm max-w-none text-gray-700 whitespace-pre-wrap font-sans">{{ $message->body_text }}</div>
                            @endif

                            @if($message->attachments->isNotEmpty())
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach($message->attachments as $att)
                                        <a href="{{ route('mailbox.attachments.download', $att) }}"
                                            class="inline-flex items-center gap-1 rounded-lg bg-gray-100 px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-200 transition">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13"/></svg>
                                            {{ $att->filename }}
                                            <span class="text-gray-400">({{ number_format($att->size / 1024, 1) }} KB)</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif

                            @if($canSendFromSelected)
                                <div class="mt-3 flex gap-3 text-xs border-t border-gray-50 pt-3">
                                    <button type="button" wire:click="startReply({{ $message->id }})" class="text-gray-500 hover:text-gray-800 font-medium transition">{{ __('Reply') }}</button>
                                    <button type="button" wire:click="startReply({{ $message->id }}, true)" class="text-gray-500 hover:text-gray-800 font-medium transition">{{ __('Reply all') }}</button>
                                    <button type="button" wire:click="startForward({{ $message->id }})" class="text-gray-500 hover:text-gray-800 font-medium transition">{{ __('Forward') }}</button>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @elseif(! $composing)
                <div class="py-16 text-center text-sm text-gray-400">
                    <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 9v.906a2.25 2.25 0 01-1.183 1.981l-6.478 3.488M2.25 9v.906a2.25 2.25 0 001.183 1.981l6.478 3.488m8.839 2.51l-4.66-2.51m0 0l-1.023-.55a2.25 2.25 0 00-2.134 0l-1.022.55m0 0l-4.661 2.51m16.5 1.615a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V8.844a2.25 2.25 0 011.183-1.98l7.5-4.04a2.25 2.25 0 012.134 0l7.5 4.04a2.25 2.25 0 011.183 1.98V19.5z"/></svg>
                    {{ __('Select a conversation to read.') }}
                </div>
            @endif

            @if($composing)
                <form wire:submit.prevent="send" class="mt-4 border-t border-gray-100 pt-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                            {{ ['reply' => __('Reply'), 'reply_all' => __('Reply all'), 'forward' => __('Forward'), 'new' => __('New message')][$composeMode] ?? __('Message') }}
                        </span>
                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="$set('showAiDraft', true)"
                                    class="text-xs font-medium px-2 py-1 rounded bg-purple-50 text-purple-700 hover:bg-purple-100 transition"
                                    title="{{ __('Draft with AI') }}">
                                <svg class="w-3.5 h-3.5 inline" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"/></svg>
                                {{ __('AI') }}
                            </button>
                            <button type="button" wire:click="cancelCompose" class="text-xs text-gray-400 hover:text-gray-600">&times;</button>
                        </div>
                    </div>

                    {{-- AI Draft Modal --}}
                    @if($showAiDraft)
                        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40" wire:click="$set('showAiDraft', false)">
                            <div class="bg-white rounded-xl shadow-2xl p-5 max-w-lg w-full" wire:click.stop>
                                <h4 class="text-sm font-bold text-gray-800 mb-3">{{ __('AI Email Draft') }}</h4>
                                <div class="space-y-3">
                                    <div>
                                        <label class="text-xs font-semibold text-gray-500 uppercase tracking-wide">{{ __('Goal') }}</label>
                                        <textarea wire:model="aiDraftGoal" rows="2" class="mt-1 w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500" placeholder="{{ __('e.g. Introduce our product and schedule a demo') }}"></textarea>
                                        @error('aiDraftGoal') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold text-gray-500 uppercase tracking-wide">{{ __('Tone') }}</label>
                                        <select wire:model="aiDraftTone" class="mt-1 block w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                                            <option value="professional">{{ __('Professional') }}</option>
                                            <option value="friendly">{{ __('Friendly') }}</option>
                                            <option value="formal">{{ __('Formal') }}</option>
                                            <option value="casual">{{ __('Casual') }}</option>
                                            <option value="persuasive">{{ __('Persuasive') }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold text-gray-500 uppercase tracking-wide">{{ __('Context (optional)') }}</label>
                                        <textarea wire:model="aiDraftContext" rows="2" class="mt-1 w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500" placeholder="{{ __('Any background info the AI should know...') }}"></textarea>
                                    </div>
                                </div>
                                <div class="flex justify-end gap-2 mt-4 pt-3 border-t border-gray-100">
                                    <button type="button" wire:click="$set('showAiDraft', false)" class="px-3 py-1.5 text-sm text-gray-600 hover:text-gray-800">{{ __('Cancel') }}</button>
                                    <button type="button" wire:click="draftWithAi" wire:loading.attr="disabled"
                                            class="px-4 py-1.5 text-sm font-semibold bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition">
                                        <span wire:loading.remove wire:target="draftWithAi">{{ __('Generate Draft') }}</span>
                                        <span wire:loading wire:target="draftWithAi">{{ __('Generating...') }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if($composeMode === 'new' && $myAccounts->count() > 1)
                        <select wire:model="composeAccountId" aria-label="{{ __('Send from') }}" class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                            @foreach($myAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->email }}</option>
                            @endforeach
                        </select>
                    @endif

                    <div>
                        <input type="text" wire:model="composeTo" placeholder="{{ __('To') }}" aria-label="{{ __('To') }}"
                            class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]" />
                        @error('composeTo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <input type="text" wire:model="composeCc" placeholder="{{ __('Cc (optional)') }}" aria-label="{{ __('Cc') }}"
                        class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]" />

                    <div>
                        <input type="text" wire:model="composeSubject" placeholder="{{ __('Subject') }}" aria-label="{{ __('Subject') }}"
                            class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]" />
                        @error('composeSubject') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <textarea wire:model="composeBody" rows="8" placeholder="{{ __('Write your message…') }}" aria-label="{{ __('Message body') }}"
                            class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]"></textarea>
                    </div>

                    <div class="flex items-center gap-3">
                        <label class="flex items-center gap-1.5 text-xs text-gray-500 cursor-pointer hover:text-gray-700">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13"/></svg>
                            <input type="file" wire:model="composeFiles" multiple class="hidden" />
                            {{ __('Attach') }}
                        </label>
                        <span wire:loading wire:target="composeFiles" class="text-xs text-gray-400">{{ __('Uploading…') }}</span>
                    </div>
                    @error('composeFiles.*') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @if(! empty($composeFiles))
                        <div class="flex flex-wrap gap-2">
                            @foreach($composeFiles as $file)
                                <span class="inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                                    {{ $file->getClientOriginalName() }}
                                    <button type="button" wire:click="removeFile({{ $loop->index }})" class="text-gray-400 hover:text-red-500">&times;</button>
                                </span>
                            @endforeach
                        </div>
                    @endif

                    <div class="flex items-center gap-2 pt-2">
                        <button type="submit" wire:loading.attr="disabled" wire:target="send"
                            class="inline-flex items-center gap-1.5 text-sm font-medium px-4 py-2 rounded-lg bg-[#25D366] text-white hover:bg-[#1da851] disabled:opacity-50 transition shadow-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                            <span wire:loading.remove wire:target="send">{{ __('Send') }}</span>
                            <span wire:loading wire:target="send">{{ __('Sending…') }}</span>
                        </button>
                        <button type="button" wire:click="cancelCompose"
                            class="text-sm px-3 py-2 rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 transition">{{ __('Cancel') }}</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>
