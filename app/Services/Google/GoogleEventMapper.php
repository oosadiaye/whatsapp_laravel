<?php

declare(strict_types=1);

namespace App\Services\Google;

use App\Models\Meeting;
use App\Models\MeetingAttendee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Pure translation between a BlastIQ Meeting and a Google Calendar event (no database, no HTTP). */
final class GoogleEventMapper
{
    /** Video links worth surfacing when they are pasted into an event's place or notes (Teams, Zoom). */
    private const VIDEO_LINK = '~https://(?:[\w-]+\.)*(?:teams\.microsoft\.com|teams\.live\.com|zoom\.us)/[^\s"\'<>)]+~i';

    /** Google event types that are calendar markers rather than meetings. */
    private const NON_MEETING_TYPES = ['outOfOffice', 'focusTime', 'workingLocation'];

    /** Hosts a join link may point at (the host itself or any subdomain). */
    private const VIDEO_HOSTS = ['meet.google.com', 'teams.microsoft.com', 'teams.live.com', 'zoom.us'];

    /**
     * The event to create or update in Google.
     *
     * @return array<string, mixed>
     */
    public static function toGoogle(Meeting $meeting, bool $withMeet): array
    {
        $meeting->loadMissing('attendees');
        $hasPastedLink = filled($meeting->video_url);

        $event = [
            'summary' => $meeting->title,
            'description' => (string) $meeting->description,
            'location' => (string) ($meeting->location ?: ($hasPastedLink ? $meeting->video_url : '')),
            'start' => ['dateTime' => $meeting->starts_at->clone()->utc()->toIso8601String(), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $meeting->ends_at->clone()->utc()->toIso8601String(), 'timeZone' => 'UTC'],
            'attendees' => $meeting->attendees->map(fn (MeetingAttendee $a): array => array_filter([
                'email' => $a->email,
                'displayName' => $a->name,
            ]))->values()->all(),
            'guestsCanModify' => false,
        ];

        if ($withMeet && ! $hasPastedLink) {
            $event['conferenceData'] = [
                'createRequest' => [
                    'requestId' => (string) Str::uuid(),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ];
        }

        return $event;
    }

    /**
     * A Google event as BlastIQ attributes. Null for all-day entries (they have no time to put on the grid).
     *
     * @param  array<string, mixed>  $event
     * @return array{
     *   id: string, cancelled: bool, self_declined: bool, title: string, description: ?string, location: ?string,
     *   video_url: ?string, starts_at: ?Carbon, ends_at: ?Carbon, html_link: ?string, organizer: ?string,
     *   attendees: list<array{email: string, name: ?string, response: string}>
     * }|null
     */
    public static function fromGoogle(array $event): ?array
    {
        $id = (string) ($event['id'] ?? '');
        if ($id === '') {
            return null;
        }

        // Out-of-office, focus time and working-location markers are not meetings.
        if (in_array($event['eventType'] ?? 'default', self::NON_MEETING_TYPES, true)) {
            return null;
        }

        $cancelled = ($event['status'] ?? '') === 'cancelled';
        $startRaw = data_get($event, 'start.dateTime');
        $endRaw = data_get($event, 'end.dateTime');
        if (! $cancelled && ($startRaw === null || $endRaw === null)) {
            return null; // all-day event
        }

        $attendees = [];
        $selfDeclined = false;
        foreach ((array) ($event['attendees'] ?? []) as $guest) {
            $email = Str::lower(trim((string) ($guest['email'] ?? '')));
            $response = self::response((string) ($guest['responseStatus'] ?? ''));
            if (! empty($guest['self']) && $response === MeetingAttendee::RESPONSE_DECLINED) {
                $selfDeclined = true;
            }
            if ($email === '' || strlen($email) > 190 || ! empty($guest['organizer']) || ! empty($guest['self']) || ! empty($guest['resource'])) {
                continue; // the organiser and the connected person are implicit; rooms are not people
            }
            if (isset($attendees[$email])) {
                continue; // one row per address (the table is unique on meeting + email)
            }
            $attendees[$email] = ['email' => $email, 'name' => filled($guest['displayName'] ?? null) ? Str::limit((string) $guest['displayName'], 120, '') : null, 'response' => $response];
        }

        $description = self::plain((string) ($event['description'] ?? ''));
        $location = self::plain((string) ($event['location'] ?? ''));

        return [
            'id' => $id,
            'cancelled' => $cancelled,
            'self_declined' => $selfDeclined,
            'title' => Str::limit(trim((string) ($event['summary'] ?? '')) ?: '(No title)', 160, ''),
            'description' => $description !== '' ? Str::limit($description, 5000, '') : null,
            'location' => $location !== '' ? Str::limit($location, 255, '') : null,
            'video_url' => self::videoUrl($event, $description, (string) ($event['location'] ?? '')),
            'starts_at' => $startRaw !== null ? Carbon::parse($startRaw)->utc() : null,
            'ends_at' => $endRaw !== null ? Carbon::parse($endRaw)->utc() : null,
            'html_link' => filled($event['htmlLink'] ?? null) ? Str::limit((string) $event['htmlLink'], 500, '') : null,
            'organizer' => Str::limit((string) (data_get($event, 'organizer.displayName') ?: data_get($event, 'organizer.email') ?: ''), 190, '') ?: null,
            'attendees' => array_slice(array_values($attendees), 0, Meeting::MAX_ATTENDEES),
        ];
    }

    /**
     * The join link: Google's own Meet link first, then a Teams / Zoom link found in the notes or place.
     *
     * @param  array<string, mixed>  $event
     */
    public static function videoUrl(array $event, string $description = '', string $location = ''): ?string
    {
        $candidates = [data_get($event, 'hangoutLink')];
        foreach ((array) data_get($event, 'conferenceData.entryPoints', []) as $entry) {
            if (($entry['entryPointType'] ?? '') === 'video') {
                $candidates[] = $entry['uri'] ?? null;
            }
        }
        foreach ([$location, $description, (string) ($event['description'] ?? '')] as $text) {
            if (preg_match(self::VIDEO_LINK, $text, $m) === 1) {
                $candidates[] = html_entity_decode($m[0]);
            }
        }

        foreach ($candidates as $url) {
            if (is_string($url) && preg_match('~^https://~i', $url) === 1 && strlen($url) <= 500
                && filter_var($url, FILTER_VALIDATE_URL) && self::isKnownVideoHost($url)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Only well-known meeting hosts become a "Join" button. An invitation from a stranger can carry any link in its
     * conference details, and a button carrying BlastIQ's name must not point at an arbitrary site.
     */
    private static function isKnownVideoHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach (self::VIDEO_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    private static function response(string $status): string
    {
        return match ($status) {
            'accepted' => MeetingAttendee::RESPONSE_ACCEPTED,
            'declined' => MeetingAttendee::RESPONSE_DECLINED,
            default => MeetingAttendee::RESPONSE_PENDING, // needsAction and tentative
        };
    }

    /** Google notes may contain HTML; keep readable text only. */
    private static function plain(string $text): string
    {
        $text = preg_replace('~<br\s*/?>|</p>|</div>|</li>~i', "\n", $text) ?? $text;

        return trim(html_entity_decode(strip_tags($text)));
    }
}
