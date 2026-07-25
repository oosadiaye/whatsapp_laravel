<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use Illuminate\Database\QueryException;

/**
 * Resolves the Gemini API key from a single place so the whole call-AI pipeline
 * agrees on "is AI configured?".
 *
 * Precedence: the Settings-page value (DB, encrypted at rest) wins over the
 * GEMINI_API_KEY env default — so an operator can turn AI on/off from the UI
 * without a redeploy, while env still works for infra-managed secrets.
 */
final class GeminiConfig
{
    public static function key(): ?string
    {
        try {
            $fromSettings = Setting::getEncrypted('gemini_api_key');
            if (filled($fromSettings)) {
                return $fromSettings;
            }
        } catch (QueryException) {
            // No settings table (isolated unit test, or before migrations run) —
            // fall through to the env default rather than crash key resolution.
        }

        $fromEnv = (string) config('services.gemini.key');

        return $fromEnv !== '' ? $fromEnv : null;
    }
}
