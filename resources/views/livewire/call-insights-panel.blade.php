@php
    use App\Models\CallLog;
    $polling = $call && in_array($call->ai_status, [CallLog::AI_STATUS_PENDING, CallLog::AI_STATUS_PROCESSING], true);
    $fmtDur = function (?int $s): string {
        $s = (int) $s;
        return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    };
    $mos = $call?->quality_metrics['mos'] ?? null;
@endphp

<aside class="flex flex-col h-full bg-white border-l border-gray-200"
       @if($polling) wire:poll.5s @endif>

    @if(! $call)
        {{-- Empty state --}}
        <div class="flex flex-1 flex-col items-center justify-center text-center px-8 text-gray-400">
            <svg class="w-12 h-12 mb-3" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
            <p class="text-sm font-medium text-gray-500">{{ __('Select a call') }}</p>
            <p class="text-xs mt-1">{{ __('Pick a call on the left to see its summary, transcript, and notes.') }}</p>
        </div>
    @else
        {{-- Contact header --}}
        <div class="px-5 pt-5 pb-4 border-b border-gray-100">
            <div class="flex items-start gap-3">
                <div class="grid place-items-center w-11 h-11 rounded-full bg-[#25D366]/10 text-[#128C7E] font-bold shrink-0">
                    {{ strtoupper(substr($call->contact?->name ?? $call->to_phone ?? '?', 0, 1)) }}
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-bold text-gray-900 truncate">{{ $call->contact?->name ?? __('Unknown') }}</h2>
                    <p class="text-xs text-gray-500 font-mono truncate">{{ $call->contact?->phone ?? $call->to_phone }}</p>
                </div>
                @if($context['engaged'])
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">{{ __('Engaged') }}</span>
                @endif
            </div>

            {{-- Call facts row --}}
            <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                <div class="rounded-lg bg-gray-50 py-2">
                    <div class="text-sm font-bold text-gray-900">{{ $fmtDur($call->duration_seconds) }}</div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-400">{{ __('Duration') }}</div>
                </div>
                <div class="rounded-lg bg-gray-50 py-2">
                    <div class="text-sm font-bold {{ $call->direction === 'inbound' ? 'text-sky-600' : 'text-violet-600' }}">{{ ucfirst($call->direction) }}</div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-400">{{ $call->status }}</div>
                </div>
                <div class="rounded-lg bg-gray-50 py-2">
                    <div class="text-sm font-bold text-gray-900">{{ $mos ? number_format((float) $mos, 1) : '—' }}</div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-400">{{ __('MOS') }}</div>
                </div>
            </div>
            <p class="mt-2 text-[11px] text-gray-400">
                {{ $call->created_at?->format('M j, Y · g:i A') }}
                @if($call->placedBy) · {{ __('by') }} {{ $call->placedBy->name }} @endif
            </p>
        </div>

        {{-- Scrollable body --}}
        <div class="flex-1 overflow-y-auto px-5 py-4 space-y-6">

            {{-- ── AI SUMMARY ─────────────────────────────────────────── --}}
            <section>
                <h3 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-gray-500 mb-2">
                    <svg class="w-4 h-4 text-[#25D366]" fill="currentColor" viewBox="0 0 24 24"><path d="M11 2 8.5 8.5 2 11l6.5 2.5L11 20l2.5-6.5L20 11l-6.5-2.5L11 2Z"/></svg>
                    {{ __('AI Summary') }}
                </h3>

                @switch($call->ai_status)
                    @case(CallLog::AI_STATUS_COMPLETED)
                        <div class="rounded-xl bg-gradient-to-br from-emerald-50 to-teal-50 border border-emerald-100 p-4">
                            <p class="text-sm text-gray-800 leading-relaxed">{{ $call->ai_summary ?: __('No summary produced.') }}</p>
                        </div>
                        @if(!empty($call->ai_key_points))
                            <ul class="mt-3 space-y-1.5">
                                @foreach($call->ai_key_points as $point)
                                    <li class="flex gap-2 text-sm text-gray-700">
                                        <span class="mt-1.5 w-1.5 h-1.5 rounded-full bg-[#25D366] shrink-0"></span>
                                        <span>{{ $point }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @break

                    @case(CallLog::AI_STATUS_PENDING)
                    @case(CallLog::AI_STATUS_PROCESSING)
                        <div class="flex items-center gap-3 rounded-xl bg-gray-50 border border-gray-100 p-4 text-sm text-gray-500">
                            <svg class="w-5 h-5 animate-spin text-[#25D366]" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            {{ __('Analysing the call…') }}
                        </div>
                        @break

                    @case(CallLog::AI_STATUS_FAILED)
                        <div class="rounded-xl bg-red-50 border border-red-100 p-4 text-sm text-red-700">
                            {{ __('Analysis failed.') }} <span class="text-red-500">{{ $call->ai_error }}</span>
                        </div>
                        @break

                    @case(CallLog::AI_STATUS_UNAVAILABLE)
                        <p class="text-sm text-gray-400">{{ __('AI analysis is not available for this call (no Gemini key, or the recording is gone).') }}</p>
                        @break

                    @default
                        <p class="text-sm text-gray-400">
                            @if($call->hasRecording())
                                {{ __('Recording captured — analysis has not run yet.') }}
                            @else
                                {{ __('No recording was captured for this call, so there is nothing to summarise.') }}
                            @endif
                        </p>
                @endswitch

                {{-- Re-analyse: recover a recording whose analysis failed / never ran
                     (e.g. Gemini quota, or ffmpeg installed after the fact). --}}
                @if($aiConfigured && $call->hasRecording() && in_array($call->ai_status, [CallLog::AI_STATUS_FAILED, CallLog::AI_STATUS_UNAVAILABLE, CallLog::AI_STATUS_NONE], true))
                    <button type="button" wire:click="reanalyse"
                            wire:loading.attr="disabled" wire:target="reanalyse"
                            class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-[#128C7E] hover:text-[#0e6b5e] disabled:opacity-50">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356M2.985 19.644v-4.992h4.992m-4.5-4.5a7.5 7.5 0 0113.02-3.02L20.015 8m-.985 5.652a7.5 7.5 0 01-13.02 3.02L2.985 15"/></svg>
                        <span wire:loading.remove wire:target="reanalyse">{{ __('Re-analyse call') }}</span>
                        <span wire:loading wire:target="reanalyse">{{ __('Queuing…') }}</span>
                    </button>
                @endif
            </section>

            {{-- ── RECORDING + TRANSCRIPT ─────────────────────────────── --}}
            @if($call->hasRecording())
                <section x-data="{ open: false }">
                    <audio controls preload="none" class="w-full h-9"
                           src="{{ route('calls.recording.download', $call) }}"></audio>
                    @if(filled($call->transcript))
                        <button type="button" @click="open = !open"
                                class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-gray-500 hover:text-gray-800">
                            <span x-text="open ? '{{ __('Hide transcript') }}' : '{{ __('Show transcript') }}'"></span>
                            <svg class="w-3.5 h-3.5 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div x-show="open" x-collapse x-cloak class="mt-2 rounded-lg bg-gray-50 border border-gray-100 p-3 text-xs text-gray-600 whitespace-pre-line leading-relaxed max-h-64 overflow-y-auto">{{ $call->transcript }}</div>
                    @endif
                </section>
            @endif

            {{-- ── CONTEXT: recent messages + prior calls ─────────────── --}}
            @if($context['recentMessages']->isNotEmpty() || $context['priorCalls']->isNotEmpty())
                <section>
                    <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-2">{{ __('Context') }}</h3>
                    @if($context['recentMessages']->isNotEmpty())
                        <div class="space-y-1.5 mb-3">
                            @foreach($context['recentMessages'] as $msg)
                                <div class="flex gap-2 text-xs">
                                    <span class="shrink-0 font-semibold {{ $msg->isInbound() ? 'text-sky-600' : 'text-[#128C7E]' }}">{{ $msg->isInbound() ? __('Them') : __('Us') }}:</span>
                                    <span class="text-gray-600 truncate">{{ \Illuminate\Support\Str::limit($msg->body ?: '['.$msg->type.']', 80) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if($context['priorCalls']->isNotEmpty())
                        <p class="text-[11px] text-gray-400">
                            {{ trans_choice('{1} :count earlier call|[2,*] :count earlier calls', $context['priorCalls']->count(), ['count' => $context['priorCalls']->count()]) }}
                            {{ __('with this contact') }}
                        </p>
                    @endif
                </section>
            @endif

            {{-- ── NOTES ──────────────────────────────────────────────── --}}
            <section>
                <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-2">
                    {{ __('Notes') }} <span class="text-gray-300">·</span> <span class="text-gray-400">{{ $call->notes->count() }}</span>
                </h3>

                <div class="space-y-2 mb-3">
                    @forelse($call->notes as $note)
                        <div class="rounded-lg bg-amber-50/60 border border-amber-100 px-3 py-2">
                            <p class="text-sm text-gray-800 whitespace-pre-line">{{ $note->body }}</p>
                            <p class="mt-1 text-[10px] text-gray-400">
                                {{ $note->author?->name ?? __('Removed user') }} · {{ $note->created_at->diffForHumans() }}
                            </p>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400">{{ __('No notes yet. Capture what mattered on this call.') }}</p>
                    @endforelse
                </div>

                <form wire:submit="addNote" class="space-y-2">
                    <textarea wire:model="noteBody" rows="2" maxlength="5000"
                              placeholder="{{ __('Add a note — logged with your name and the time…') }}"
                              class="block w-full rounded-lg border-gray-200 text-sm shadow-sm focus:border-[#25D366] focus:ring-[#25D366] resize-none"></textarea>
                    @error('noteBody') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    <div class="flex justify-end">
                        <button type="submit"
                                class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-lg bg-[#25D366] text-white text-sm font-semibold hover:bg-[#1da851] disabled:opacity-50"
                                wire:loading.attr="disabled" wire:target="addNote">
                            <span wire:loading.remove wire:target="addNote">{{ __('Log note') }}</span>
                            <span wire:loading wire:target="addNote">{{ __('Saving…') }}</span>
                        </button>
                    </div>
                </form>
            </section>
        </div>
    @endif
</aside>
