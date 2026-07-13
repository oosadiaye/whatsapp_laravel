<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Services\BusinessHours;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BusinessHoursTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['voice.business_hours' => [
            'timezone' => 'Africa/Lagos',
            'week' => [
                'mon' => ['09:00', '17:00'],
                'tue' => ['09:00', '17:00'],
                'wed' => ['09:00', '17:00'],
                'thu' => ['09:00', '17:00'],
                'fri' => ['09:00', '17:00'],
                'sat' => null,
                'sun' => null,
            ],
            'closed_message' => 'Closed now.',
        ]]);
    }

    public function test_open_inside_the_weekday_window(): void
    {
        // Wednesday 10:00 Lagos time.
        $at = Carbon::parse('2026-07-15 10:00', 'Africa/Lagos');
        $this->assertTrue(app(BusinessHours::class)->isOpen($at));
    }

    public function test_closed_after_hours(): void
    {
        $at = Carbon::parse('2026-07-15 20:00', 'Africa/Lagos'); // Wed 8pm
        $this->assertFalse(app(BusinessHours::class)->isOpen($at));
    }

    public function test_closed_on_a_day_with_no_window(): void
    {
        $at = Carbon::parse('2026-07-18 11:00', 'Africa/Lagos'); // Saturday
        $this->assertFalse(app(BusinessHours::class)->isOpen($at));
    }

    public function test_respects_the_configured_timezone(): void
    {
        // 08:30 UTC is 09:30 in Lagos (UTC+1) → open.
        $at = Carbon::parse('2026-07-15 08:30', 'UTC');
        $this->assertTrue(app(BusinessHours::class)->isOpen($at));
    }
}
