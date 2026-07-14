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
        if ($log->opened_at === null) {
            $log->forceFill(['opened_at' => now()]);
            // Don't clobber a failed/unsubscribed status — only a delivered send
            // becomes "opened".
            if ($log->status === EmailLog::STATUS_SENT) {
                $log->status = EmailLog::STATUS_OPENED;
            }
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
