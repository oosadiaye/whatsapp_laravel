<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Voicemail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
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

    /**
     * Stream a voicemail recording through the app instead of exposing the raw
     * Africa's Talking URL in the page. The route enforces the same conversation-
     * visibility gate as the inbox, so playback is authenticated — the AT URL is
     * never handed to the browser.
     */
    public function download(Voicemail $voicemail): Response
    {
        abort_if(blank($voicemail->recording_url), 404);

        $upstream = Http::timeout(15)->get($voicemail->recording_url);
        abort_unless($upstream->successful(), 404);

        return response($upstream->body(), 200, [
            'Content-Type' => $upstream->header('Content-Type') ?: 'audio/mpeg',
            'Content-Disposition' => 'inline; filename="voicemail-'.$voicemail->id.'"',
            'Cache-Control' => 'private, no-store',
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
