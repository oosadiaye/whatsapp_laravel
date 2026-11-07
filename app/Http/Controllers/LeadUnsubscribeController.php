<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\Leads\Outreach\LeadSuppressor;
use Illuminate\View\View;

/** One-click unsubscribe in an outreach email (signed URL, no login). */
class LeadUnsubscribeController extends Controller
{
    public function unsubscribe(Lead $lead, LeadSuppressor $suppressor): View
    {
        $suppressor->suppress($lead, 'Clicked unsubscribe in an email.', 'lead');

        return view('email.unsubscribed', ['email' => (string) $lead->email]);
    }
}
