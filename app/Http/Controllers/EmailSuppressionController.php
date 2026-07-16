<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailSuppression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The do-not-email list. Populated automatically by unsubscribes; this screen
 * lets operators view it and add/remove addresses manually (e.g. a bounce or
 * complaint reported out-of-band, until automated provider ingestion exists).
 */
class EmailSuppressionController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $suppressions = EmailSuppression::query()
            ->when($q !== '', fn ($query) => $query->where('email', 'like', '%'.EmailSuppression::normalize($q).'%'))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('email-campaigns.suppressions', ['suppressions' => $suppressions, 'q' => $q]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'reason' => ['nullable', 'in:unsubscribe,bounce,complaint,manual'],
        ]);

        EmailSuppression::suppress($data['email'], $data['reason'] ?? EmailSuppression::REASON_MANUAL);

        return back()->with('success', 'Address added to the suppression list.');
    }

    public function destroy(EmailSuppression $suppression): RedirectResponse
    {
        $suppression->delete();

        return back()->with('success', 'Address removed from the suppression list.');
    }
}
