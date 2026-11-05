<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use Illuminate\Database\QueryException;

/**
 * Where lead-platform keys and the global kill switch live. Same pattern as
 * GeminiConfig / AiConfig: a key saved in Settings (encrypted) wins, the
 * environment is the fallback.
 */
final class LeadConfig
{
    public static function key(string $source): ?string
    {
        try {
            $saved = Setting::getEncrypted(self::keySetting($source));
            if (filled($saved)) {
                return $saved;
            }
        } catch (QueryException) {
            // No settings table (isolated test, pre-migration): fall back to env.
        }

        $env = (string) config("services.{$source}.key");

        return $env !== '' ? $env : null;
    }

    public static function setKey(string $source, string $key): void
    {
        Setting::setEncrypted(self::keySetting($source), $key);
    }

    /** One switch that halts pulling, qualifying and every automatic send. */
    public static function killSwitchOn(): bool
    {
        try {
            return Setting::get('leads_kill_switch') === '1';
        } catch (QueryException) {
            return false;
        }
    }

    public static function setKillSwitch(bool $on): void
    {
        Setting::set('leads_kill_switch', $on ? '1' : '0');
    }

    public static function pageSize(): int
    {
        return max(1, min(100, (int) config('leads.page_size', 25)));
    }

    private static function keySetting(string $source): string
    {
        return "lead_source_{$source}_key";
    }
}
