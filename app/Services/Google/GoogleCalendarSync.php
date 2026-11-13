<?php

declare(strict_types=1);

namespace App\Services\Google;

use App\Models\GoogleConnection;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moves meetings between BlastIQ and a person's Google Calendar.
 *
 *  - push / update / cancel: a meeting made in BlastIQ is created, changed or removed in Google (Google then sends
 *    the invitations and holds the Meet room). Each returns null on success or a plain-language error.
 *  - import: Google events (made in Google, Meet or Teams invitations, ...) appear on the BlastIQ calendar as
 *    read-only meetings, and answers to meetings made here flow back.
 */
class GoogleCalendarSync
{
    /** How far back and forward the importer looks. */
    public const WINDOW_PAST_DAYS = 7;

    public const WINDOW_FUTURE_DAYS = 90;

    public function __construct(private readonly GoogleOAuth $oauth) {}

    public function push(Meeting $meeting): ?string
    {
        $connection = GoogleConnection::usableFor($meeting->organizer_id);
        if ($connection === null) {
            return 'The organiser has not connected Google Calendar.';
        }

        try {
            $withMeet = blank($meeting->video_url);
            $created = $this->client($connection)->insertEvent(GoogleEventMapper::toGoogle($meeting, $withMeet), $withMeet);

            $meeting->forceFill([
                'external_provider' => 'google',
                'external_id' => (string) $created['id'],
                'external_url' => filled($created['htmlLink'] ?? null) ? (string) $created['htmlLink'] : null,
                'video_url' => $meeting->video_url ?: GoogleEventMapper::videoUrl($created),
            ])->save();

            return null;
        } catch (GoogleApiException $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            report($e);

            return 'Google could not be reached. Try again shortly.';
        }
    }

    public function update(Meeting $meeting): ?string
    {
        return $this->guarded($meeting, function (GoogleCalendarClient $client) use ($meeting): void {
            $event = GoogleEventMapper::toGoogle($meeting, false);
            $client->patchEvent((string) $meeting->external_id, $event);
        });
    }

    public function cancel(Meeting $meeting): ?string
    {
        return $this->guarded($meeting, fn (GoogleCalendarClient $client) => $client->deleteEvent((string) $meeting->external_id));
    }

    /**
     * Read the person's Google calendar and mirror it.
     *
     * @return array{created: int, updated: int, cancelled: int}
     *
     * @throws GoogleApiException
     */
    public function import(GoogleConnection $connection): array
    {
        // One pass per connection at a time: "Sync now", the first import after connecting and the scheduled sync
        // must not race each other into duplicate rows or half-finished sweeps.
        $lock = Cache::lock('gcal-sync:'.$connection->id, 300);
        if (! $lock->get()) {
            throw new GoogleApiException('A sync of this calendar is already running. Try again in a minute.');
        }

        try {
            return $this->importLocked($connection);
        } finally {
            $lock->release();
        }
    }

    /** @return array{created: int, updated: int, cancelled: int} */
    private function importLocked(GoogleConnection $connection): array
    {
        $from = now()->subDays(self::WINDOW_PAST_DAYS);
        $to = now()->addDays(self::WINDOW_FUTURE_DAYS);

        $events = $this->client($connection)->listEvents($from, $to);

        $counts = ['created' => 0, 'updated' => 0, 'cancelled' => 0];
        $seen = [];

        foreach ($events as $raw) {
            $event = GoogleEventMapper::fromGoogle($raw);
            if ($event === null) {
                continue;
            }

            $meeting = Meeting::query()
                ->where('external_provider', 'google')
                ->where('external_id', $event['id'])
                ->where('organizer_id', $connection->user_id)
                ->first();

            $gone = $event['cancelled'] || $event['self_declined'];
            if ($gone) {
                if ($meeting !== null && ! $meeting->isCancelled()) {
                    $meeting->forceFill(['status' => Meeting::STATUS_CANCELLED, 'cancelled_at' => now()])->save();
                    $counts['cancelled']++;
                }

                continue;
            }

            $seen[] = $event['id'];
            if ($meeting === null) {
                $this->createImported($connection, $event);
                $counts['created']++;
            } elseif ($this->refresh($meeting, $event)) {
                $counts['updated']++;
            }
        }

        // An imported event that used to be in the window and is no longer returned was deleted in Google.
        Meeting::query()
            ->where('external_provider', 'google')->where('imported', true)->where('organizer_id', $connection->user_id)
            ->scheduled()
            ->where('starts_at', '>=', $from)->where('starts_at', '<=', $to)
            ->whereNotIn('external_id', $seen === [] ? [''] : $seen)
            ->get()
            ->each(function (Meeting $meeting) use (&$counts): void {
                $meeting->forceFill(['status' => Meeting::STATUS_CANCELLED, 'cancelled_at' => now()])->save();
                $counts['cancelled']++;
            });

        $connection->forceFill([
            'last_synced_at' => now(),
            'last_error' => null,
            'last_import_count' => count($seen),
            'status' => GoogleConnection::STATUS_ACTIVE,
        ])->save();

        return $counts;
    }

    /** @param  array<string, mixed>  $event */
    private function createImported(GoogleConnection $connection, array $event): void
    {
        try {
            $this->insertImported($connection, $event);
        } catch (UniqueConstraintViolationException) {
            // Created a moment ago by a parallel pass; the unique index did its job.
        }
    }

    /** @param  array<string, mixed>  $event */
    private function insertImported(GoogleConnection $connection, array $event): void
    {
        DB::transaction(function () use ($connection, $event): void {
            $meeting = Meeting::create([
                'organizer_id' => $connection->user_id,
                'title' => $event['title'],
                'description' => $event['description'],
                'location' => $event['location'],
                'video_url' => $event['video_url'],
                'starts_at' => $event['starts_at'],
                'ends_at' => $event['ends_at'],
                'status' => Meeting::STATUS_SCHEDULED,
                'uid' => (string) Str::uuid(),
                'sequence' => 0,
                'external_provider' => 'google',
                'external_id' => $event['id'],
                'external_url' => $event['html_link'],
                'external_organizer' => $event['organizer'],
                'imported' => true,
            ]);

            foreach ($event['attendees'] as $guest) {
                $meeting->attendees()->create([
                    'email' => $guest['email'], 'name' => $guest['name'], 'response' => $guest['response'],
                    'response_token' => Str::random(40), 'user_id' => null,
                ]);
            }
        });
    }

    /**
     * Bring an existing meeting in line with Google. Returns whether anything changed.
     *
     * @param  array<string, mixed>  $event
     */
    private function refresh(Meeting $meeting, array $event): bool
    {
        $changed = false;

        if (! $meeting->imported) {
            // A meeting made in BlastIQ keeps BlastIQ's own title, notes, place, times and status: Google may be behind
            // (a failed push) or may have normalised the text, and reading it back would silently undo local changes or
            // revive a meeting that was cancelled here. Only the join link (once) and the Google address are taken back.
            if (blank($meeting->video_url) && $event['video_url'] !== null) {
                $meeting->video_url = $event['video_url'];
            }
            $meeting->external_url = $event['html_link'] ?? $meeting->external_url;
        } else {
            $meeting->fill([
                'title' => $event['title'], 'description' => $event['description'], 'location' => $event['location'],
                'video_url' => $event['video_url'] ?? $meeting->video_url,
                'starts_at' => $event['starts_at'], 'ends_at' => $event['ends_at'],
                'external_url' => $event['html_link'], 'external_organizer' => $event['organizer'],
            ]);
            if ($meeting->isCancelled()) {
                $meeting->status = Meeting::STATUS_SCHEDULED; // un-deleted or re-accepted in Google
                $meeting->cancelled_at = null;
            }
        }
        if ($meeting->isDirty()) {
            $meeting->save();
            $changed = true;
        }

        $existing = $meeting->attendees()->get()->keyBy('email');
        foreach ($event['attendees'] as $guest) {
            $row = $existing->get($guest['email']);
            if ($row === null) {
                if ($meeting->imported && $existing->count() < Meeting::MAX_ATTENDEES) {
                    $meeting->attendees()->create([
                        'email' => $guest['email'], 'name' => $guest['name'], 'response' => $guest['response'],
                        'response_token' => Str::random(40), 'user_id' => null,
                    ]);
                    $changed = true;
                }
            } elseif ($row->response !== $guest['response']) {
                $row->forceFill(['response' => $guest['response'], 'responded_at' => $guest['response'] === MeetingAttendee::RESPONSE_PENDING ? null : now()])->save();
                $changed = true;
            }
        }

        if ($meeting->imported) {
            $wanted = collect($event['attendees'])->pluck('email');
            $stale = $existing->keys()->diff($wanted);
            if ($stale->isNotEmpty()) {
                $meeting->attendees()->whereIn('email', $stale->all())->delete();
                $changed = true;
            }
        }

        return $changed;
    }

    /** Run a Google call for a pushed meeting, turning any failure into a message. */
    private function guarded(Meeting $meeting, callable $call): ?string
    {
        $connection = GoogleConnection::usableFor($meeting->organizer_id);
        if ($connection === null) {
            return 'The organiser\'s Google Calendar is not connected any more.';
        }

        try {
            $call($this->client($connection));

            return null;
        } catch (GoogleApiException $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            report($e);

            return 'Google could not be reached. Try again shortly.';
        }
    }

    private function client(GoogleConnection $connection): GoogleCalendarClient
    {
        return new GoogleCalendarClient($connection, $this->oauth);
    }
}
