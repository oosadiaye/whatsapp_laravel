<?php

declare(strict_types=1);

namespace App\Services\Google;

use App\Models\GoogleConnection;
use Illuminate\Support\Facades\Http;

/**
 * Google's OAuth 2.0 web-server flow (authorization code). All endpoints are fixed constants, so no user-supplied
 * URL is ever fetched.
 */
class GoogleOAuth
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public function authorizationUrl(string $state, ?string $loginHint = null): string
    {
        return self::AUTH_URL.'?'.http_build_query(array_filter([
            'client_id' => GoogleConfig::clientId(),
            'redirect_uri' => GoogleConfig::redirectUri(),
            'response_type' => 'code',
            'scope' => GoogleConfig::SCOPES,
            // offline + consent: Google only returns a refresh token (needed to keep syncing) when asked this way.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'login_hint' => $loginHint,
        ]), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Trade the one-time code for tokens and look up which Google account was connected.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_at: \Illuminate\Support\Carbon, scopes: string, email: string}
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->acceptJson()->timeout(20)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => GoogleConfig::clientId(),
            'client_secret' => GoogleConfig::clientSecret(),
            'redirect_uri' => GoogleConfig::redirectUri(),
            'grant_type' => 'authorization_code',
        ]);
        if (! $response->successful()) {
            throw GoogleApiException::fromResponse($response);
        }

        $token = (string) $response->json('access_token');
        $info = Http::withToken($token)->acceptJson()->timeout(20)->get(self::USERINFO_URL);
        if (! $info->successful() || blank($info->json('email'))) {
            throw new GoogleApiException('Google did not tell us which account was connected. Please try again.');
        }

        return [
            'access_token' => $token,
            'refresh_token' => $response->json('refresh_token'),
            'expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
            'scopes' => (string) $response->json('scope', ''),
            'email' => (string) $info->json('email'),
        ];
    }

    /** Get a fresh access token. A revoked permission surfaces as GoogleApiException with needsReauth. */
    public function refresh(GoogleConnection $connection): void
    {
        if (blank($connection->refresh_token)) {
            $connection->forceFill(['status' => GoogleConnection::STATUS_NEEDS_REAUTH, 'last_error' => 'Google did not give a long-lived permission. Connect again.'])->save();
            throw new GoogleApiException('Google did not give a long-lived permission. Connect Google Calendar again.', true);
        }

        $response = Http::asForm()->acceptJson()->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => GoogleConfig::clientId(),
            'client_secret' => GoogleConfig::clientSecret(),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            $error = GoogleApiException::fromResponse($response);
            if ($error->needsReauth) {
                $connection->forceFill(['status' => GoogleConnection::STATUS_NEEDS_REAUTH, 'last_error' => $error->getMessage()])->save();
            }
            throw $error;
        }

        $connection->forceFill([
            'access_token' => (string) $response->json('access_token'),
            'expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
            'status' => GoogleConnection::STATUS_ACTIVE,
        ])->save();
    }

    /** Best effort: tell Google to forget the permission. A failure here must never block disconnecting. */
    public function revoke(GoogleConnection $connection): void
    {
        try {
            $token = $connection->refresh_token ?: $connection->access_token;
            Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $token]);
        } catch (\Throwable) {
            // ignore
        }
    }
}
