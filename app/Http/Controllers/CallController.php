<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\Calling\CallClaimed;
use App\Events\Calling\CallRinging;
use App\Events\Calling\CallTerminated;
use App\Exceptions\ConfigurationException;
use App\Exceptions\VoiceProviderException;
use App\Http\Requests\StoreCallQualityRequest;
use App\Jobs\TerminateProviderCall;
use App\Jobs\TranscribeCallRecording;
use App\Models\CallLog;
use App\Models\CallNote;
use App\Models\Conversation;
use App\Models\Setting;
use App\Services\AfricasTalkingVoiceService;
use App\Services\CallQualityCalculator;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cross-conversation call feed (page at /calls).
 *
 * Visibility mirrors the inbox:
 *   - users with conversations.view_all see all calls in their account
 *   - users with conversations.view_assigned see calls only in conversations
 *     assigned to them
 *
 * Filterable by direction, status, and date range via query params.
 */
class CallController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        // Visibility scoping (single-tenant — fb5a398 flipped contacts/
        // conversations/campaigns to shared visibility; CallController was
        // missed in that pass):
        //   - conversations.view_all  → every call in the company
        //   - conversations.view_assigned → only calls on conversations
        //     currently assigned to me (agent workflow scope, unchanged)
        $scope = function ($q) use ($user) {
            if (! $user->can('conversations.view_all')) {
                $q->whereHas('conversation', fn ($c) => $c->where('assigned_to_user_id', $user->id));
            }
        };

        // Header trend widgets — computed from real data, scoped like the list.
        $todayCount = CallLog::query()->tap($scope)->whereDate('created_at', today())->count();
        $avgDurationSeconds = (int) round(
            (float) CallLog::query()->tap($scope)->where('duration_seconds', '>', 0)->avg('duration_seconds')
        );
        $providerCounts = CallLog::query()->tap($scope)
            ->selectRaw('provider, count(*) as aggregate')
            ->groupBy('provider')
            ->pluck('aggregate', 'provider');

        // Richer observability metrics — today, same visibility scope. One fetch
        // so the answer-rate / time-to-answer / MOS / failure-breakdown tiles
        // don't each cost a query.
        $todayCalls = CallLog::query()->tap($scope)
            ->whereDate('created_at', today())
            ->get(['status', 'connected_at', 'started_at', 'quality_metrics']);

        $answered = $todayCalls->whereNotNull('connected_at')->count();
        $missed = $todayCalls->where('status', CallLog::STATUS_MISSED)->count();
        $decisive = $answered + $missed;

        $timeToAnswer = $todayCalls
            ->filter(fn (CallLog $c) => $c->connected_at !== null && $c->started_at !== null)
            ->map(fn (CallLog $c) => max(0, $c->connected_at->getTimestamp() - $c->started_at->getTimestamp()));

        $mos = $todayCalls->map(fn (CallLog $c) => $c->quality_metrics['mos'] ?? null)->filter();

        $query = CallLog::query()->tap($scope)
            ->with(['contact', 'conversation', 'whatsappInstance', 'placedBy']);

        if ($direction = $request->query('direction')) {
            if (in_array($direction, ['inbound', 'outbound'], true)) {
                $query->where('direction', $direction);
            }
        }

        if ($status = $request->query('status')) {
            if (in_array($status, ['ended', 'missed', 'declined', 'failed'], true)) {
                $query->where('status', $status);
            }
        }

        $calls = $query->latest()->paginate(50)->withQueryString();

        return view('calls.index', [
            'calls' => $calls,
            'currentDirection' => $request->query('direction'),
            'currentStatus' => $request->query('status'),
            'stats' => [
                'todayCount' => $todayCount,
                'avgDurationSeconds' => $avgDurationSeconds,
                'providerCounts' => $providerCounts,
                'providerTotal' => (int) $providerCounts->sum(),
                // Observability (today, scoped).
                'answered' => $answered,
                'missed' => $missed,
                'answerRate' => $decisive > 0 ? (int) round($answered / $decisive * 100) : null,
                'avgTimeToAnswerSeconds' => $timeToAnswer->isNotEmpty() ? (int) round($timeToAnswer->avg()) : null,
                'avgMos' => $mos->isNotEmpty() ? round((float) $mos->avg(), 1) : null,
                'statusBreakdown' => $todayCalls->groupBy('status')->map->count(),
            ],
        ]);
    }

    /**
     * The unified agent Call Workspace: a live-call header, the recent-call
     * queue/history on the left, and a per-call AI + notes panel on the right.
     *
     * Visibility scoping matches {@see index()} — view_all sees the company's
     * calls, view_assigned sees only calls on their assigned conversations.
     */
    public function workspace(Request $request): View
    {
        $user = $request->user();

        $scope = function ($q) use ($user) {
            if (! $user->can('conversations.view_all')) {
                $q->whereHas('conversation', fn ($c) => $c->where('assigned_to_user_id', $user->id));
            }
        };

        $calls = CallLog::query()->tap($scope)
            ->with(['contact', 'conversation', 'placedBy'])
            ->withCount('notes')
            ->latest()
            ->limit(50)
            ->get();

        // The right panel opens on ?call=id when it's in scope, else the newest.
        $requestedId = (int) $request->query('call');
        $selected = $calls->firstWhere('id', $requestedId) ?? $calls->first();

        return view('calls.workspace', [
            'calls' => $calls,
            'selectedCallId' => $selected?->id,
            'recordingEnabled' => (bool) config('voice.call_recording_enabled'),
            'aiConfigured' => filled(config('services.gemini.key')),
        ]);
    }

    /**
     * Place an outbound PSTN call via Africa's Talking.
     */
    public function placeOutbound(Request $request, AfricasTalkingVoiceService $service): JsonResponse
    {
        $request->validate([
            'conversation_id' => 'required|exists:conversations,id',
        ]);

        $conversation = Conversation::findOrFail($request->input('conversation_id'));

        // Authorize: must have conversations.call AND either view_all
        // (any conversation in the company — single-tenant) or be
        // assigned-to the conversation (agent workflow scope).
        $user = $request->user();
        if (!$user->can('conversations.call')) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        $hasAccess = $user->can('conversations.view_all')
            || ($user->can('conversations.view_assigned') && $conversation->assigned_to_user_id === $user->id);
        if (!$hasAccess) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        try {
            $sessionId = $service->placeCall($conversation->contact->phone);
            $call = $this->recordOutboundAtCall($conversation, $sessionId);
            CallRinging::dispatch($call);

            return response()->json([
                'call_id' => $call->id,
                'session_id' => $sessionId,
            ]);
        } catch (VoiceProviderException | ConfigurationException $e) {
            $this->recordOutboundAtFailure($conversation, $e->getMessage());

            return response()->json([
                'error' => 'Voice service unavailable. Try again in a moment, or contact via WhatsApp message.',
            ], 503);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'Invalid phone number for this contact.',
            ], 422);
        }
    }

    /**
     * Atomic claim of an inbound ringing call by a specific browser session.
     *
     * First-tab-wins: an UPDATE that only matches when answered_by_session_id
     * is NULL or already this session id. Idempotent for the holder, 409 for
     * any other session.
     */
    public function claim(Request $request, CallLog $call): JsonResponse
    {
        $this->authorizeCallAccess($call);

        $sessionId = $request->input('session_id');
        if (!is_string($sessionId) || $sessionId === '' || strlen($sessionId) > 64) {
            return response()->json(['error' => 'invalid session_id'], 422);
        }

        $rowsAffected = DB::table('call_logs')
            ->where('id', $call->id)
            ->where(function ($q) use ($sessionId) {
                $q->whereNull('answered_by_session_id')
                  ->orWhere('answered_by_session_id', $sessionId);
            })
            ->update(['answered_by_session_id' => $sessionId]);

        if ($rowsAffected === 0) {
            return response()->json(['error' => 'already claimed in another window'], 409);
        }

        $call->refresh();
        CallClaimed::dispatch($call);

        return response()->json(['claimed' => true]);
    }

    /**
     * Send the agent's SDP answer back to Meta and persist for audit.
     * Requires that the same session_id has previously claimed the call.
     */
    public function answer(Request $request, CallLog $call, WhatsAppCloudApiService $service): JsonResponse
    {
        $this->authorizeCallAccess($call);

        $sessionId = $request->input('session_id');
        $sdp = $request->input('sdp');

        if ($call->answered_by_session_id !== $sessionId) {
            return response()->json(['error' => 'must claim before answering, or different session'], 409);
        }
        if (!is_string($sdp) || $sdp === '') {
            return response()->json(['error' => 'sdp required'], 422);
        }

        $service->acceptCall($call->whatsappInstance, $call->meta_call_id, $sdp);
        $call->update(['sdp_answer' => $sdp]);

        return response()->json(['accepted' => true]);
    }

    /**
     * Build the success CallLog row for an outbound AT call.
     *
     * Extracted from placeOutbound so the action method is purely the
     * authorisation + service-call + broadcast spine. Field overlap with
     * recordOutboundAtFailure() is ~80%; the diff is `status`/`provider_session_id`/
     * `started_at` vs `failure_reason`. Kept as two methods (not a builder
     * with optional args) because the call sites read more naturally
     * when each helper names its purpose.
     */
    private function recordOutboundAtCall(Conversation $conversation, string $sessionId): CallLog
    {
        return CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'whatsapp_instance_id' => $conversation->whatsapp_instance_id,
            'direction' => CallLog::DIRECTION_OUTBOUND,
            'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
            'provider_session_id' => $sessionId,
            'status' => CallLog::STATUS_INITIATED,
            'started_at' => now(),
            'placed_by_user_id' => auth()->id(),
            'from_phone' => Setting::get('africastalking_virtual_number'),
            'to_phone' => $conversation->contact->phone,
        ]);
    }

    /**
     * Build an audit CallLog row when the AT API rejected the dial.
     *
     * Keeps a failed attempt visible on the /calls history page so
     * operators can see "tried to call X, AT said Y" without grepping
     * laravel.log. from_phone falls back to '' when the virtual number
     * setting itself is the misconfiguration that caused the failure.
     */
    private function recordOutboundAtFailure(Conversation $conversation, string $reason): CallLog
    {
        return CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'whatsapp_instance_id' => $conversation->whatsapp_instance_id,
            'direction' => CallLog::DIRECTION_OUTBOUND,
            'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
            'status' => CallLog::STATUS_FAILED,
            'failure_reason' => $reason,
            'placed_by_user_id' => auth()->id(),
            'from_phone' => Setting::get('africastalking_virtual_number') ?? '',
            'to_phone' => $conversation->contact->phone,
        ]);
    }

    /**
     * Decline an inbound ringing call.
     */
    public function decline(CallLog $call): JsonResponse
    {
        $this->authorizeCallAccess($call);
        $this->terminate($call, CallLog::STATUS_DECLINED, 'declined');

        return response()->json(['declined' => true]);
    }

    /**
     * Hang up an in-progress call from the agent side.
     */
    public function hangup(CallLog $call): JsonResponse
    {
        $this->authorizeCallAccess($call);
        $this->terminate($call, CallLog::STATUS_ENDED, 'agent_hung_up');

        return response()->json(['ended' => true]);
    }

    /**
     * Ensure the acting user may control this specific call.
     *
     * Mirrors the ownership predicate already enforced by
     * {@see StoreCallQualityRequest::authorize()} and placeOutbound():
     * company-wide viewers (conversations.view_all), the agent the call's
     * conversation is assigned to, or the agent who placed an outbound call.
     *
     * Without this, any user holding conversations.reply could claim/answer/
     * decline/hangup ANY call by enumerating the integer call_logs.id —
     * terminating a colleague's live PSTN call, or claim+answer to intercept
     * the customer's audio. The four call-mutation endpoints were the only
     * ones missing this check.
     */
    private function authorizeCallAccess(CallLog $call): void
    {
        $user = auth()->user();

        $allowed = $user->can('conversations.view_all')
            || $call->placed_by_user_id === $user->id
            || $call->conversation?->assigned_to_user_id === $user->id;

        abort_unless($allowed, 403, 'You do not have access to this call.');
    }

    /**
     * Shared terminate flow for decline + hangup.
     *
     * Marks the call ended locally and broadcasts CallTerminated immediately so
     * the agent's (and every other session's) UI is instantly consistent. The
     * provider-side hangup is handed to {@see TerminateProviderCall}, a retried
     * job — so a transient provider failure no longer orphans the customer's
     * live leg the way the old best-effort inline call did.
     */
    private function terminate(CallLog $call, string $finalStatus, string $broadcastReason): void
    {
        // Hand the provider-side hangup to a retried background job. Guard the
        // dispatch so nothing about the provider call can fail the agent's
        // hangup request: on a real queue this only enqueues (retries happen on
        // the worker); on the sync queue it runs inline, so we swallow a failure
        // here exactly as the old best-effort inline call did.
        try {
            TerminateProviderCall::dispatch($call->id);
        } catch (\Throwable $e) {
            Log::warning('Provider terminate dispatch failed', [
                'call_id' => $call->id,
                'reason' => $broadcastReason,
                'error' => $e->getMessage(),
            ]);
        }

        $call->update([
            'status' => $finalStatus,
            'ended_at' => now(),
        ]);
        CallTerminated::dispatch($call, $broadcastReason);
    }

    /**
     * Accept the browser's recording of the call audio and kick off analysis.
     *
     * The mixed call audio (agent mic + remote leg) is captured client-side by
     * call-recorder.js and POSTed here on hangup. We store it on the PRIVATE
     * disk (never public — it's a customer conversation) and queue the Gemini
     * transcription. Gated by voice.call_recording_enabled so the whole pipeline
     * stays dark until consent handling is in place.
     */
    public function storeRecording(Request $request, CallLog $call): JsonResponse
    {
        $this->authorizeCallAccess($call);

        if (! config('voice.call_recording_enabled')) {
            return response()->json(['error' => 'Call recording is disabled.'], 403);
        }

        $maxKb = (int) config('voice.recording_max_kb', 25600);
        $request->validate([
            // Audit L2: validate the server-SNIFFED content type (mimetypes, not
            // the client-declared header), restricted to audio. MediaRecorder's
            // webm audio commonly sniffs as video/webm, so it's allowed too.
            'audio' => [
                'required', 'file', "max:{$maxKb}",
                'mimetypes:audio/webm,audio/ogg,audio/mpeg,audio/mp4,audio/wav,audio/x-wav,video/webm',
            ],
        ]);

        // store() uses the default (local) disk, rooted at storage/app/private —
        // so the audio is never web-accessible; it streams only via download().
        $path = $request->file('audio')->store('call-recordings');

        $hasKey = filled(config('services.gemini.key'));

        $call->update([
            'recording_path' => $path,
            // getMimeType() sniffs the file contents; getClientMimeType() would
            // trust the browser-declared header (audit L2).
            'recording_mime' => $this->normaliseAudioMime($request->file('audio')->getMimeType()),
            'recording_uploaded_at' => now(),
            // Only queue analysis if Gemini is configured; otherwise the recording
            // is kept but the panel shows "analysis unavailable".
            'ai_status' => $hasKey ? CallLog::AI_STATUS_PENDING : CallLog::AI_STATUS_UNAVAILABLE,
            'ai_error' => null,
        ]);

        if ($hasKey) {
            TranscribeCallRecording::dispatch($call->id);
        }

        return response()->json(['ok' => true, 'ai_status' => $call->ai_status]);
    }

    /**
     * Strip the codecs parameter MediaRecorder appends (e.g.
     * "audio/webm;codecs=opus" → "audio/webm") so the stored/forwarded MIME is
     * the bare container type Gemini expects.
     */
    private function normaliseAudioMime(?string $mime): string
    {
        $mime = trim(explode(';', (string) $mime)[0]);

        return $mime !== '' ? $mime : 'audio/webm';
    }

    /**
     * Blind-transfer a live call to another agent or a PSTN number.
     *
     * Records the destination on the call; the next AT call-control request
     * routes the customer leg there (see AfricasTalkingWebhookController::
     * handleCallControl). For an agent target we also reassign the conversation
     * and ring their softphone. The agent's own leg drops client-side after this
     * returns, prompting AT to re-request control — verify that re-request
     * behaviour on a live account before enabling.
     */
    public function transfer(Request $request, CallLog $call): JsonResponse
    {
        $this->authorizeCallAccess($call);

        if (! config('voice.transfer_enabled')) {
            return response()->json(['error' => 'Call transfer is disabled.'], 403);
        }

        $validated = $request->validate([
            'target_type' => ['required', 'in:agent,number'],
            'target_user_id' => ['nullable', 'required_if:target_type,agent', 'integer', 'exists:users,id'],
            'target_number' => ['nullable', 'required_if:target_type,number', 'string', 'max:32'],
        ]);

        if ($validated['target_type'] === 'agent') {
            $targetId = (int) $validated['target_user_id'];

            $call->update([
                'transfer_target' => AfricasTalkingVoiceService::clientNameForUser($targetId),
                'transferred_to_user_id' => $targetId,
                'transfer_type' => 'blind',
                'transferred_at' => now(),
            ]);

            // Reassign + ring the target agent's softphone so their banner shows
            // when AT bridges the transferred leg.
            $call->conversation?->update(['assigned_to_user_id' => $targetId]);
            CallRinging::dispatch($call->fresh());
        } else {
            $call->update([
                'transfer_target' => $validated['target_number'],
                'transfer_type' => 'blind',
                'transferred_at' => now(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Log an agent note against a call — append-only timeline entry.
     */
    public function storeNote(Request $request, CallLog $call): JsonResponse
    {
        $this->authorizeCallAccess($call);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $note = $call->notes()->create([
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        return response()->json([
            'id' => $note->id,
            'body' => $note->body,
            'author' => $request->user()->name,
            'created_at' => $note->created_at->toIso8601String(),
            'created_human' => $note->created_at->diffForHumans(),
        ], 201);
    }

    /**
     * Stream a call recording. Private-disk file, gated by the same per-call
     * access check as every other call mutation — recordings are customer audio.
     */
    public function downloadRecording(Request $request, CallLog $call): StreamedResponse
    {
        $this->authorizeCallAccess($call);

        abort_unless($call->hasRecording() && Storage::exists($call->recording_path), 404);

        return Storage::download(
            $call->recording_path,
            'call-'.$call->id.'-recording',
            ['Content-Type' => $call->recording_mime ?? 'application/octet-stream'],
        );
    }

    /**
     * Persist call-quality telemetry posted by the browser collector.
     *
     * Authorisation + validation live in StoreCallQualityRequest. The
     * action below is purely: compute MOS from the validated inputs,
     * merge into the JSON metrics payload, persist, return MOS for
     * the client to display.
     */
    public function quality(
        StoreCallQualityRequest $request,
        CallLog $call,
        CallQualityCalculator $calculator,
    ): JsonResponse {
        $validated = $request->validated();

        $mos = $calculator->computeMos(
            (float) $validated['avg_packet_loss_pct'],
            (float) $validated['avg_jitter_ms'],
            (int) $validated['avg_rtt_ms'],
        );

        $call->update(['quality_metrics' => [
            'avg_jitter_ms' => (float) $validated['avg_jitter_ms'],
            'avg_packet_loss_pct' => (float) $validated['avg_packet_loss_pct'],
            'avg_rtt_ms' => (int) $validated['avg_rtt_ms'],
            'samples_captured' => (int) $validated['samples_captured'],
            'ice_candidate_type' => $validated['ice_candidate_type'],
            'codec' => $validated['codec'],
            'mos' => $mos,
        ]]);

        return response()->json(['mos' => $mos]);
    }
}
