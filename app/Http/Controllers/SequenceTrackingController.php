<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailSequence;
use App\Models\EmailSequenceRecipient;
use App\Models\EmailSuppression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Open-tracking + unsubscribe for email-sequence messages (signed URLs only).
 *
 * Sequences send through the per-account mailbox path (UserMail), which has no
 * EmailLog row — the {@see EmailSequenceRecipient} IS the attribution target, so
 * the pixel updates its open_count/status and the unsubscribe marks it
 * UNSUBSCRIBED + adds the address to the global suppression list.
 */
class SequenceTrackingController extends Controller
{
    // 1x1 transparent GIF (same payload as the campaign open pixel).
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function open(EmailSequenceRecipient $recipient): Response
    {
        // First open only, and only for a delivered step — a leaked/pixel-prefetched
        // URL for a never-sent recipient must not inflate engagement.
        if ($recipient->open_count === 0
            && in_array($recipient->status, [EmailSequence::RECIPIENT_SENT, EmailSequence::RECIPIENT_OPENED], true)) {
            $recipient->update([
                'open_count' => 1,
                'status' => EmailSequence::RECIPIENT_OPENED,
            ]);
        }

        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Content-Length' => '43',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function unsubscribe(EmailSequenceRecipient $recipient): View
    {
        EmailSuppression::suppress($recipient->email, EmailSuppression::REASON_UNSUBSCRIBE);
        $recipient->update([
            'status' => EmailSequence::RECIPIENT_UNSUBSCRIBED,
            'completed_at' => now(),
        ]);

        return view('email.unsubscribed', ['email' => $recipient->email]);
    }

    /**
     * Click-through redirect. The signed URL carries the original destination in
     * the `url` query param (bound into the signature, so it can't be swapped to
     * a phishing target). Records the click on the recipient and sends them on.
     */
    public function click(EmailSequenceRecipient $recipient, Request $request): RedirectResponse
    {
        $target = (string) $request->query('url', '');

        // Only http(s) targets are legal — a signed URL carrying javascript: or a
        // bare path is a tampered/malformed link.
        if (! preg_match('#^https?://#i', $target)) {
            abort(400);
        }

        // Count genuine engagement from a delivered step only; a leaked link for
        // a never-sent / unsubscribed recipient still redirects but records nothing.
        if (in_array($recipient->status, [
            EmailSequence::RECIPIENT_SENT,
            EmailSequence::RECIPIENT_OPENED,
            EmailSequence::RECIPIENT_CLICKED,
        ], true)) {
            $recipient->increment('click_count');
            if ($recipient->status !== EmailSequence::RECIPIENT_CLICKED) {
                $recipient->update(['status' => EmailSequence::RECIPIENT_CLICKED]);
            }
        }

        return redirect()->away($target);
    }
}
