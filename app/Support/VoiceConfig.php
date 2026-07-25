<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use Illuminate\Database\QueryException;

/**
 * Resolves the compliance-sensitive call-recording settings from one place so
 * the endpoint, the workspace, and the browser recorder all agree.
 *
 * Precedence mirrors {@see GeminiConfig}: the Settings-page value (DB) wins over
 * the VOICE_CALL_RECORDING_ENABLED env default — an operator can enable/disable
 * recording from the UI (which forces them to record a consent notice) without a
 * redeploy. Guarded so isolated/pre-migrate contexts fall back to config.
 */
final class VoiceConfig
{
    public static function recordingEnabled(): bool
    {
        try {
            $setting = Setting::get('voice_recording_enabled');
            if ($setting !== null) {
                return (string) $setting === '1';
            }
        } catch (QueryException) {
            // No settings table yet — fall back to the env-backed config.
        }

        return (bool) config('voice.call_recording_enabled');
    }

    public static function recordingConsentNotice(): ?string
    {
        try {
            $notice = Setting::get('voice_recording_consent_notice');
            if (filled($notice)) {
                return (string) $notice;
            }
        } catch (QueryException) {
            // ignore — no notice available
        }

        return null;
    }

    /**
     * Days to keep recording audio before the prune sweeper deletes it (keeping
     * the transcript). 0 = keep forever. DB setting wins over the env config.
     */
    public static function recordingRetentionDays(): int
    {
        try {
            $setting = Setting::get('voice_recording_retention_days');
            if ($setting !== null && $setting !== '') {
                return max(0, (int) $setting);
            }
        } catch (QueryException) {
            // No settings table — fall back to the env-backed config.
        }

        return max(0, (int) config('voice.recording_retention_days', 0));
    }
}
