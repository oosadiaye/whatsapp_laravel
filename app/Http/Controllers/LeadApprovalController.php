<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LeadEvent;
use App\Models\LeadMessage;
use App\Models\LeadReply;
use App\Services\Leads\Outreach\LeadAttention;
use App\Services\Leads\Outreach\LeadMessageSender;
use App\Support\LeadConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The queue of AI drafts waiting for a person, and replies that need one. */
class LeadApprovalController extends Controller
{
    public function index(): View
    {
        $replies = LeadReply::query()
            ->with(['lead', 'message'])
            ->whereIn('status', [LeadReply::STATUS_NEEDS_HUMAN, LeadReply::STATUS_NEW])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $view = view('lead-approvals.index', [
            'messages' => LeadMessage::query()
                ->with(['lead.profile', 'reply'])
                ->whereIn('status', [LeadMessage::STATUS_PENDING, LeadMessage::STATUS_FAILED])
                ->orderBy('id')
                ->limit(100)
                ->get(),
            'replies' => $replies,
            'killSwitch' => LeadConfig::killSwitchOn(),
        ]);

        LeadReply::query()->whereIn('id', $replies->pluck('id'))->whereNull('seen_at')->update(['seen_at' => now()]);

        return $view;
    }

    public function approve(Request $request, LeadMessage $message, LeadMessageSender $sender): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'min:2', 'max:150'],
            'body' => ['nullable', 'string', 'min:10', 'max:1500'],
        ]);

        if (! in_array($message->status, [LeadMessage::STATUS_PENDING, LeadMessage::STATUS_FAILED], true)) {
            return back()->with('warning', 'That email has already been handled.');
        }

        $edited = (isset($data['subject']) && $data['subject'] !== $message->subject)
            || (isset($data['body']) && trim($data['body']) !== trim($message->body));

        $message->forceFill([
            'subject' => $data['subject'] ?? $message->subject,
            'body' => isset($data['body']) ? trim($data['body']) : $message->body,
            'by_ai' => $edited ? false : $message->by_ai,
            'status' => LeadMessage::STATUS_APPROVED,
            'decided_by' => $request->user()->id,
            'failure' => null,
        ])->save();

        LeadEvent::record($message->lead, 'draft_approved', ['message_id' => $message->id, 'edited' => $edited], (string) $request->user()->id);

        $result = $sender->send($message);

        return match ($result) {
            LeadMessageSender::SENT => back()->with('success', 'Approved and sent.'),
            LeadMessageSender::WAITING => back()->with('success', 'Approved. It will go out as soon as automation is running and today\'s send limit allows.'),
            LeadMessageSender::DISCARDED => back()->with('warning', 'Approved, but it was not sent: '.($message->fresh()->failure ?: 'the lead can no longer be emailed.')),
            default => back()->with('error', 'Approved, but it could not be sent: '.($message->fresh()->failure ?: 'see the profile for details.')),
        };
    }

    public function discard(Request $request, LeadMessage $message): RedirectResponse
    {
        if (! in_array($message->status, [LeadMessage::STATUS_PENDING, LeadMessage::STATUS_FAILED], true)) {
            return back()->with('warning', 'That email has already been handled.');
        }

        $message->forceFill(['status' => LeadMessage::STATUS_DISCARDED, 'decided_by' => $request->user()->id, 'failure' => 'Discarded by a person.'])->save();
        LeadEvent::record($message->lead, 'draft_discarded', ['message_id' => $message->id], (string) $request->user()->id);

        return back()->with('success', 'Draft discarded.');
    }

    public function resolveReply(Request $request, LeadReply $reply): RedirectResponse
    {
        $reply->forceFill(['status' => LeadReply::STATUS_HANDLED, 'seen_at' => $reply->seen_at ?? now()])->save();
        LeadEvent::record($reply->lead, 'reply_resolved', ['reply_id' => $reply->id], (string) $request->user()->id);

        return back()->with('success', 'Marked as dealt with.');
    }

    public function attention(): JsonResponse
    {
        return response()->json(LeadAttention::counts());
    }
}
