<?php

declare(strict_types=1);

namespace Tests\Feature\Meetings;

use App\Models\Meeting;
use App\Models\MeetingAttendee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class MeetingResponseTest extends TestCase
{
    use RefreshDatabase;

    private function attendee(array $meetingOverrides = []): MeetingAttendee
    {
        $meeting = Meeting::factory()->create($meetingOverrides);

        return MeetingAttendee::factory()->create(['meeting_id' => $meeting->id, 'response_token' => str_repeat('a', 40)]);
    }

    public function test_opening_the_link_shows_the_meeting_but_changes_nothing(): void
    {
        $attendee = $this->attendee();

        $this->get(route('meetings.respond.show', ['token' => $attendee->response_token, 'answer' => 'accepted']))
            ->assertOk()
            ->assertSee($attendee->meeting->title)
            ->assertSee('Yes, I will come');

        $this->assertSame('pending', $attendee->fresh()->response);
    }

    public function test_posting_records_the_answer_without_login(): void
    {
        Mail::fake();
        $attendee = $this->attendee();

        $this->post(route('meetings.respond.store', ['token' => $attendee->response_token]), ['answer' => 'accepted'])
            ->assertOk()
            ->assertSee('marked as coming');

        $this->assertSame('accepted', $attendee->fresh()->response);
        $this->assertNotNull($attendee->fresh()->responded_at);
    }

    public function test_unknown_or_malformed_tokens_are_404(): void
    {
        $this->get(route('meetings.respond.show', ['token' => str_repeat('z', 40)]))->assertNotFound();
        $this->get(route('meetings.respond.show', ['token' => 'short']))->assertNotFound();
        $this->post(route('meetings.respond.store', ['token' => str_repeat('z', 40)]), ['answer' => 'accepted'])->assertNotFound();
    }

    public function test_an_invalid_answer_is_rejected(): void
    {
        $attendee = $this->attendee();

        $this->post(route('meetings.respond.store', ['token' => $attendee->response_token]), ['answer' => 'maybe'])
            ->assertSessionHasErrors('answer');
        $this->assertSame('pending', $attendee->fresh()->response);
    }

    public function test_a_cancelled_meeting_says_so_and_cannot_be_answered(): void
    {
        $attendee = $this->attendee(['status' => Meeting::STATUS_CANCELLED]);

        $this->get(route('meetings.respond.show', ['token' => $attendee->response_token]))
            ->assertOk()->assertSee('cancelled');

        $this->post(route('meetings.respond.store', ['token' => $attendee->response_token]), ['answer' => 'accepted'])->assertOk();
        $this->assertSame('pending', $attendee->fresh()->response);
    }

    public function test_the_page_never_leaks_other_attendees_or_tokens(): void
    {
        $attendee = $this->attendee();
        $other = MeetingAttendee::factory()->create(['meeting_id' => $attendee->meeting_id, 'email' => 'secret@example.com', 'response_token' => str_repeat('b', 40)]);

        $this->get(route('meetings.respond.show', ['token' => $attendee->response_token]))
            ->assertDontSee('secret@example.com')
            ->assertDontSee($other->response_token, false);
    }
}
