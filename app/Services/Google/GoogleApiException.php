<?php

declare(strict_types=1);

namespace App\Services\Google;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A Google call that failed, with a message written for the person (never Google's raw response body, which can echo
 * request details). `needsReauth` means the saved permission is gone and they must connect again.
 */
final class GoogleApiException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $needsReauth = false, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $status = $response->status();
        $json = $response->json() ?? [];
        // Google API errors nest an object under `error`; OAuth token errors put a plain string there. Read only scalars.
        $reasons = [data_get($json, 'error.errors.0.reason'), data_get($json, 'error.status'), data_get($json, 'error')];
        $reason = (string) (collect($reasons)->first(fn ($v) => is_string($v) && $v !== '') ?? '');
        $detailRaw = data_get($json, 'error.message') ?? data_get($json, 'error_description');
        $detail = is_string($detailRaw) ? strtolower($detailRaw) : '';

        // Wrong app credentials come back as HTTP 401 too, so they must be recognised BEFORE the generic 401 rule:
        // telling someone to "reconnect" when the Client secret is wrong would send them in circles.
        if ($reason === 'invalid_client' || $reason === 'unauthorized_client') {
            return new self('Google rejected the Client ID or Client secret. Check both in Settings -> Google Calendar.', false, $status);
        }
        if ($reason === 'redirect_uri_mismatch') {
            return new self('The redirect address is not registered in Google Cloud. Copy the "Authorized redirect URI" shown in Settings into your OAuth client.', false, $status);
        }
        if ($status === 401 || $reason === 'invalid_grant' || $reason === 'UNAUTHENTICATED' || $reason === 'authError') {
            return new self('Google no longer accepts the saved connection (access was removed or expired). Connect Google Calendar again.', true, $status);
        }
        if (in_array($reason, ['accessNotConfigured', 'SERVICE_DISABLED'], true) || str_contains($detail, 'has not been used') || str_contains($detail, 'is disabled')) {
            return new self('The Google Calendar API is switched off for your Google Cloud project. In Google Cloud Console open APIs & Services, Library, search "Google Calendar API" and press Enable.', false, $status);
        }
        if (in_array($reason, ['insufficientPermissions', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT'], true)) {
            return new self('Google did not grant calendar access. Disconnect, connect again, and tick the calendar permission on Google\'s screen.', true, $status);
        }
        if ($status === 429 || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'RESOURCE_EXHAUSTED'], true)) {
            return new self('Google is limiting requests right now. Try again in a minute.', false, $status);
        }
        if ($status === 404 || $status === 410) {
            return new self('Google could not find that calendar entry (it may have been deleted there).', false, $status);
        }
        if ($status >= 500) {
            return new self('Google is having trouble right now. Try again shortly.', false, $status);
        }

        return new self('Google refused the request (HTTP '.$status.'). Check the connection in Settings -> Google Calendar.', false, $status);
    }
}
