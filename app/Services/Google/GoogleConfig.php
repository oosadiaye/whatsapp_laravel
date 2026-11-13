<?php

declare(strict_types=1);

namespace App\Services\Google;

use App\Models\Setting;

/** Where the Google OAuth client comes from: Settings (encrypted secret) first, then .env. */
final class GoogleConfig
{
    /** openid + email identify the account; calendar.events reads, creates, changes and deletes events (incl. Meet links). */
    public const SCOPES = 'openid email https://www.googleapis.com/auth/calendar.events';

    public const CALENDAR_SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    public static function clientId(): ?string
    {
        $value = (string) Setting::get('google_client_id', '');

        return $value !== '' ? $value : (config('services.google_calendar.client_id') ?: null);
    }

    public static function clientSecret(): ?string
    {
        $value = Setting::getEncrypted('google_client_secret');

        return filled($value) ? $value : (config('services.google_calendar.client_secret') ?: null);
    }

    public static function isConfigured(): bool
    {
        return filled(self::clientId()) && filled(self::clientSecret());
    }

    /** The exact address that must be listed under "Authorized redirect URIs" in Google Cloud. */
    public static function redirectUri(): string
    {
        return route('google.callback');
    }
}
