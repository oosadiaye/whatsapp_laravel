<?php

declare(strict_types=1);

namespace App\Services\Meetings;

use App\Models\Meeting;

/**
 * Builds the iCalendar (.ics) text calendar apps understand (Google, Outlook, Apple, Yahoo).
 *
 * METHOD:REQUEST adds or updates the event (same UID, higher SEQUENCE), METHOD:CANCEL removes it.
 * Pure: no database, no clock — everything comes from the Meeting, so it is easy to test.
 */
final class IcsBuilder
{
    public const METHOD_REQUEST = 'REQUEST';

    public const METHOD_CANCEL = 'CANCEL';

    /** Max octets per physical line before folding (RFC 5545 §3.1). */
    private const FOLD_AT = 75;

    /**
     * @param  string|null  $onlyAttendeeEmail  list just this invitee (plus the organiser). Used for every file sent to or
     *                                          downloaded by one person, so clients invited to the same meeting never receive
     *                                          each other's addresses. Null lists everyone (managers only).
     */
    public static function build(Meeting $meeting, string $method = self::METHOD_REQUEST, ?string $onlyAttendeeEmail = null): string
    {
        $meeting->loadMissing(['organizer', 'attendees']);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//BlastIQ//Meetings//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:'.$method,
            'BEGIN:VEVENT',
            'UID:'.$meeting->uid,
            'SEQUENCE:'.$meeting->sequence,
            'DTSTAMP:'.self::utc($meeting->updated_at ?? $meeting->created_at),
            'DTSTART:'.self::utc($meeting->starts_at),
            'DTEND:'.self::utc($meeting->ends_at),
            'SUMMARY:'.self::escape($meeting->title),
            'STATUS:'.($method === self::METHOD_CANCEL ? 'CANCELLED' : 'CONFIRMED'),
        ];

        $description = trim(($meeting->description ?? '').($meeting->video_url ? "\n\nJoin: ".$meeting->video_url : ''));
        if ($description !== '') {
            $lines[] = 'DESCRIPTION:'.self::escape($description);
        }

        $location = $meeting->location ?: $meeting->video_url;
        if (filled($location)) {
            $lines[] = 'LOCATION:'.self::escape((string) $location);
        }

        if (filled($meeting->video_url)) {
            $lines[] = 'URL:'.$meeting->video_url;
        }

        if ($meeting->organizer !== null) {
            $lines[] = 'ORGANIZER;CN='.self::param($meeting->organizer->name).':mailto:'.$meeting->organizer->email;
        }

        foreach ($meeting->attendees as $attendee) {
            if ($onlyAttendeeEmail !== null && strtolower($attendee->email) !== strtolower($onlyAttendeeEmail)) {
                continue;
            }
            $lines[] = 'ATTENDEE;CN='.self::param($attendee->displayName()).';ROLE=REQ-PARTICIPANT;RSVP=TRUE:mailto:'.$attendee->email;
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    private static function utc(\DateTimeInterface $moment): string
    {
        return \Illuminate\Support\Carbon::instance($moment)->utc()->format('Ymd\THis\Z');
    }

    /** Escape a TEXT value: backslash, semicolon, comma, and newlines. */
    private static function escape(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
    }

    /** A parameter value (CN=...): quoted, with quotes and control characters removed. */
    private static function param(string $value): string
    {
        $clean = trim(preg_replace('/[\x00-\x1F\x7F"]/u', ' ', $value) ?? '');

        return '"'.$clean.'"';
    }

    /** Fold a line at 75 octets without splitting a multibyte character. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= self::FOLD_AT) {
            return $line;
        }

        $parts = [];
        $current = '';
        foreach (mb_str_split($line) as $char) {
            $limit = $parts === [] ? self::FOLD_AT : self::FOLD_AT - 1; // continuation lines start with a space
            if (strlen($current) + strlen($char) > $limit) {
                $parts[] = $current;
                $current = '';
            }
            $current .= $char;
        }
        $parts[] = $current;

        return implode("\r\n ", $parts);
    }
}
