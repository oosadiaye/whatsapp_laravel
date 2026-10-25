<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tracks signed-in browser sessions and lets an admin end them.
 *
 * Enforcement is the revoked_at flag, checked by TrackUserSession on the
 * browser's next request. Destroying the stored session payload as well is only a
 * best-effort extra: an in-flight request from that browser can rewrite the
 * session after we destroy it, so the flag is the authority.
 */
final class UserSessionMonitor
{
    /** Throttle for last_active_at writes (the app polls every ~15s per tab). */
    public const TOUCH_INTERVAL_SECONDS = 30;

    /** A session active this recently counts as "online" (matches call-routing availability). */
    public const ONLINE_WINDOW_MINUTES = 2;

    /** Revoked rows are kept this long so the browser still gets signed out. */
    private const REVOKED_RETENTION_HOURS = 24;

    /**
     * Record activity for the current request's session.
     *
     * @return bool false when this session was revoked and must be signed out
     */
    public function record(Request $request, User $user): bool
    {
        $sessionId = $request->session()->getId();
        $session = UserSession::where('session_id', $sessionId)->first();

        if ($session !== null && $session->user_id !== $user->id) {
            // A session id never legitimately changes hands; start clean.
            $session->delete();
            $session = null;
        }

        if ($session === null) {
            try {
                UserSession::create([
                    'user_id' => $user->id,
                    'session_id' => $sessionId,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'last_active_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // A parallel request created it first; nothing more to do.
            }

            if (random_int(1, 100) === 1) {
                $this->prune();
            }

            return true;
        }

        if ($session->revoked_at !== null) {
            return false;
        }

        if ($session->last_active_at === null
            || $session->last_active_at->lt(now()->subSeconds(self::TOUCH_INTERVAL_SECONDS))) {
            $session->forceFill([
                'last_active_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ])->save();
        }

        return true;
    }

    public function revoke(UserSession $session): void
    {
        if ($session->revoked_at === null) {
            $session->forceFill(['revoked_at' => now()])->save();
        }

        try {
            app('session')->driver()->getHandler()->destroy($session->session_id);
        } catch (Throwable $e) {
            Log::warning('Could not destroy session payload for revoked session', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Sign a user out of every live session (optionally sparing one), and — when
     * nothing is spared — stop the call router treating them as available.
     */
    public function revokeAllForUser(User $user, ?string $exceptSessionId = null): int
    {
        $sessions = UserSession::live()
            ->where('user_id', $user->id)
            ->when($exceptSessionId !== null, fn ($q) => $q->where('session_id', '!=', $exceptSessionId))
            ->get();

        foreach ($sessions as $session) {
            $this->revoke($session);
        }

        if ($exceptSessionId === null) {
            $user->forceFill(['last_seen_at' => null])->save();
        }

        return $sessions->count();
    }

    /** Drop expired sessions and revocations old enough that the browser is long gone. */
    public function prune(): void
    {
        $lifetime = (int) config('session.lifetime', 120);

        UserSession::where('last_active_at', '<', now()->subMinutes($lifetime))->delete();
        UserSession::whereNotNull('revoked_at')
            ->where('revoked_at', '<', now()->subHours(self::REVOKED_RETENTION_HOURS))
            ->delete();
    }

    /** 'online' (recent), 'idle' (session still valid but quiet), otherwise 'offline'. */
    public static function state(UserSession $session): string
    {
        if ($session->revoked_at !== null || $session->last_active_at === null) {
            return 'offline';
        }

        return $session->last_active_at->gte(now()->subMinutes(self::ONLINE_WINDOW_MINUTES)) ? 'online' : 'idle';
    }

    /** "Chrome on Windows" — enough for an admin to recognise a device. */
    public static function describeAgent(?string $userAgent): string
    {
        if ($userAgent === null || $userAgent === '') {
            return 'Unknown device';
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/'), str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        $os = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $os !== null ? "{$browser} on {$os}" : $browser;
    }
}
