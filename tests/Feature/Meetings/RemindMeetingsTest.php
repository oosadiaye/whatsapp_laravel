<?php

declare(strict_types=1);

namespace Tests\Feature\Meetings;

use App\Mail\MeetingReminderMail;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class RemindMeetingsTest extends TestCase
{
    use RefreshDatabase;

    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boss = User::factory()->create(['email' => 'boss@example.com']);
        Mail::fake();
        config(['meetings.reminders_enabled' => true]);
    }

    /** A meeting that was set up well ahead (updated_at far in the past) and starts $inMinutes from now. */
    private function meetingStartingIn(int $inMinutes, array $overrides = []): Meeting
    {
        $meeting = Meeting::factory()->create(array_merge([
            'organizer_id' => $this->boss->id,
            'starts_at' => now()->addMinutes($inMinutes),
            'ends_at' => now()->addMinutes($inMinutes + 60),
        ], $overrides));
        Meeting::whereKey($meeting->id)->toBase()->update(['updated_at' => now()->subDays(5)]);

        return $meeting->fresh();
    }

    public function test_does_nothing_while_the_flag_is_off(): void
    {
        config(['meetings.reminders_enabled' => false]);
        $this->meetingStartingIn(5);

        $this->artisan('meetings:remind')->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_sends_the_ten_minute_reminder_once_to_organiser_and_non_decliners(): void
    {
        $meeting = $this->meetingStartingIn(8);
        MeetingAttendee::factory()->create(['meeting_id' => $meeting->id, 'email' => 'in@example.com']);
        MeetingAttendee::factory()->create(['meeting_id' => $meeting->id, 'email' => 'out@example.com', 'response' => 'declined']);

        $this->artisan('meetings:remind');
        $this->artisan('meetings:remind'); // a second tick must not repeat it

        Mail::assertQueued(MeetingReminderMail::class, 2);
        Mail::assertQueued(MeetingReminderMail::class, fn ($m) => $m->hasTo('boss@example.com') && $m->leadLabel === '10 minutes');
        Mail::assertQueued(MeetingReminderMail::class, fn ($m) => $m->hasTo('in@example.com'));
        Mail::assertNotQueued(MeetingReminderMail::class, fn ($m) => $m->hasTo('out@example.com'));
        $this->assertNotNull($meeting->fresh()->reminder_10m_sent_at);
    }

    public function test_sends_the_day_before_reminder_when_in_the_window(): void
    {
        $meeting = $this->meetingStartingIn(23 * 60 + 30);

        $this->artisan('meetings:remind');

        Mail::assertQueued(MeetingReminderMail::class, fn ($m) => $m->leadLabel === '24 hours');
        $this->assertNotNull($meeting->fresh()->reminder_24h_sent_at);
        $this->assertNull($meeting->fresh()->reminder_10m_sent_at);
    }

    public function test_the_day_before_reminder_is_not_sent_late_with_a_false_label(): void
    {
        // e.g. the flag was just switched on: the meeting is 5 hours away, not 24.
        $meeting = $this->meetingStartingIn(5 * 60);

        $this->artisan('meetings:remind');

        Mail::assertNothingQueued();
        $this->assertNull($meeting->fresh()->reminder_24h_sent_at);
    }

    public function test_a_meeting_booked_at_short_notice_does_not_claim_24_hours(): void
    {
        $meeting = Meeting::factory()->create([
            'organizer_id' => User::factory()->create(),
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(3),
        ]); // updated_at = now, i.e. only 2 hours of notice

        $this->artisan('meetings:remind');

        Mail::assertNothingQueued();
        $this->assertNull($meeting->fresh()->reminder_24h_sent_at);
    }

    public function test_cancelled_and_past_meetings_get_no_reminder(): void
    {
        $this->meetingStartingIn(5, ['status' => Meeting::STATUS_CANCELLED]);
        $this->meetingStartingIn(-30);

        $this->artisan('meetings:remind');

        Mail::assertNothingQueued();
    }
}
