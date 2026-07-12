<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Jobs\TranscribeCallRecording;
use App\Models\CallLog;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Right-hand panel of the Call Workspace. For the selected call it shows:
 *   - a context brief built from real data (contact, engagement, recent
 *     WhatsApp messages, prior calls, call facts),
 *   - the Gemini AI summary + key points + transcript (once analysed),
 *   - an append-only notes timeline with an "add note" box.
 *
 * The panel polls while transcription is in flight (ai_status pending /
 * processing) so the summary appears without a manual refresh, then stops.
 */
class CallInsightsPanel extends Component
{
    public ?int $callId = null;

    public string $noteBody = '';

    public function mount(?int $callId = null): void
    {
        $this->callId = $callId;
    }

    /**
     * Switch the panel to another call (clicked in the left-hand list).
     */
    public function selectCall(int $callId): void
    {
        $this->callId = $callId;
        $this->noteBody = '';
        $this->resetErrorBag();
    }

    public function addNote(): void
    {
        $call = $this->authorizedCall();
        if ($call === null) {
            return;
        }

        $validated = $this->validate([
            'noteBody' => ['required', 'string', 'max:5000'],
        ]);

        $call->notes()->create([
            'user_id' => auth()->id(),
            'body' => $validated['noteBody'],
        ]);

        $this->noteBody = '';
    }

    /**
     * Re-run transcription on a call that has a recording — e.g. a first attempt
     * failed (Gemini quota, or ffmpeg wasn't installed yet) and the operator has
     * since fixed the cause. Requeues rather than making the recording a dead end.
     */
    public function reanalyse(): void
    {
        $call = $this->authorizedCall();
        if ($call === null) {
            return;
        }

        // Nothing to do without a recording or a configured Gemini key, and never
        // stack a second job while one is already in flight.
        if (! $call->hasRecording() || ! filled(config('services.gemini.key'))) {
            return;
        }
        if (in_array($call->ai_status, [CallLog::AI_STATUS_PENDING, CallLog::AI_STATUS_PROCESSING], true)) {
            return;
        }

        $call->update(['ai_status' => CallLog::AI_STATUS_PENDING, 'ai_error' => null]);
        TranscribeCallRecording::dispatch($call->id);
    }

    public function render()
    {
        $call = $this->authorizedCall(['contact', 'conversation', 'placedBy', 'notes.author']);

        return view('livewire.call-insights-panel', [
            'call' => $call,
            'context' => $call ? $this->buildContext($call) : null,
            'aiConfigured' => filled(config('services.gemini.key')),
        ]);
    }

    /**
     * Load the selected call and enforce the same per-call access rule as
     * CallController — company-wide viewers, the assigned agent, or the agent
     * who placed the call. Returns null when nothing is selected.
     *
     * @param  array<int, string>  $with
     */
    private function authorizedCall(array $with = []): ?CallLog
    {
        if (! $this->callId) {
            return null;
        }

        $call = CallLog::with($with)->find($this->callId);
        if ($call === null) {
            return null;
        }

        $user = auth()->user();
        $allowed = $user->can('conversations.view_all')
            || $call->placed_by_user_id === $user->id
            || $call->conversation?->assigned_to_user_id === $user->id;

        abort_unless($allowed, 403, 'You do not have access to this call.');

        return $call;
    }

    /**
     * Build the deterministic "context brief" from real data — this is what the
     * panel shows even when there's no AI transcript (recording off, or the
     * contact never had a chat). Never fabricated.
     *
     * @return array{engaged: bool, recentMessages: Collection, priorCalls: Collection}
     */
    private function buildContext(CallLog $call): array
    {
        $recentMessages = $call->conversation
            ? $call->conversation->messages()->latest()->limit(5)->get()->reverse()->values()
            : new Collection();

        $priorCalls = CallLog::query()
            ->where('contact_id', $call->contact_id)
            ->where('id', '!=', $call->id)
            ->latest()
            ->limit(4)
            ->get();

        return [
            'engaged' => (bool) $call->contact?->isEngaged(),
            'recentMessages' => $recentMessages,
            'priorCalls' => $priorCalls,
        ];
    }
}
