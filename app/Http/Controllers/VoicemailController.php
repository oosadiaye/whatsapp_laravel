<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Voicemail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

/**
 * Voicemail inbox. Visibility mirrors the calls feed: users with
 * conversations.view_all see every voicemail; users with view_assigned see only
 * voicemails whose conversation is assigned to them.
 */
class VoicemailController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        $query = Voicemail::query()->with(['contact', 'conversation', 'heardBy']);

        // view_assigned agents only see voicemails on their assigned conversations.
        if (! $user->can('conversations.view_all')) {
            $query->whereHas('conversation', fn ($q) => $q->where('assigned_to_user_id', $user->id));
        }

        return view('voicemails.index', [
            'voicemails' => $query->latest()->paginate(30),
            'unheardCount' => (clone $query)->where('is_heard', false)->count(),
        ]);
    }

    /**
     * Stream a voicemail recording through the app instead of exposing the raw
     * Africa's Talking URL. Per-voicemail access is enforced (not just the coarse
     * route permission), and the upstream URL is validated to an AT-host
     * allowlist before fetching to prevent SSRF.
     */
    public function download(Voicemail $voicemail): Response
    {
        $this->authorizeVoicemailAccess($voicemail);

        abort_if(blank($voicemail->recording_url), 404);
        $this->assertSafeRecordingUrl((string) $voicemail->recording_url);

        $upstream = Http::withOptions(['allow_redirects' => false])
            ->timeout(15)
            ->get($voicemail->recording_url);
        abort_unless($upstream->successful(), 404);

        return response($upstream->body(), 200, [
            'Content-Type' => $upstream->header('Content-Type') ?: 'audio/mpeg',
            'Content-Disposition' => 'inline; filename="voicemail-'.$voicemail->id.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function markHeard(Voicemail $voicemail): RedirectResponse
    {
        $this->authorizeVoicemailAccess($voicemail);

        if (! $voicemail->is_heard) {
            $voicemail->update([
                'is_heard' => true,
                'heard_by_user_id' => auth()->id(),
                'heard_at' => now(),
            ]);
        }

        return back()->with('success', 'Voicemail marked as heard.');
    }

    /**
     * A user may access a voicemail if they can see every conversation, or the
     * voicemail's conversation is assigned to them. Mirrors
     * CallController::authorizeCallAccess.
     */
    private function authorizeVoicemailAccess(Voicemail $voicemail): void
    {
        $user = auth()->user();

        $allowed = $user->can('conversations.view_all')
            || ($voicemail->conversation?->assigned_to_user_id === $user->id
                && $user->can('conversations.view_assigned'));

        abort_unless($allowed, 403, 'You do not have access to this voicemail.');
    }

    /**
     * SSRF guard: the recording URL is fetched server-side, so restrict it to
     * https on an explicit host allowlist. Redirects are disabled by the caller
     * so a 302 to an internal address can't bypass this.
     */
    private function assertSafeRecordingUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));
        $allowed = (array) config('voice.voicemail.allowed_hosts', []);

        abort_unless($scheme === 'https' && $host !== '' && in_array($host, $allowed, true), 404);
    }
}
