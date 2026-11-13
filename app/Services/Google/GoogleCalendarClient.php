<?php

declare(strict_types=1);

namespace App\Services\Google;

use App\Models\GoogleConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Google Calendar v3 REST API on behalf of one connected person. Refreshes the access token when
 * it is about to expire (and once more if Google says it is no longer valid). Every failure is a GoogleApiException
 * with a plain-language message.
 */
class GoogleCalendarClient
{
    private const BASE = 'https://www.googleapis.com/calendar/v3';

    /** Hard stop for paging so a huge calendar can never keep a request or job running indefinitely. */
    private const MAX_PAGES = 10;

    public function __construct(
        private readonly GoogleConnection $connection,
        private readonly GoogleOAuth $oauth,
    ) {}

    public static function for(GoogleConnection $connection): self
    {
        return new self($connection, app(GoogleOAuth::class));
    }

    /**
     * Every event that starts in the window, repeating events expanded, cancelled ones included (so deletions show).
     *
     * @return list<array<string, mixed>>
     */
    public function listEvents(Carbon $from, Carbon $to): array
    {
        $items = [];
        $pageToken = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->send('GET', '/calendars/primary/events', array_filter([
                'timeMin' => $from->clone()->utc()->toIso8601String(),
                'timeMax' => $to->clone()->utc()->toIso8601String(),
                'singleEvents' => 'true',
                'showDeleted' => 'true',
                'orderBy' => 'startTime',
                'maxResults' => 250,
                'pageToken' => $pageToken,
            ]));

            foreach ((array) $response->json('items', []) as $item) {
                $items[] = $item;
            }

            $pageToken = $response->json('nextPageToken');
            if ($pageToken === null) {
                return $items;
            }
        }

        throw new GoogleApiException('Your Google calendar has more events than can be read in one go. Narrow it down or try again.');
    }

    /**
     * A cheap read that proves the connection works end to end.
     *
     * @return array{timeZone: ?string, calendar: ?string}
     */
    public function probe(): array
    {
        $response = $this->send('GET', '/calendars/primary/events', ['maxResults' => 1, 'timeMin' => now()->utc()->toIso8601String(), 'singleEvents' => 'true']);

        return ['timeZone' => $response->json('timeZone'), 'calendar' => $response->json('summary')];
    }

    /**
     * Create an event; Google emails the guests. With $withMeet a Google Meet room is created and attached.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed> the created event (id, htmlLink, hangoutLink ...)
     */
    public function insertEvent(array $event, bool $withMeet): array
    {
        return $this->send('POST', '/calendars/primary/events', [
            'sendUpdates' => 'all',
            'conferenceDataVersion' => $withMeet ? 1 : 0,
        ], $event)->json();
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function patchEvent(string $eventId, array $changes): array
    {
        return $this->send('PATCH', '/calendars/primary/events/'.rawurlencode($eventId), ['sendUpdates' => 'all'], $changes)->json();
    }

    /** Delete (cancel) an event and tell the guests. Already gone counts as done. */
    public function deleteEvent(string $eventId): void
    {
        try {
            $this->send('DELETE', '/calendars/primary/events/'.rawurlencode($eventId), ['sendUpdates' => 'all']);
        } catch (GoogleApiException $e) {
            if (! in_array($e->httpStatus, [404, 410], true)) {
                throw $e;
            }
        }
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $json
     */
    private function send(string $method, string $path, array $query = [], ?array $json = null, bool $retried = false): Response
    {
        $this->ensureFreshToken();

        $request = Http::withToken($this->connection->access_token)->acceptJson()->timeout(25);
        $url = self::BASE.$path.($query !== [] ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');

        $response = match ($method) {
            'GET' => $request->get($url),
            'DELETE' => $request->delete($url),
            default => $request->send($method, $url, ['json' => $json ?? []]),
        };

        if ($response->status() === 401 && ! $retried) {
            $this->oauth->refresh($this->connection);

            return $this->send($method, $path, $query, $json, true);
        }
        if (! $response->successful()) {
            $error = GoogleApiException::fromResponse($response);
            if ($error->needsReauth) {
                $this->connection->forceFill(['status' => GoogleConnection::STATUS_NEEDS_REAUTH, 'last_error' => $error->getMessage()])->save();
            }
            throw $error;
        }

        return $response;
    }

    private function ensureFreshToken(): void
    {
        if (! $this->connection->isUsable()) {
            throw new GoogleApiException('Google Calendar needs to be connected again.', true);
        }
        if ($this->connection->expires_at === null || $this->connection->expires_at->lte(now()->addSeconds(60))) {
            $this->oauth->refresh($this->connection);
        }
    }
}
