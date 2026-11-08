<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\Leads\Outreach\LeadSuppressor;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Unsubscribe link in an outreach email (signed URL, no login).
 *
 * GET only asks for confirmation: mail scanners and link previewers open every link with GET and
 * must not be able to unsubscribe someone. POST does the work, whether it comes from the confirm
 * button or from a mail client's RFC 8058 one-click unsubscribe.
 */
class LeadUnsubscribeController extends Controller
{
    public function confirm(Request $request, Lead $lead): View
    {
        return view('email.unsubscribe-confirm', [
            'email' => (string) $lead->email,
            'action' => $request->fullUrl(),
        ]);
    }

    public function unsubscribe(Lead $lead, LeadSuppressor $suppressor): View
    {
        $suppressor->suppress($lead, 'Clicked unsubscribe in an email.', 'lead');

        return view('email.unsubscribed', ['email' => (string) $lead->email]);
    }
}
