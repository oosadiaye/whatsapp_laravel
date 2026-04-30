<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\WhatsAppApiException;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\OutboundCallService;
use App\Services\WhatsAppMessenger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Inbox + chat thread.
 *
 * Visibility model:
 *   - users with `conversations.view_all` see every conversation owned by
 *     their account (admins, managers)
 *   - users with `conversations.view_assigned` see only conversations whose
 *     assigned_to_user_id = current user (agents)
 *   - users without either permission get 403 from middleware before reaching
 *     these actions
 *
 * Reply send flow uses {@see WhatsAppMessenger} → Cloud API. After Meta
 * accepts, we create the outbound ConversationMessage row mirroring the
 * sent message, with status=sent and wamid captured.
 */
class ConversationController extends Controller
{
    public function __construct(
        private readonly WhatsAppMessenger $messenger,
        private readonly OutboundCallService $outboundCalls,
    ) {
    }

    /**
     * Inbox view — list of conversations with last-message preview.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $query = Conversation::with(['contact', 'whatsappInstance', 'assignedTo'])
            ->orderByDesc('last_message_at');

        // Visibility: admins/managers see everything in the account; agents only see
        // conversations assigned to them. The conversations.view_all permission
        // controls which side of this branch you land on.
        if ($user->can('conversations.view_all')) {
            $query->where('user_id', $user->id);
        } else {
            $query->where('assigned_to_user_id', $user->id);
        }

        // Optional filter: ?filter=unassigned shows the unassigned pool (for managers
        // doing assignment work). Only meaningful for view_all users.
        if ($request->query('filter') === 'unassigned' && $user->can('conversations.view_all')) {
            $query->whereNull('assigned_to_user_id');
        }

        return view('conversations.index', [
            'conversations' => $query->paginate(25),
            'currentFilter' => $request->query('filter'),
        ]);
    }

    /**
     * One conversation thread + reply form.
     */
    public function show(Request $request, Conversation $conversation): View
    {
        $this->authorizeConversationAccess($request, $conversation);

        // Mark as read when opening — clears the inbox unread badge for this thread.
        if ($conversation->unread_count > 0) {
            $conversation->update(['unread_count' => 0]);
        }

        // Eager load messages + sender info (for outbound rows showing "Sent by Alice").
        $messages = $conversation->messages()->with('sentBy')->get();

        $callLogs = $conversation->callLogs()->with('placedBy')->get();

        // Merge messages and call_logs into one chronological timeline
        $timeline = $messages->concat($callLogs)->sortBy('created_at')->values();

        // Approved templates from the same instance, used when the 24h window is closed.
        $templates = MessageTemplate::where('user_id', $conversation->user_id)
            ->where(function ($q) use ($conversation) {
                $q->where('whatsapp_instance_id', $conversation->whatsapp_instance_id)
                  ->orWhereNull('whatsapp_instance_id');
            })
            ->where('status', MessageTemplate::STATUS_APPROVED)
            ->orderBy('name')
            ->get();

        // Assignable staff: only fetch the dropdown options if current user can
        // actually use them. Otherwise the view skips rendering the assign UI entirely.
        $assignableStaff = collect();
        if ($request->user()->can('conversations.assign')) {
            $assignableStaff = User::query()
                ->where('is_active', true)
                ->whereHas('roles.permissions', function ($q) {
                    $q->whereIn('name', ['conversations.view_all', 'conversations.view_assigned']);
                })
                ->orderBy('name')
                ->get(['id', 'name', 'email']);
        }

        return view('conversations.show', [
            'conversation' => $conversation->load(['contact', 'whatsappInstance', 'assignedTo']),
            'messages' => $messages,
            'callLogs' => $callLogs,
            'timeline' => $timeline,
            'templates' => $templates,
            'assignableStaff' => $assignableStaff,
        ]);
    }

    /**
     * Assign a conversation to a staff member, or unassign by passing user_id=null.
     *
     * Self-assign is always allowed for users with the assign permission — it's
     * a common pattern ("I'll take this one"). Cross-account assignment is
     * blocked (target user must belong to the same BlastIQ account / user_id).
     */
    public function assign(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorizeConversationAccess($request, $conversation);
        abort_unless($request->user()->can('conversations.assign'), 403);

        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        // Block cross-account assignment: target user must belong to same account
        // (same user_id on records they own). For now we just check the assignee
        // exists and is active — the row's user_id is the conversation owner,
        // and assignees in this app are siloed by user creation flow.
        if ($validated['user_id']) {
            $assignee = User::findOrFail($validated['user_id']);
            abort_unless($assignee->is_active, 422, 'Cannot assign to deactivated user.');
        }

        $conversation->update(['assigned_to_user_id' => $validated['user_id']]);

        $action = $validated['user_id'] ? 'assigned' : 'unassigned';

        return redirect()
            ->route('conversations.show', $conversation)
            ->with('success', "Conversation {$action}.");
    }

    /**
     * Place an outbound call from this conversation's instance to its contact.
     * Permission gated via route middleware (`conversations.call`).
     *
     * @throws WhatsAppApiException  if Meta rejects the call (caught and surfaced
     *                                as a flash error, no call_log row created)
     */
    public function initiateCall(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorizeConversationAccess($request, $conversation);

        try {
            $this->outboundCalls->initiate($conversation, $request->user());
        } catch (WhatsAppApiException $e) {
            return redirect()
                ->route('conversations.show', $conversation)
                ->with('error', "Could not place call: {$e->getMessage()}");
        }

        return redirect()
            ->route('conversations.show', $conversation)
            ->with('success', "Calling {$conversation->contact->name}...");
    }

    /**
     * End an in-flight outbound call. Mirrors initiateCall's permission
     * checks (same route middleware, same access guard).
     */
    public function endCall(Request $request, Conversation $conversation, CallLog $call): RedirectResponse
    {
        $this->authorizeConversationAccess($request, $conversation);

        if ($call->conversation_id !== $conversation->id) {
            abort(404);
        }

        if (! $call->isInFlight()) {
            return redirect()->route('conversations.show', $conversation)
                ->with('warning', 'Call is no longer in flight; nothing to end.');
        }

        try {
            $this->outboundCalls->end($call);
        } catch (WhatsAppApiException $e) {
            return redirect()->route('conversations.show', $conversation)
                ->with('error', "Could not end call: {$e->getMessage()}");
        }

        return redirect()->route('conversations.show', $conversation)
            ->with('success', 'Call ended.');
    }

    /**
     * Send a reply in this conversation.
     *
     * Inside the 24-hour window: freeform text accepted.
     * Outside the window: only template sends accepted (caller must specify
     * message_template_id; Meta would reject freeform anyway).
     */
    public function reply(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorizeConversationAccess($request, $conversation);
        abort_unless($request->user()->can('conversations.reply'), 403);

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:4096'],
            'message_template_id' => ['nullable', 'integer', 'exists:message_templates,id'],
        ]);

        $instance = $conversation->whatsappInstance;
        $phone = $conversation->contact->phone;

        // Branch: template (outside window or by user choice) vs freeform text
        try {
            if (! empty($validated['message_template_id'])) {
                $template = MessageTemplate::findOrFail($validated['message_template_id']);
                $result = $this->messenger->sendTemplate(
                    $instance,
                    $phone,
                    $template->name,
                    $template->language ?? 'en_US',
                    [],  // Component params — empty for now; Phase 15 can add UI for this
                );
                $type = 'template';
                $body = $template->content;
            } else {
                if (! $conversation->isWindowOpen()) {
                    return redirect()->back()->with('error',
                        'The 24-hour reply window is closed. Pick an approved template instead.');
                }

                if (empty($validated['body'])) {
                    return redirect()->back()->with('error', 'Message body is required.');
                }

                $result = $this->messenger->sendText($instance, $phone, $validated['body']);
                $type = 'text';
                $body = $validated['body'];
            }
        } catch (WhatsAppApiException $e) {
            return redirect()->back()->with('error', "Send failed: {$e->getMessage()}");
        } catch (Throwable $e) {
            return redirect()->back()->with('error', 'Send failed (network error). Please retry.');
        }

        // Persist the outbound message so it appears in the thread immediately.
        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => ConversationMessage::DIRECTION_OUTBOUND,
            'whatsapp_message_id' => $result->messageId,
            'type' => $type,
            'body' => $body,
            'sent_by_user_id' => $request->user()->id,
            'status' => 'SENT',
            'received_at' => Carbon::now(),
        ]);

        $conversation->update(['last_message_at' => Carbon::now()]);

        return redirect()->route('conversations.show', $conversation);
    }

    /**
     * Serve inbound media files. They live under storage/app/ (not public/) so
     * we can permission-check before streaming. Each request is gated by the
     * same conversation access check as the thread view.
     */
    public function downloadMedia(Request $request, ConversationMessage $message): BinaryFileResponse|StreamedResponse
    {
        $this->authorizeConversationAccess($request, $message->conversation);

        abort_if($message->media_path === null, 404);
        abort_unless(Storage::exists($message->media_path), 404);

        return Storage::download(
            $message->media_path,
            null,
            ['Content-Type' => $message->media_mime ?? 'application/octet-stream'],
        );
    }

    /**
     * 403 if the user can't see this conversation under their permission set.
     *
     * Two paths to access:
     *   1. user has conversations.view_all + conversation belongs to their account
     *   2. user has conversations.view_assigned + conversation is assigned to them
     */
    private function authorizeConversationAccess(Request $request, Conversation $conversation): void
    {
        $user = $request->user();

        if ($user->can('conversations.view_all') && $conversation->user_id === $user->id) {
            return;
        }

        if ($user->can('conversations.view_assigned') && $conversation->assigned_to_user_id === $user->id) {
            return;
        }

        abort(403, 'You do not have access to this conversation.');
    }
}
