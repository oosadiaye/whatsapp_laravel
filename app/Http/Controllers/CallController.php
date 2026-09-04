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
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Setting;
use App\Models\WhatsAppInstance;
use App\Services\AfricasTalkingVoiceService;
use App\Services\CallQualityCalculator;
use App\Services\ContactImportService;
use App\Services\WhatsAppCloudApiService;
use App\Support\GeminiConfig;
use App\Support\VoiceConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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

        // "Today" is defined in the business timezone (matching the dashboard),
        // then bounded as a half-open UTC range so the query stays sargable on
        // the created_at index — whereDate() wraps the column in a function and
        // defeats the index (audit M2).
        $startOfToday = Carbon::now(config('app.business_timezone'))->startOfDay()->utc();
        $endOfToday = $startOfToday->copy()->addDay();
        $todayScoped = fn () => CallLog::query()->tap($scope)
            ->where('created_at', '>=', $startOfToday)
            ->where('created_at', '<', $endOfToday);

        // Header trend widgets — computed from real data, scoped like the list.
        $todayCount = $todayScoped()->count();
        $avgDurationSeconds = (int) round(
            (float) CallLog::query()->tap($scope)->where('duration_seconds', '>', 0)->avg('duration_seconds')
        );
        $providerCounts = CallLog::query()->tap($scope)
            ->selectRaw('provider, count(*) as aggregate')
            ->groupBy('provider')
            ->pluck('aggregate', 'provider');

        // Observability tiles (today, same visibility scope). Counts + the
        // failure breakdown are SQL aggregates instead of materialising every
        // row (audit L11); only time-to-answer (a timestamp diff) and MOS
        // (buried in the quality_metrics JSON) fetch the narrow columns they
        // actually need, and only for the rows that have the data.
        $answered = $todayScoped()->whereNotNull('connected_at')->count();
        $missed = $todayScoped()->where('status', CallLog::STATUS_MISSED)->count();
        $decisive = $answered + $missed;

        $statusBreakdown = $todayScoped()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        // These two tiles compute an average in PHP (a timestamp diff, and a value
        // buried in quality_metrics JSON), so they must hydrate rows rather than
        // use a SQL aggregate like the counts above. Cap the hydration so a very
        // high-volume day can't blow up page memory — on such a day the average
        // becomes an estimate over a large sample, which is fine for a dashboard.
        $tileSampleCap = 10000;

        $timeToAnswer = $todayScoped()
            ->whereNotNull('connected_at')
            ->whereNotNull('started_at')
            ->limit($tileSampleCap)
            ->get(['started_at', 'connected_at'])
            ->map(fn (CallLog $c) => max(0, $c->connected_at->getTimestamp() - $c->started_at->getTimestamp()));

        $mos = $todayScoped()
            ->whereNotNull('quality_metrics')
            ->limit($tileSampleCap)
            ->get(['quality_metrics'])
            ->map(fn (CallLog $c) => $c->quality_metrics['mos'] ?? null)
            ->filter();

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
                'statusBreakdown' => $statusBreakdown,
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
            ->when($q = $request->query('q'), fn ($qry, $q) => $qry->where(function ($sub) use ($q) {
                $sub->where('from_phone', 'like', "%{$q}%")
                    ->orWhere('to_phone', 'like', "%{$q}%")
                    ->orWhereHas('contact', fn ($c) => $c->where('name', 'like', "%{$q}%"));
            }))
            ->when($dir = $request->query('dir'), fn ($qry, $dir) => $qry->where('direction', $dir))
            ->when($status = $request->query('status'), fn ($qry, $status) => $qry->where('status', $status))
            ->latest()
            ->limit(50)
            ->get();

        // The right panel opens on ?call=id when it's in scope, else the newest.
        $requestedId = (int) $request->query('call');
        $selected = $calls->firstWhere('id', $requestedId) ?? $calls->first();

        // Dial pad (calls.dial): the contact list is no longer serialized into
        // the page. It is fetched lazily by the Quick Dial modal from
        // route('calls.contacts') on first open (see resources/js + workspace.blade).
        // Require BOTH calls.dial (see the feature) AND conversations.call (the
        // gate on the calls.outbound endpoint Quick Dial posts to). Gating on
        // calls.dial alone rendered the pad for a role that then 403'd on every
        // Start Call — a silent dead-end.
        $canDial = (bool) $user->can('calls.dial') && (bool) $user->can('conversations.call');

        // Wrap-up prompt: only for a call THIS user just handled — one they placed
        // or one on a conversation assigned to them — and only recently. Using the
        // page $scope meant a view_all manager got a company-wide, never-expiring
        // nag for anyone's undispositioned call on every page load (M3).
        $activeCall = CallLog::query()
            ->where(function ($q) use ($user) {
                $q->where('placed_by_user_id', $user->id)
                    ->orWhereHas('conversation', fn ($c) => $c->where('assigned_to_user_id', $user->id));
            })
            ->whereNull('disposition')
            ->whereNotNull('ended_at')
            ->where('ended_at', '>=', now()->subMinutes(15))
            ->latest('ended_at')
            ->first();

        return view('calls.workspace', [
            'calls' => $calls,
            'selectedCallId' => $selected?->id,
            'recordingEnabled' => VoiceConfig::recordingEnabled(),
            'aiConfigured' => filled(GeminiConfig::key()),
            'canDial' => $canDial,
            'activeCall' => $activeCall,
        ]);
    }

    /**
     * Lazy contact list for the Quick Dial picker (route calls.contacts).
     *
     * Previously the first 500 contacts were serialized into every workspace
     * page load. This endpoint is fetched on demand (modal open) so the heavy
     * payload only travels when the agent actually dials. Bounded for safety.
     */
    public function dialContacts(Request $request): JsonResponse
    {
        if (! $request->user()->can('calls.dial')) {
            return response()->json([]);
        }

        $contacts = Contact::whereNotNull('phone')
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'phone'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name ?? $c->phone,
                'phone' => $c->phone,
            ])
            ->values();

        return response()->json($contacts);
    }

    /**
     * Place an outbound PSTN call via Africa's Talking.
     *
     * Accepts conversation_id (existing flow) OR phone + optional contact_id
     * for direct calling without needing a chat thread first.
     */
    public function placeOutbound(Request $request, AfricasTalkingVoiceService $service, ContactImportService $normalizer): JsonResponse
    {
        // Operational kill-switch (config/voice.php · VOICE_OUTBOUND_CALLS_ENABLED,
        // defaults ON). Lets ops stop the app placing PSTN calls during a billing
        // spike / provider incident without pulling AT credentials (which would
        // also break inbound + softphone token minting).
        if (! config('voice.outbound_calls_enabled', true)) {
            return response()->json([
                'error' => 'Outbound calling is temporarily disabled.',
            ], 503);
        }

        $key = 'outbound-call:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json(['error' => 'rate_limit'], 429);
        }
        RateLimiter::hit($key, 60);

        $request->validate([
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
            'phone' => ['nullable', 'string', 'max:32', 'required_without:conversation_id'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
        ]);

        $user = $request->user();

        if ($conversationId = $request->input('conversation_id')) {
            $conversation = Conversation::findOrFail($conversationId);
        } else {
            $instance = WhatsAppInstance::primary();
            if ($instance === null) {
                return response()->json(['error' => 'WhatsApp not configured.'], 503);
            }

            if ($contactId = $request->input('contact_id')) {
                $contact = Contact::findOrFail($contactId);
            } else {
                $phone = $normalizer->normalizePhone((string) $request->input('phone'), Setting::get('default_country_code'));
                if ($phone === null || $phone === '') {
                    return response()->json(['error' => 'Invalid phone number.'], 422);
                }
                $contact = Contact::firstOrCreateIncludingTrashed(
                    ['user_id' => $instance->user_id, 'phone' => $phone],
                    ['name' => $phone, 'is_active' => true],
                );
            }

            $conversation = Conversation::firstOrCreate(
                ['contact_id' => $contact->id, 'whatsapp_instance_id' => $instance->id],
                ['user_id' => $instance->user_id, 'unread_count' => 0],
            );

            if ($conversation->assigned_to_user_id === null) {
                $conversation->update(['assigned_to_user_id' => $user->id]);
            }
        }

        if (! $user->can('conversations.call')) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        $hasAccess = $user->can('conversations.view_all')
            || ($user->can('conversations.view_assigned') && $conversation->assigned_to_user_id === $user->id);
        if (! $hasAccess) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        try {
            $sessionId = $service->placeCall($conversation->contact->phone);
        } catch (ConfigurationException $e) {
            $this->recordOutboundAtFailure($conversation, $e->getMessage());

            return response()->json([
                'error' => 'Voice calling isn’t set up yet — an admin needs to add the Africa’s Talking credentials in Settings → Voice.',
            ], 503);
        } catch (VoiceProviderException $e) {
            $this->recordOutboundAtFailure($conversation, $e->getMessage());

            return response()->json([
                // Persistent, not transient — surface AT's OWN reason (e.g. "AT
                // rejected call: InsufficientCredit" / "InvalidSenderId" / HTTP 401)
                // so the operator can fix the actual cause instead of guessing.
                'error' => 'Africa’s Talking rejected the call: '.\Illuminate\Support\Str::limit($e->getMessage(), 160)
                    .'. Common causes: an invalid API key, no voice credit, or the virtual number isn’t voice-enabled for outbound. '
                    .'Verify in Settings → Voice (“Test voice connection”).',
            ], 503);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'Invalid phone number for this contact.',
            ], 422);
        }

        // The AT call now exists on the provider. If persisting its CallLog
        // fails, the answered call would reach call-control, find no matching
        // row, and get <Reject/> — an orphaned live leg the app can neither see
        // nor control (still connected, still billing). Tear it back down
        // best-effort rather than leaking it.
        try {
            $call = $this->recordOutboundAtCall($conversation, $sessionId);
        } catch (\Throwable $e) {
            Log::error('Outbound AT call placed but CallLog persist failed; terminating orphaned leg', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            try {
                $service->endCall($sessionId);
            } catch (\Throwable $inner) {
                Log::warning('Failed to terminate orphaned AT call', [
                    'session_id' => $sessionId,
                    'error' => $inner->getMessage(),
                ]);
            }

            return response()->json([
                'error' => 'Voice service unavailable. Try again in a moment, or contact via WhatsApp message.',
            ], 503);
        }

        // Outbound CallRinging: the placing agent's own client filters this out
        // (app.js rings only direction==='inbound'), so it doesn't ring anyone
        // today — but it's the canonical "call is now live" signal on the agent's
        // channel, asserted by CallControllerOutboundTest and a ready hook for
        // outbound-progress UI. Kept intentionally; the payload is tiny.
        CallRinging::dispatch($call);

        return response()->json([
            'call_id' => $call->id,
            'session_id' => $sessionId,
        ]);
    }

    /**
     * Workspace dial pad: place an outbound call to an arbitrary number or a
     * chosen contact, without needing an existing conversation. Resolves (or
     * creates, soft-delete-safe) the contact + conversation, assigns it to the
     * dialer so they can view + control the call, then hands off to the
     * conversation page which auto-starts the call through the proven softphone
     * flow (?dial=1). The actual PSTN leg still goes through placeOutbound —
     * this method only prepares the destination. Gated by calls.dial.
     */
    public function dial(Request $request, ContactImportService $normalizer): RedirectResponse
    {
        $key = 'outbound-call:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->with('error', "Rate limit reached. Try again in {$seconds} seconds.");
        }
        RateLimiter::hit($key, 60);

        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:32'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
        ]);

        if (blank($data['phone'] ?? null) && blank($data['contact_id'] ?? null)) {
            return back()->with('error', 'Enter a number or pick a contact to call.');
        }

        // Calls are recorded against the single WhatsApp number — without it
        // there's no instance to key the contact/conversation to.
        $instance = WhatsAppInstance::primary();
        if ($instance === null) {
            return back()->with('error', 'Configure your WhatsApp number in Settings first — calls are recorded against it.');
        }

        // An explicit contact pick wins; otherwise normalise the typed number
        // and find-or-revive the contact for it (soft-delete-safe).
        if (filled($data['contact_id'] ?? null)) {
            $contact = Contact::where('user_id', $instance->user_id)->find($data['contact_id']);
            if ($contact === null) {
                return back()->with('error', 'That contact could not be found.');
            }
        } else {
            $phone = $normalizer->normalizePhone((string) $data['phone'], Setting::get('default_country_code'));
            if ($phone === null || $phone === '') {
                return back()->with('error', 'That does not look like a valid phone number.');
            }
            $contact = Contact::firstOrCreateIncludingTrashed(
                ['user_id' => $instance->user_id, 'phone' => $phone],
                ['name' => $phone, 'is_active' => true],
            );
        }

        $conversation = Conversation::firstOrCreate(
            ['contact_id' => $contact->id, 'whatsapp_instance_id' => $instance->id],
            ['user_id' => $instance->user_id, 'unread_count' => 0],
        );

        // Own the conversation so the dialer passes the outbound gate (view_all
        // OR assigned-to-me). Only claims it when it isn't already someone's.
        if ($conversation->assigned_to_user_id === null) {
            $conversation->update(['assigned_to_user_id' => $request->user()->id]);
        }

        return redirect()->route('conversations.show', ['conversation' => $conversation, 'dial' => 1]);
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
        if (! is_string($sessionId) || $sessionId === '' || strlen($sessionId) > 64) {
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

        // Require a real, prior claim. An empty session_id must be rejected up
        // front — otherwise an UNCLAIMED call (answered_by_session_id === null) +
        // a missing session_id makes the check below `null !== null` (false) and
        // bypasses claim()'s first-tab-wins guarantee.
        if (! is_string($sessionId) || $sessionId === '') {
            return response()->json(['error' => 'session_id required'], 422);
        }
        if ($call->answered_by_session_id !== $sessionId) {
            return response()->json(['error' => 'must claim before answering, or different session'], 409);
        }
        if (! is_string($sdp) || $sdp === '') {
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
        // Idempotency guard: only the first request actually transitions the
        // call and fires the teardown side-effects. A double-clicked Drop or
        // two tabs racing to hang up would otherwise dispatch duplicate
        // TerminateProviderCall jobs and duplicate CallTerminated broadcasts
        // (and could resurrect the provider hang-up on a second pass).
        $transitioned = DB::table('call_logs')
            ->where('id', $call->id)
            ->whereIn('status', CallLog::STATUSES_IN_FLIGHT)
            ->update([
                'status' => $finalStatus,
                'ended_at' => now(),
            ]);

        if ($transitioned === 0) {
            // Already terminal — someone else ended it. Nothing more to do.
            return;
        }

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

        CallTerminated::dispatch($call->fresh(), $broadcastReason);
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

        if (! VoiceConfig::recordingEnabled()) {
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

        $hasKey = filled(GeminiConfig::key());

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
    public function transfer(Request $request, CallLog $call, ContactImportService $normalizer): JsonResponse
    {
        $this->authorizeCallAccess($call);

        if (! config('voice.transfer_enabled')) {
            return response()->json(['error' => 'Call transfer is disabled.'], 403);
        }

        // Transferring bridges a live call — it is a calling action. Require the
        // calling permission explicitly (the route sits under conversations.reply,
        // so the gate alone doesn't enforce it), independent of the seeded roles
        // that happen to pair the two today.
        abort_unless((bool) $request->user()->can('conversations.call'), 403);

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
            // Dialing an arbitrary PSTN number — apply the SAME safety controls as
            // placeOutbound(), which this path previously bypassed entirely: the
            // operational kill-switch, the per-user outbound rate limit, and E.164
            // normalization (raw string|max:32 was persisted and dialed verbatim).
            if (! config('voice.outbound_calls_enabled', true)) {
                return response()->json(['error' => 'Outbound calling is temporarily disabled.'], 503);
            }

            $key = 'outbound-call:'.$request->user()->id;
            if (RateLimiter::tooManyAttempts($key, 10)) {
                return response()->json(['error' => 'rate_limit'], 429);
            }
            RateLimiter::hit($key, 60);

            // normalizePhone validates + canonicalizes to digits-only; the AT <Dial>
            // target must be +E.164 (see the webhook dial), so re-prepend '+'.
            $digits = $normalizer->normalizePhone((string) $validated['target_number'], Setting::get('default_country_code'));
            if ($digits === null || $digits === '') {
                return response()->json(['error' => 'Invalid phone number.'], 422);
            }

            $call->update([
                'transfer_target' => '+'.$digits,
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
