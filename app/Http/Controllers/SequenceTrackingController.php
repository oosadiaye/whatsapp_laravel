<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailSequence;
use App\Models\EmailSequenceRecipient;
use App\Models\EmailSuppression;
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
}
