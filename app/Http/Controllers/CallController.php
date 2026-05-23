<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\Calling\CallClaimed;
use App\Events\Calling\CallRinging;
use App\Events\Calling\CallTerminated;
use App\Exceptions\ConfigurationException;
use App\Exceptions\VoiceProviderException;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\Setting;
use App\Services\AfricasTalkingVoiceService;
use App\Services\CallQualityCalculator;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

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

        $query = CallLog::query()->with(['contact', 'conversation', 'whatsappInstance', 'placedBy']);

        // Visibility scoping (single-tenant — fb5a398 flipped contacts/
        // conversations/campaigns to shared visibility; CallController was
        // missed in that pass):
        //   - conversations.view_all  → every call in the company
        //   - conversations.view_assigned → only calls on conversations
        //     currently assigned to me (agent workflow scope, unchanged)
        if (! $user->can('conversations.view_all')) {
            $query->whereHas('conversation', fn ($q) => $q->where('assigned_to_user_id', $user->id));
        }

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

        $calls = $query->latest()->paginate(50);

        return view('calls.index', [
            'calls' => $calls,
            'currentDirection' => $request->query('direction'),
            'currentStatus' => $request->query('status'),
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
        $this->terminate($call, CallLog::STATUS_DECLINED, 'declined');

        return response()->json(['declined' => true]);
    }

    /**
     * Hang up an in-progress call from the agent side.
     */
    public function hangup(CallLog $call): JsonResponse
    {
        $this->terminate($call, CallLog::STATUS_ENDED, 'agent_hung_up');

        return response()->json(['ended' => true]);
    }

    /**
     * Shared terminate flow for decline + hangup.
     *
     * Routes to the right provider terminate endpoint (Meta vs Africa's
     * Talking), then updates local state and broadcasts CallTerminated.
     *
     * Provider failures are non-fatal — we still mark the call ended
     * locally and notify other browser sessions. The customer may briefly
     * hear silence before the natural disconnect, but the agent's UI is
     * consistent. (Future improvement: enqueue a retry job for the
     * provider-side terminate so the customer's side hangs up reliably.)
     */
    private function terminate(CallLog $call, string $finalStatus, string $broadcastReason): void
    {
        $context = ['call_id' => $call->id, 'reason' => $broadcastReason];
        try {
            if ($call->provider === CallLog::PROVIDER_AFRICAS_TALKING) {
                app(AfricasTalkingVoiceService::class)->endCall($call->provider_session_id);
            } else {
                app(WhatsAppCloudApiService::class)
                    ->endCall($call->whatsappInstance, $call->meta_call_id);
            }
        } catch (\Throwable $e) {
            // Log + continue. Customer-side may not hear the hangup tone if
            // the provider call fails, but the agent's UI must still end
            // cleanly — letting an exception bubble would surface as a 500
            // and leave the call_log in an in-flight state.
            Log::warning('Provider endCall failed during terminate', $context + ['error' => $e->getMessage()]);
        }

        $call->update([
            'status' => $finalStatus,
            'ended_at' => now(),
        ]);
        CallTerminated::dispatch($call, $broadcastReason);
    }

    public function quality(
        Request $request,
        CallLog $call,
        CallQualityCalculator $calculator,
    ): JsonResponse {
        // Ownership check: only the agent who placed/answered may post.
        // Outbound: matches placed_by_user_id.
        // Inbound: matches the parent conversation's assigned_to_user_id.
        $userId = auth()->id();
        $owns = $call->placed_by_user_id === $userId
            || $call->conversation?->assigned_to_user_id === $userId;
        if (! $owns) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $validated = $request->validate([
            'avg_jitter_ms' => ['required', 'numeric', 'min:0', 'max:10000'],
            'avg_packet_loss_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'avg_rtt_ms' => ['required', 'integer', 'min:0', 'max:60000'],
            'samples_captured' => ['required', 'integer', 'min:0', 'max:1000'],
            'ice_candidate_type' => ['required', 'string', 'in:host,srflx,relay,prflx,unknown'],
            'codec' => ['required', 'string', 'max:32'],
        ]);

        $mos = $calculator->computeMos(
            (float) $validated['avg_packet_loss_pct'],
            (float) $validated['avg_jitter_ms'],
            (int) $validated['avg_rtt_ms'],
        );

        $metrics = [
            'avg_jitter_ms' => (float) $validated['avg_jitter_ms'],
            'avg_packet_loss_pct' => (float) $validated['avg_packet_loss_pct'],
            'avg_rtt_ms' => (int) $validated['avg_rtt_ms'],
            'samples_captured' => (int) $validated['samples_captured'],
            'ice_candidate_type' => $validated['ice_candidate_type'],
            'codec' => $validated['codec'],
            'mos' => $mos,
        ];

        $call->update(['quality_metrics' => $metrics]);

        return response()->json(['mos' => $mos]);
    }
}
