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

        if ($user->can('conversations.view_all')) {
            // Account-wide visibility — restrict to calls whose conversation
            // belongs to the current user's account.
            $query->whereHas('conversation', fn ($q) => $q->where('user_id', $user->id));
        } else {
            // Agent visibility — only conversations assigned to me
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

        // Authorize: must have conversations.call AND own/be-assigned-to the conversation.
        $user = $request->user();
        if (!$user->can('conversations.call')) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        $hasAccess = ($user->can('conversations.view_all') && $conversation->user_id === $user->id)
            || ($user->can('conversations.view_assigned') && $conversation->assigned_to_user_id === $user->id);
        if (!$hasAccess) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        try {
            $sessionId = $service->placeCall($conversation->contact->phone);

            $call = CallLog::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact_id,
                'whatsapp_instance_id' => $conversation->whatsapp_instance_id,
                'direction' => 'outbound',
                'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
                'provider_session_id' => $sessionId,
                'status' => CallLog::STATUS_INITIATED,
                'started_at' => now(),
                'placed_by_user_id' => auth()->id(),
                'from_phone' => Setting::get('africastalking_virtual_number'),
                'to_phone' => $conversation->contact->phone,
            ]);

            CallRinging::dispatch($call);

            return response()->json([
                'call_id' => $call->id,
                'session_id' => $sessionId,
            ]);
        } catch (VoiceProviderException | ConfigurationException $e) {
            // Audit row for the failure.
            CallLog::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact_id,
                'whatsapp_instance_id' => $conversation->whatsapp_instance_id,
                'direction' => 'outbound',
                'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
                'status' => CallLog::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
                'placed_by_user_id' => auth()->id(),
                'from_phone' => Setting::get('africastalking_virtual_number') ?? '',
                'to_phone' => $conversation->contact->phone,
            ]);

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
     * Decline an inbound ringing call. Routes to the right provider terminate endpoint.
     */
    public function decline(CallLog $call): JsonResponse
    {
        if ($call->provider === CallLog::PROVIDER_AFRICAS_TALKING) {
            try {
                app(AfricasTalkingVoiceService::class)->endCall($call->provider_session_id);
            } catch (\Throwable $e) {
                Log::warning('AT endCall failed during decline', [
                    'call_id' => $call->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            app(WhatsAppCloudApiService::class)
                ->endCall($call->whatsappInstance, $call->meta_call_id);
        }

        $call->update([
            'status' => CallLog::STATUS_DECLINED,
            'ended_at' => now(),
        ]);
        CallTerminated::dispatch($call, 'declined');

        return response()->json(['declined' => true]);
    }

    /**
     * Hang up an in-progress call from the agent side. Routes to the right provider terminate endpoint.
     */
    public function hangup(CallLog $call): JsonResponse
    {
        if ($call->provider === CallLog::PROVIDER_AFRICAS_TALKING) {
            try {
                app(AfricasTalkingVoiceService::class)->endCall($call->provider_session_id);
            } catch (\Throwable $e) {
                Log::warning('AT endCall failed during hangup', [
                    'call_id' => $call->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            app(WhatsAppCloudApiService::class)
                ->endCall($call->whatsappInstance, $call->meta_call_id);
        }

        $call->update([
            'status' => CallLog::STATUS_ENDED,
            'ended_at' => now(),
        ]);
        CallTerminated::dispatch($call, 'agent_hung_up');

        return response()->json(['ended' => true]);
    }
}
