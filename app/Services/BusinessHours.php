<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Business-hours calendar for inbound call routing. Reads the weekly schedule
 * from config/voice.php (HH:MM windows per day in a configured timezone). A day
 * with no window is closed all day. Pure + timezone-aware so it's easy to test.
 */
class BusinessHours
{
    public function isOpen(?CarbonInterface $at = null): bool
    {
        $tz = (string) config('voice.business_hours.timezone', 'UTC');
        $now = $at ? $at->copy()->setTimezone($tz) : Carbon::now($tz);

        $day = strtolower($now->format('D')); // Mon → "mon"
        $window = config("voice.business_hours.week.{$day}");

        if (! is_array($window) || count($window) !== 2) {
            return false; // closed all day
        }

        [$open, $close] = $window;
        $openAt = $now->copy()->setTimeFromTimeString((string) $open);
        $closeAt = $now->copy()->setTimeFromTimeString((string) $close);

        return $now->gte($openAt) && $now->lte($closeAt);
    }

    public function closedMessage(): string
    {
        return (string) config(
            'voice.business_hours.closed_message',
            'We are currently closed. Please call back during business hours.',
        );
    }
}
