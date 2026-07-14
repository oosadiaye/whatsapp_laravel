<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailSuppression;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public one-click unsubscribe. The link in every campaign email is a Laravel
 * signed URL (route 'email.unsubscribe' behind the `signed` middleware), so the
 * address can't be tampered with. Visiting it adds the address to the global
 * suppression list — every future send skips it.
 */
class UnsubscribeController extends Controller
{
    public function show(Request $request): View
    {
        $email = trim((string) $request->query('email', ''));

        if ($email !== '') {
            EmailSuppression::suppress($email, EmailSuppression::REASON_UNSUBSCRIBE);
        }

        return view('email.unsubscribed', ['email' => $email]);
    }
}
