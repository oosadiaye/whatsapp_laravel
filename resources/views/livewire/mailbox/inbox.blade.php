<div wire:key="mailbox-inbox" x-ref="shell"
     x-data="{
        w: 416, min: 256, max: 680, full: false,
        init() {
            try { const v = parseInt(localStorage.getItem('mailbox.listWidth'), 10); if (v >= this.min && v <= this.max) { this.w = v; } } catch (e) {}
        },
        clamp(v) { return Math.min(this.max, Math.max(this.min, v)); },
        save() { try { localStorage.setItem('mailbox.listWidth', String(this.w)); } catch (e) {} },
        startDrag(e) {
            const x0 = e.clientX, w0 = this.w;
            const move = (ev) => { this.w = this.clamp(w0 + ev.clientX - x0); };
            const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); this.save(); };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },
        nudge(d) { this.w = this.clamp(this.w + d); this.save(); },
        reset() { this.w = 416; this.save(); },
        toggleFull() {
            try { if (document.fullscreenElement) { document.exitFullscreen(); } else { this.$refs.shell.requestFullscreen(); } } catch (e) {}
        },
     }"
     @fullscreenchange.window="full = !! document.fullscreenElement"
     :class="full ? 'bg-[rgb(var(--ground))] p-3 overflow-y-auto' : ''"
     :style="'--list-w:' + w + 'px; --pane-h:' + (full ? 'calc(100vh - 1.5rem)' : 'calc(100vh - 5.75rem)')">
    @if(session('mailbox_status'))
        <div class="mb-3 rounded-md bg-green-50 border border-green-200 px-3 py-2 text-sm text-green-800">
            {{ session('mailbox_status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:gap-0 lg:grid-cols-[var(--list-w)_1rem_minmax(0,1fr)]">
        {{-- Left: thread list --}}
        <div class="bg-white shadow-sm rounded-lg p-4 flex flex-col h-[var(--pane-h)] min-h-[30rem] min-w-0">
            <div class="flex items-center justify-between mb-3 shrink-0">
                <div class="flex gap-1 text-xs">
                    @foreach(['inbox' => __('Inbox'), 'sent' => __('Sent'), 'archive' => __('Archive'), 'trash' => __('Trash')] as $key => $label)
                        <button type="button" wire:click="$set('folder', '{{ $key }}')"
                            class="px-2 py-1 rounded transition {{ $folder === $key ? 'bg-brand text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">{{ $label }}</button>
                    @endforeach
                </div>
                <div class="flex items-center gap-1">
                    <button type="button" @click="toggleFull()" class="rounded p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-800"
                        :title="full ? '{{ __('Exit full screen') }}' : '{{ __('Full screen') }}'" :aria-label="full ? '{{ __('Exit full screen') }}' : '{{ __('Full screen') }}'">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15"/></svg>
                    </button>
                    @if($myAccounts->isNotEmpty())
                        <button type="button" wire:click="startCompose"
                            class="text-xs font-medium px-2 py-1 rounded bg-gray-800 text-white hover:bg-gray-700 transition">{{ __('Compose') }}</button>
                    @endif
                </div>
            </div>

            <div class="relative mb-3 shrink-0">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search mail…') }}"
                    aria-label="{{ __('Search mail') }}"
                    class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-brand focus:ring-brand pl-8" />
                <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                <span wire:loading.delay wire:target="search, selectThread"
                    class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-gray-500">{{ __('Loading…') }}</span>
            </div>

            <div class="mb-2 flex items-center gap-2 shrink-0 text-xs text-gray-500">
                <label for="mailbox-sort" class="shrink-0">{{ __('Sort by') }}</label>
                <select id="mailbox-sort" wire:model.live="sortBy"
                    class="flex-1 min-w-0 text-xs rounded-md border-gray-300 py-1 shadow-sm focus:border-brand focus:ring-brand">
                    <option value="date">{{ __('Date') }}</option>
                    <option value="sender">{{ $folder === 'sent' ? __('Recipient (A–Z)') : __('Sender (A–Z)') }}</option>
                    <option value="subject">{{ __('Subject (A–Z)') }}</option>
                </select>
                <button type="button" wire:click="toggleSortDir"
                    class="shrink-0 inline-flex items-center gap-1 rounded-md border border-gray-300 px-2 py-1 text-xs text-gray-700 hover:bg-gray-50"
                    title="{{ $sortDir === 'asc' ? __('Ascending') : __('Descending') }}"
                    aria-label="{{ __('Reverse sort order') }}">
                    @if($sortBy === 'date')
                        {{ $sortDir === 'asc' ? __('Oldest first') : __('Newest first') }}
                    @else
                        {{ $sortDir === 'asc' ? __('A → Z') : __('Z → A') }}
                    @endif
                    <svg class="w-3 h-3 {{ $sortDir === 'asc' ? '' : 'rotate-180' }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5"/></svg>
                </button>
            </div>

            <ul class="divide-y divide-gray-100 overflow-y-auto flex-1 -mx-4 px-2">
                @forelse($threads as $thread)
                    <li>
                        <button type="button" wire:click="selectThread({{ $thread->id }})"
                            class="w-full text-left py-3 px-2 rounded-lg transition hover:bg-gray-50 {{ $selectedThreadId === $thread->id ? 'bg-brand/5 ring-1 ring-brand/20' : '' }}">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="text-sm truncate {{ $thread->unread_count > 0 ? 'font-semibold text-gray-900' : 'text-gray-700' }}">
                                    {{ $folder === 'sent' ? ($thread->getRecipientPreview() ? __('To:').' '.$thread->getRecipientPreview() : ($thread->subject ?: __('(no subject)'))) : ($thread->getSenderPreview() ?: ($thread->subject ?: __('(no subject)'))) }}
                                </span>
                                <span class="shrink-0 text-[11px] {{ $thread->unread_count > 0 ? 'font-semibold text-brand-text' : 'text-gray-500' }}">{{ $thread->getListDateLabel() }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-2 mt-0.5">
                                <span class="text-xs truncate {{ $thread->unread_count > 0 ? 'font-semibold text-gray-800' : 'text-gray-600' }}">{{ $thread->subject ?: __('(no subject)') }}</span>
                                @if($thread->unread_count > 0)
                                    <span class="shrink-0 inline-flex items-center justify-center rounded-full bg-brand text-white text-[10px] min-w-[1rem] h-4 px-1">{{ $thread->unread_count < 100 ? $thread->unread_count : '99+' }}</span>
                                @endif
                            </div>
                            <div class="text-xs text-gray-500 mt-0.5 truncate">{{ $thread->getSnippet() }}</div>
                        </button>
                    </li>
                @empty
                    <li class="py-12 text-center text-sm text-gray-500">
                        @if($myAccounts->isEmpty())
                            <span class="block mb-2">{{ __('No mailbox connected yet.') }}</span>
                            <a href="{{ route('mailbox.accounts.index') }}"
                               class="inline-flex items-center font-medium text-brand-text hover:text-brand-hover">{{ __('Connect a mailbox to get started →') }}</a>
                        @else
                            <span class="block mb-1">{{ __('No mail in this folder.') }}</span>
                            @if($search)
                                <span class="text-xs">{{ __('Try a different search term.') }}</span>
                            @endif
                        @endif
                    </li>
                @endforelse
            </ul>

            <div class="mt-3 shrink-0 border-t border-gray-100 pt-2 flex items-center justify-between text-xs text-gray-500">
                <span>
                    @if($threads->total() > 0)
                        {{ $threads->firstItem() }}–{{ $threads->lastItem() }} {{ __('of') }} {{ number_format($threads->total()) }}
                    @endif
                </span>
                <span class="flex gap-1">
                    <button type="button" wire:click="previousPage" @disabled($threads->onFirstPage())
                        class="rounded border border-gray-300 px-2 py-1 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed">&lsaquo; {{ __('Prev') }}</button>
                    <button type="button" wire:click="nextPage" @disabled(! $threads->hasMorePages())
                        class="rounded border border-gray-300 px-2 py-1 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed">{{ __('Next') }} &rsaquo;</button>
                </span>
            </div>
        </div>

        {{-- Drag to resize the list; arrow keys nudge it, double-click resets. --}}
        <div class="hidden lg:flex items-center justify-center cursor-col-resize group touch-none select-none"
             role="separator" aria-orientation="vertical" tabindex="0"
             aria-label="{{ __('Resize the mail list') }}"
             :aria-valuenow="w" :aria-valuemin="min" :aria-valuemax="max"
             @pointerdown.prevent="startDrag($event)"
             @dblclick="reset()"
             @keydown.left.prevent="nudge(-24)"
             @keydown.right.prevent="nudge(24)">
            <span class="h-16 w-1 rounded-full bg-gray-300 transition group-hover:bg-brand group-focus-visible:bg-brand"></span>
        </div>

        {{-- Right: selected thread + composer --}}
        <div class="bg-white shadow-sm rounded-lg p-5 overflow-y-auto h-[var(--pane-h)] min-h-[30rem] min-w-0">
            @if($selected)
                <div class="flex items-start justify-between mb-4 gap-2">
                    <div class="min-w-0 flex-1">
                        <h3 class="text-xl font-semibold text-gray-800 break-words">{{ $selected->subject ?: __('(no subject)') }}</h3>
                        <p class="text-xs text-gray-500 mt-1">
                            {{ $selected->messages->first()?->from_email ? __('From:').' '.$selected->messages->first()->from_email : '' }}
                            &middot; {{ $selected->messages->count() }} {{ __('message(s)') }}
                        </p>
                    </div>
                    @if($canSendFromSelected && $selected->messages->isNotEmpty())
                        <button type="button" wire:click="startReply({{ $selected->messages->last()->id }})"
                            class="shrink-0 text-xs font-medium px-3 py-1.5 rounded bg-brand text-white hover:bg-brand-hover transition">{{ __('Reply') }}</button>
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
                                        <span class="text-[10px] text-gray-500 bg-gray-200 px-1.5 rounded">{{ __('Sent') }}</span>
                                    @endif
                                </div>
                                <span class="shrink-0">{{ ($message->received_at ?? $message->sent_at)?->format('M j, Y g:i A') }}</span>
                            </div>

                            @if($message->body_html)
                                <iframe sandbox class="w-full border rounded-md bg-white {{ $loop->last ? 'h-[60vh]' : 'h-[30vh]' }} min-h-[14rem]" srcdoc="{{ $message->body_html }}" title="{{ __('Message body') }}"></iframe>
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
                                            <span class="text-gray-500">({{ number_format($att->size / 1024, 1) }} KB)</span>
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
            @else
                <div class="py-16 text-center text-sm text-gray-500">
                    <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 9v.906a2.25 2.25 0 01-1.183 1.981l-6.478 3.488M2.25 9v.906a2.25 2.25 0 001.183 1.981l6.478 3.488m8.839 2.51l-4.66-2.51m0 0l-1.023-.55a2.25 2.25 0 00-2.134 0l-1.022.55m0 0l-4.661 2.51m16.5 1.615a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V8.844a2.25 2.25 0 011.183-1.98l7.5-4.04a2.25 2.25 0 012.134 0l7.5 4.04a2.25 2.25 0 011.183 1.98V19.5z"/></svg>
                    {{ __('Select a conversation to read.') }}
                </div>
            @endif

        </div>
    </div>
    {{-- Floating composer, pinned bottom-right like Gmail. Minimise is client-side only so a
         half-written message is never lost; the send logic is the same Livewire form as before. --}}
    @if($composing)
        <div x-data="{ minimised: false }"
             class="fixed bottom-0 right-0 sm:right-6 z-40 w-full sm:w-[34rem] max-w-full flex flex-col bg-white rounded-t-lg shadow-2xl ring-1 ring-gray-300"
             role="dialog" aria-label="{{ __('Compose message') }}">
            <div class="flex items-center justify-between gap-2 rounded-t-lg bg-gray-800 px-3 py-2 text-white cursor-pointer select-none"
                 @click="minimised = ! minimised">
                <span class="truncate text-sm font-medium">
                    {{ ['reply' => __('Reply'), 'reply_all' => __('Reply all'), 'forward' => __('Forward'), 'new' => __('New message')][$composeMode] ?? __('Message') }}@if($composeMode !== 'new' && $composeSubject !== ''): {{ $composeSubject }}@endif
                </span>
                <span class="flex items-center gap-1" @click.stop>
                    <button type="button" wire:click="$set('showAiDraft', true)" title="{{ __('Draft with AI') }}"
                            class="rounded px-2 py-0.5 text-xs font-medium bg-purple-500/30 hover:bg-purple-500/50 transition">{{ __('AI') }}</button>
                    <button type="button" @click="minimised = ! minimised" class="rounded px-2 text-lg leading-none hover:bg-white/10" :aria-label="minimised ? '{{ __('Expand') }}' : '{{ __('Minimise') }}'">
                        <span x-text="minimised ? '▢' : '–'"></span>
                    </button>
                    <button type="button" wire:click="cancelCompose" class="rounded px-2 text-lg leading-none hover:bg-white/10" aria-label="{{ __('Discard') }}">&times;</button>
                </span>
            </div>

            <form wire:submit.prevent="send" x-show="! minimised" class="space-y-2 p-3 max-h-[70vh] overflow-y-auto">
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

            {{-- From (only when there is a choice) / To / Cc / Subject: borderless rows like Gmail --}}
            <div class="-mx-3 divide-y divide-gray-100 border-y border-gray-100 text-sm">
                @if($composeMode === 'new' && $myAccounts->count() > 1)
                    <div class="flex items-center gap-2 px-3">
                        <label for="compose-from" class="w-12 shrink-0 text-gray-500">{{ __('From') }}</label>
                        <select id="compose-from" wire:model="composeAccountId" class="flex-1 border-0 bg-transparent py-2 text-sm focus:ring-0">
                            @foreach($myAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->email }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="flex items-center gap-2 px-3">
                    <label for="compose-to" class="w-12 shrink-0 text-gray-500">{{ __('To') }}</label>
                    <input id="compose-to" type="text" wire:model="composeTo" autocomplete="off" spellcheck="false"
                        class="flex-1 border-0 bg-transparent py-2 text-sm placeholder-gray-400 focus:ring-0" placeholder="{{ __('name@example.com, another@example.com') }}" />
                </div>
                @error('composeTo') <p class="px-3 py-1 text-xs text-red-600">{{ $message }}</p> @enderror
                <div class="flex items-center gap-2 px-3">
                    <label for="compose-cc" class="w-12 shrink-0 text-gray-500">{{ __('Cc') }}</label>
                    <input id="compose-cc" type="text" wire:model="composeCc" autocomplete="off" spellcheck="false"
                        class="flex-1 border-0 bg-transparent py-2 text-sm placeholder-gray-400 focus:ring-0" placeholder="{{ __('optional') }}" />
                </div>
                <div class="flex items-center gap-2 px-3">
                    <label for="compose-subject" class="w-12 shrink-0 text-gray-500">{{ __('Subject') }}</label>
                    <input id="compose-subject" type="text" wire:model="composeSubject"
                        class="flex-1 border-0 bg-transparent py-2 text-sm font-medium placeholder-gray-400 focus:ring-0" />
                </div>
                @error('composeSubject') <p class="px-3 py-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- AI writing tools: act on the body only, never on quoted history. --}}
            <div class="flex flex-wrap items-center gap-1.5 pt-1" aria-label="{{ __('AI writing tools') }}">
                @foreach(['fix' => __('Fix spelling & grammar'), 'rephrase' => __('Rephrase'), 'polish' => __('Polish')] as $mode => $label)
                    <button type="button" wire:click="aiEdit('{{ $mode }}')" wire:loading.attr="disabled" wire:target="aiEdit"
                        class="inline-flex items-center gap-1 rounded-full border border-purple-200 bg-purple-50 px-2.5 py-1 text-xs font-medium text-purple-700 hover:bg-purple-100 disabled:opacity-50 transition">
                        <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/></svg>
                        {{ $label }}
                    </button>
                @endforeach
                <span wire:loading wire:target="aiEdit" class="text-xs text-purple-700">{{ __('Working on it…') }}</span>
                @if($bodyBeforeAi !== null)
                    <button type="button" wire:click="undoAiEdit" class="ml-auto text-xs font-medium text-gray-600 underline hover:text-gray-900">{{ __('Undo AI change') }}</button>
                @endif
            </div>

            <div wire:loading.class="opacity-60" wire:target="aiEdit">
                <textarea wire:model="composeBody" rows="11" spellcheck="true" placeholder="{{ __('Write your message…') }}" aria-label="{{ __('Message body') }}"
                    class="w-full resize-y rounded-md border-0 bg-transparent px-0 text-sm leading-relaxed placeholder-gray-400 focus:ring-0"></textarea>
            </div>

            @error('composeFiles.*') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            @if(! empty($composeFiles))
                <div class="flex flex-wrap gap-2">
                    @foreach($composeFiles as $file)
                        <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-700">
                            {{ $file->getClientOriginalName() }}
                            <button type="button" wire:click="removeFile({{ $loop->index }})" class="text-gray-500 hover:text-red-500" aria-label="{{ __('Remove attachment') }}">&times;</button>
                        </span>
                    @endforeach
                </div>
            @endif

            <div class="flex items-center justify-between gap-2 border-t border-gray-100 pt-3">
                <div class="flex items-center gap-2">
                    <button type="submit" wire:loading.attr="disabled" wire:target="send"
                        class="inline-flex items-center gap-1.5 rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-hover disabled:opacity-50">
                        <span wire:loading.remove wire:target="send">{{ __('Send') }}</span>
                        <span wire:loading wire:target="send">{{ __('Sending…') }}</span>
                    </button>
                    <label class="flex cursor-pointer items-center gap-1 rounded-full p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-800" title="{{ __('Attach files') }}">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13"/></svg>
                        <input type="file" wire:model="composeFiles" multiple class="hidden" />
                        <span wire:loading wire:target="composeFiles" class="text-xs">{{ __('Uploading…') }}</span>
                    </label>
                </div>
                <div class="flex items-center gap-3 text-xs text-gray-500">
                    <span x-data x-text="($wire.composeBody || '').trim() === '' ? '' : (($wire.composeBody || '').trim().split(/\s+/).length + ' {{ __('words') }}')"></span>
                    @if($composeAccountId)
                        <a href="{{ route('mailbox.accounts.edit', $composeAccountId) }}#signature" target="_blank" rel="noopener" class="hover:text-gray-800 hover:underline">{{ __('Signature') }}</a>
                    @endif
                    <button type="button" wire:click="cancelCompose" class="rounded-full p-2 hover:bg-gray-100 hover:text-red-600" title="{{ __('Discard draft') }}" aria-label="{{ __('Discard draft') }}">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                    </button>
                </div>
            </div>
            </form>
        </div>
    @endif
</div>
