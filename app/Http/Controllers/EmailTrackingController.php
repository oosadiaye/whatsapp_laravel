<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailLog;
use Illuminate\Http\Response;

/**
 * Open tracking. Each campaign email embeds a 1x1 pixel pointing at a signed URL
 * for that recipient's EmailLog; the mail client fetching it records the open.
 *
 * Note: open tracking is best-effort — image-blocking clients and Apple Mail
 * Privacy Protection suppress or pre-fetch it, so counts are a lower/upper bound,
 * not exact. First open only is counted.
 */
class EmailTrackingController extends Controller
{
    // 1x1 transparent GIF.
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function open(EmailLog $log): Response
    {
        // Only a delivered send can be "opened". A QUEUED/FAILED/UNSUBSCRIBED log
        // that somehow gets its signed pixel fetched (leaked URL, prefetch before
        // send) must not inflate the campaign's open rate.
        if ($log->opened_at === null && in_array($log->status, [EmailLog::STATUS_SENT, EmailLog::STATUS_OPENED], true)) {
            // Check-then-act is the pragmatic choice here (a signed URL, low
            // concurrency); the fetch is idempotent on the opened_at guard.
            $log->forceFill(['opened_at' => now(), 'status' => EmailLog::STATUS_OPENED]);
            $log->save();

            $log->campaign?->increment('opened_count');
        }

        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Content-Length' => '43',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
