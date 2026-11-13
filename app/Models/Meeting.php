<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One scheduled meeting. Times are stored in UTC and shown in app.business_timezone.
 * BlastIQ does not carry the video itself: `video_url` is a Google Meet / Teams / any https link.
 */
class Meeting extends Model
{
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CANCELLED = 'cancelled';

    public const MAX_ATTENDEES = 50;

    protected $fillable = [
        'organizer_id', 'title', 'description', 'location', 'video_url',
        'starts_at', 'ends_at', 'status', 'uid', 'sequence', 'cancelled_at',
        'reminder_24h_sent_at', 'reminder_10m_sent_at',
        'external_provider', 'external_id', 'external_url', 'external_organizer', 'imported',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminder_24h_sent_at' => 'datetime',
            'reminder_10m_sent_at' => 'datetime',
            'sequence' => 'integer',
            'imported' => 'boolean',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(MeetingAttendee::class);
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SCHEDULED);
    }

    /** Meetings whose time span overlaps [$start, $end) — touching ends do not overlap. */
    public function scopeOverlapping(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->where('starts_at', '<', $end)->where('ends_at', '>', $start);
    }

    /** Organised by, or invited as staff to (and not declined by), this user. */
    public function scopeInvolving(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId): void {
            $q->where('organizer_id', $userId)
                ->orWhereHas('attendees', fn (Builder $a) => $a->where('user_id', $userId)
                    ->where('response', '!=', MeetingAttendee::RESPONSE_DECLINED));
        });
    }

    /** The organiser, or anyone with meetings.manage_all, may edit, cancel and reschedule. */
    public function canBeManagedBy(User $user): bool
    {
        // A meeting brought in from Google is a read-only mirror: it is changed in Google, not here.
        if ($this->imported) {
            return false;
        }

        // A meeting held in someone's Google Calendar is changed with THEIR Google permission, which they granted for
        // their own use: only they may change or cancel it from here (a manager would be acting as them in Google).
        if ($this->isGoogleManaged()) {
            return $this->organizer_id === $user->id;
        }

        return $this->organizer_id === $user->id || $user->can('meetings.manage_all');
    }

    /** Created here and pushed to Google Calendar: Google holds the event and sends the invitations. */
    public function isGoogleManaged(): bool
    {
        return $this->external_provider === 'google' && ! $this->imported && filled($this->external_id);
    }

    /** Managers of the meeting, plus staff invited to it. */
    public function canBeViewedBy(User $user): bool
    {
        // The owner always sees their meeting, even a read-only Google copy that nobody may change.
        return $this->organizer_id === $user->id
            || $this->canBeManagedBy($user)
            || $this->attendees()->where('user_id', $user->id)->exists();
    }

    /**
     * Organised by, or invited as staff to, this user — INCLUDING meetings they declined, so a colleague who said
     * "no" still sees the meeting on their calendar and can change their mind. (scopeInvolving, which drops declined
     * meetings, is for "is this person actually busy then".)
     */
    public function scopeParticipating(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId): void {
            $q->where('organizer_id', $userId)
                ->orWhereHas('attendees', fn (Builder $a) => $a->where('user_id', $userId));
        });
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function organizerName(): string
    {
        return $this->organizer?->name ?? 'A former colleague';
    }

    /** What kind of link this is, from its host — shown as a label next to the Join button. */
    public function videoProvider(): ?string
    {
        if (blank($this->video_url)) {
            return null;
        }

        $host = strtolower((string) parse_url($this->video_url, PHP_URL_HOST));

        return match (true) {
            $host === 'meet.google.com' => 'Google Meet',
            $host === 'teams.microsoft.com', $host === 'teams.live.com', str_ends_with($host, '.teams.microsoft.com') => 'Microsoft Teams',
            $host === 'zoom.us', str_ends_with($host, '.zoom.us') => 'Zoom',
            default => 'Video link',
        };
    }

    /** Deep link that pre-fills a new Google Calendar event with this meeting. */
    public function googleCalendarUrl(): string
    {
        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $this->title,
            'dates' => $this->starts_at->clone()->utc()->format('Ymd\THis\Z').'/'.$this->ends_at->clone()->utc()->format('Ymd\THis\Z'),
            'details' => $this->calendarDetails(),
            'location' => $this->location ?: ($this->video_url ?? ''),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Deep link that pre-fills a new Outlook / Microsoft 365 calendar event with this meeting. */
    public function outlookCalendarUrl(): string
    {
        return 'https://outlook.office.com/calendar/0/deeplink/compose?'.http_build_query([
            'path' => '/calendar/action/compose',
            'rru' => 'addevent',
            'subject' => $this->title,
            'startdt' => $this->starts_at->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
            'enddt' => $this->ends_at->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
            'body' => $this->calendarDetails(),
            'location' => $this->location ?: ($this->video_url ?? ''),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function calendarDetails(): string
    {
        return trim(($this->description ?? '').($this->video_url ? "\n\nJoin: ".$this->video_url : ''));
    }
}
