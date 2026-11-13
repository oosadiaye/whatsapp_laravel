<?php

declare(strict_types=1);

namespace Tests\Feature\Meetings;

use App\Jobs\SendMeetingMail;
use App\Mail\MeetingInviteMail;
use App\Mail\MeetingResponseMail;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\User;
use App\Services\Meetings\MeetingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

final class MeetingServiceTest extends TestCase
{
    use RefreshDatabase;

    private MeetingService $service;

    private User $organizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MeetingService::class);
        $this->organizer = User::factory()->create(['email' => 'boss@example.com']);
    }

    private function data(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Kick-off',
            'starts_at' => Carbon::parse('2030-01-10 09:00', 'UTC'),
            'ends_at' => Carbon::parse('2030-01-10 10:00', 'UTC'),
        ], $overrides);
    }

    public function test_create_stores_attendees_and_queues_one_invite_each(): void
    {
        Queue::fake();

        $meeting = $this->service->create($this->organizer, $this->data(), [
            ['email' => 'Bob@Example.com', 'name' => 'Bob'],
            ['email' => 'bob@example.com'],            // duplicate (case-insensitive)
            ['email' => 'boss@example.com'],           // the organiser is implicit
            ['email' => 'not-an-email'],               // ignored
        ]);

        $this->assertSame(1, $meeting->attendees()->count());
        $attendee = $meeting->attendees()->first();
        $this->assertSame('bob@example.com', $attendee->email);
        $this->assertSame(40, strlen($attendee->makeVisible('response_token')->response_token));
        Queue::assertPushed(SendMeetingMail::class, 1);
        Queue::assertPushed(SendMeetingMail::class, fn (SendMeetingMail $j) => $j->kind === 'invite' && $j->email === 'bob@example.com');
    }

    public function test_more_than_fifty_attendees_is_refused(): void
    {
        $rows = [];
        for ($i = 0; $i < 51; $i++) {
            $rows[] = ['email' => "p{$i}@example.com"];
        }

        $this->expectException(InvalidArgumentException::class);
        $this->service->create($this->organizer, $this->data(), $rows);
    }

    public function test_rescheduling_resets_answers_bumps_sequence_and_tells_everyone(): void
    {
        Queue::fake();
        $meeting = $this->service->create($this->organizer, $this->data(), [['email' => 'a@example.com'], ['email' => 'b@example.com']]);
        $meeting->attendees()->update(['response' => 'accepted']);
        $meeting->forceFill(['reminder_24h_sent_at' => now()])->save();
        Queue::fake(); // forget the invites; look only at the update

        $this->service->update($meeting->fresh(), $this->data([
            'starts_at' => Carbon::parse('2030-01-11 09:00', 'UTC'),
            'ends_at' => Carbon::parse('2030-01-11 10:00', 'UTC'),
        ]), [['email' => 'a@example.com'], ['email' => 'c@example.com']]);

        $meeting->refresh();
        $this->assertSame(1, $meeting->sequence);
        $this->assertNull($meeting->reminder_24h_sent_at);
        $this->assertSame(['a@example.com', 'c@example.com'], $meeting->attendees()->orderBy('email')->pluck('email')->all());
        $this->assertSame('pending', $meeting->attendees()->where('email', 'a@example.com')->value('response'));

        Queue::assertPushed(SendMeetingMail::class, fn (SendMeetingMail $j) => $j->kind === 'update' && $j->email === 'a@example.com');
        Queue::assertPushed(SendMeetingMail::class, fn (SendMeetingMail $j) => $j->kind === 'invite' && $j->email === 'c@example.com');
        Queue::assertPushed(SendMeetingMail::class, fn (SendMeetingMail $j) => $j->kind === 'cancel' && $j->email === 'b@example.com');
    }

    public function test_editing_only_the_title_keeps_answers(): void
    {
        Queue::fake();
        $meeting = $this->service->create($this->organizer, $this->data(), [['email' => 'a@example.com']]);
        $meeting->attendees()->update(['response' => 'accepted']);

        $this->service->update($meeting->fresh(), $this->data(['title' => 'Kick-off v2']), [['email' => 'a@example.com']]);

        $this->assertSame('accepted', $meeting->attendees()->value('response'));
    }

    public function test_cancel_marks_cancelled_and_notifies_everyone_once(): void
    {
        Queue::fake();
        $meeting = $this->service->create($this->organizer, $this->data(), [['email' => 'a@example.com']]);
        Queue::fake();

        $this->service->cancel($meeting->fresh());
        $this->service->cancel($meeting->fresh()); // second call is a no-op

        $this->assertTrue($meeting->fresh()->isCancelled());
        Queue::assertPushed(SendMeetingMail::class, 1);
        Queue::assertPushed(SendMeetingMail::class, fn (SendMeetingMail $j) => $j->kind === 'cancel');
    }

    public function test_decline_tells_the_organiser_but_accept_does_not(): void
    {
        Mail::fake();
        Queue::fake();
        $meeting = $this->service->create($this->organizer, $this->data(), [['email' => 'a@example.com', 'name' => 'Ann']]);
        $attendee = $meeting->attendees()->first();

        $this->assertTrue($this->service->respond($attendee, 'accepted'));
        Mail::assertNothingQueued();

        $this->assertTrue($this->service->respond($attendee->fresh(), 'declined'));
        Mail::assertQueued(MeetingResponseMail::class, fn ($m) => $m->hasTo('boss@example.com') && $m->personName === 'Ann');
    }

    public function test_cannot_answer_a_cancelled_or_finished_meeting(): void
    {
        Queue::fake();
        $meeting = $this->service->create($this->organizer, $this->data(), [['email' => 'a@example.com']]);
        $attendee = $meeting->attendees()->first();

        $meeting->forceFill(['status' => Meeting::STATUS_CANCELLED])->save();
        $this->assertFalse($this->service->respond($attendee->fresh(), 'accepted'));

        $meeting->forceFill(['status' => Meeting::STATUS_SCHEDULED, 'starts_at' => now()->subHours(3), 'ends_at' => now()->subHours(2)])->save();
        $this->assertFalse($this->service->respond($attendee->fresh(), 'accepted'));
        $this->assertSame('pending', $attendee->fresh()->response);
    }

    public function test_conflicts_find_overlaps_but_not_touching_or_declined_or_cancelled(): void
    {
        Queue::fake();
        $busy = User::factory()->create(['email' => 'busy@example.com']);
        $existing = $this->service->create($this->organizer, $this->data(), [['email' => 'busy@example.com', 'user_id' => $busy->id]]);

        $overlap = $this->service->conflicts([$busy->id], Carbon::parse('2030-01-10 09:30', 'UTC'), Carbon::parse('2030-01-10 10:30', 'UTC'));
        $this->assertSame($existing->id, $overlap[$busy->id]->id);

        $touching = $this->service->conflicts([$busy->id], Carbon::parse('2030-01-10 10:00', 'UTC'), Carbon::parse('2030-01-10 11:00', 'UTC'));
        $this->assertSame([], $touching);

        $ignoring = $this->service->conflicts([$busy->id], Carbon::parse('2030-01-10 09:30', 'UTC'), Carbon::parse('2030-01-10 10:30', 'UTC'), $existing->id);
        $this->assertSame([], $ignoring);

        $existing->attendees()->update(['response' => 'declined']);
        $this->assertSame([], $this->service->conflicts([$busy->id], Carbon::parse('2030-01-10 09:30', 'UTC'), Carbon::parse('2030-01-10 10:30', 'UTC')));

        $existing->attendees()->update(['response' => 'pending']);
        $existing->forceFill(['status' => Meeting::STATUS_CANCELLED])->save();
        $this->assertSame([], $this->service->conflicts([$busy->id], Carbon::parse('2030-01-10 09:30', 'UTC'), Carbon::parse('2030-01-10 10:30', 'UTC')));
    }

    public function test_the_mail_job_sends_with_calendar_file_and_records_delivery(): void
    {
        Mail::fake();
        Queue::fake();
        $meeting = $this->service->create($this->organizer, $this->data(['video_url' => 'https://meet.google.com/abc-defg-hij']), [['email' => 'a@example.com']]);
        $attendee = $meeting->attendees()->first();

        (new SendMeetingMail($meeting->id, 'invite', 'a@example.com', 'Ann', $attendee->id))->handle();

        Mail::assertSent(MeetingInviteMail::class, function (MeetingInviteMail $mail) {
            $this->assertCount(1, $mail->attachments());

            return $mail->hasTo('a@example.com') && str_contains($mail->render(), 'Join with Google Meet');
        });
        $this->assertNotNull($attendee->fresh()->invited_at);
    }

    public function test_a_failed_invite_is_recorded_for_the_organiser(): void
    {
        Queue::fake();
        $meeting = $this->service->create($this->organizer, $this->data(), [['email' => 'a@example.com']]);
        $attendee = $meeting->attendees()->first();

        (new SendMeetingMail($meeting->id, 'invite', 'a@example.com', null, $attendee->id))->failed(new \RuntimeException('Connection could not be established'));

        $this->assertNotEmpty($attendee->fresh()->invite_error);
    }

    public function test_video_provider_is_recognised_from_the_link(): void
    {
        $this->assertSame('Google Meet', Meeting::factory()->make(['video_url' => 'https://meet.google.com/x'])->videoProvider());
        $this->assertSame('Microsoft Teams', Meeting::factory()->make(['video_url' => 'https://teams.microsoft.com/l/meetup-join/x'])->videoProvider());
        $this->assertSame('Video link', Meeting::factory()->make(['video_url' => 'https://example.com/room'])->videoProvider());
        $this->assertNull(Meeting::factory()->make(['video_url' => null])->videoProvider());
    }
}
