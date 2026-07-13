<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Voicemail;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Voicemail inbox. Single-tenant: anyone who can see conversations sees the
 * voicemails, mirroring the /calls visibility gate on the routes.
 */
class VoicemailController extends Controller
{
    public function index(): View
    {
        $voicemails = Voicemail::query()
            ->with(['contact', 'conversation', 'heardBy'])
            ->latest()
            ->paginate(30);

        return view('voicemails.index', [
            'voicemails' => $voicemails,
            'unheardCount' => Voicemail::where('is_heard', false)->count(),
        ]);
    }

    public function markHeard(Voicemail $voicemail): RedirectResponse
    {
        if (! $voicemail->is_heard) {
            $voicemail->update([
                'is_heard' => true,
                'heard_by_user_id' => auth()->id(),
                'heard_at' => now(),
            ]);
        }

        return back()->with('success', 'Voicemail marked as heard.');
    }
}
