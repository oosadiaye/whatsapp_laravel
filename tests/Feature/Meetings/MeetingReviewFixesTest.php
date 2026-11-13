<?php

declare(strict_types=1);

namespace Tests\Feature\Meetings;

use App\Jobs\SendMeetingMail;
use App\Livewire\Meetings\Calendar;
use App\Livewire\Meetings\MeetingForm;
use App\Mail\MeetingInviteMail;
use App\Models\Contact;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\User;
use App\Services\Meetings\IcsBuilder;
use App\Services\Meetings\MeetingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/** Regression tests for the problems found reviewing the first build of Meetings. */
final class MeetingReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Tomorrow 10:00–11:00 in the business timezone. Pinned so these tests do not depend on the time of day they run:
     * the form takes one date plus start/end times, so a meeting spanning local midnight cannot be edited through it.
     *
     * @return array{starts_at: \Illuminate\Support\Carbon, ends_at: \Illuminate\Support\Carbon}
     */
    private function tomorrowAtTen(): array
    {
        $start = now(config('app.business_timezone'))->addDay()->setTime(10, 0)->utc();

        return ['starts_at' => $start, 'ends_at' => $start->clone()->addHour()];
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    public function test_editing_never_drops_an_invitee_the_editor_cannot_resolve(): void
    {
        Queue::fake();
        $owner = $this->user('agent');
        // A manager who only sees their own contacts, editing a meeting that includes someone else's contact.
        $manager = $this->user('manager');
        $manager->forceFill(['restrict_to_own' => true])->save();
        $foreignContact = Contact::factory()->create(['user_id' => $owner->id, 'email' => 'client@example.com']);
        $inactive = User::factory()->create(['is_active' => false, 'email' => 'gone@example.com']);

        $meeting = Meeting::factory()->create(['organizer_id' => $owner->id, ...$this->tomorrowAtTen()]);
        MeetingAttendee::factory()->create(['meeting_id' => $meeting->id, 'contact_id' => $foreignContact->id, 'email' => 'client@example.com']);
        MeetingAttendee::factory()->create(['meeting_id' => $meeting->id, 'user_id' => $inactive->id, 'email' => 'gone@example.com']);

        Livewire::actingAs($manager)->test(MeetingForm::class)
            ->call('open', $meeting->id)
            ->set('title', 'Fixed a typo')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsCanonicalizing(['client@example.com', 'gone@example.com'], $meeting->attendees()->pluck('email')->all());
        Queue::assertNotPushed(SendMeetingMail::class, fn (SendMeetingMail $j) => $j->kind === 'cancel');
    }

    public function test_unticking_someone_still_removes_them(): void
    {
        Queue::fake();
        $owner = $this->user('agent');
        $colleague = $this->user('agent');
        $meeting = Meeting::factory()->create(['organizer_id' => $owner->id, ...$this->tomorrowAtTen()]);
        MeetingAttendee::factory()->create(['meeting_id' => $meeting->id, 'user_id' => $colleague->id, 'email' => $colleague->email]);

        Livewire::actingAs($owner)->test(MeetingForm::class)
            ->call('open', $meeting->id)
            ->set('staffIds', [])
            ->call('save');

        $this->assertSame(0, $meeting->attendees()->count());
        Queue::assertPushed(SendMeetingMail::class, fn (SendMeetingMail $j) => $j->kind === 'cancel' && $j->email === $colleague->email);
    }

    public function test_a_cancelled_meeting_cannot_be_edited_by_any_route(): void
    {
        Queue::fake();
        $owner = $this->user('agent');
        $meeting = Meeting::factory()->create(['organizer_id' => $owner->id, 'status' => Meeting::STATUS_CANCELLED, 'sequence' => 3]);

        Livewire::actingAs($owner)->test(MeetingForm::class)
            ->call('open', $meeting->id)->assertSet('show', false)->assertDispatched('notify');

        Livewire::actingAs($owner)->test(Calendar::class)
            ->call('editMeeting', $meeting->id)->assertDispatched('notify')->assertNotDispatched('meeting-form-open');

        $this->expectException(InvalidArgumentException::class);
        app(MeetingService::class)->update($meeting, ['title' => 'x', 'starts_at' => now(), 'ends_at' => now()->addHour()], []);
    }

    public function test_a_form_left_open_while_the_meeting_is_cancelled_saves_nothing(): void
    {
        Queue::fake();
        $owner = $this->user('agent');
        $meeting = Meeting::factory()->create(['organizer_id' => $owner->id, ...$this->tomorrowAtTen()]);

        $form = Livewire::actingAs($owner)->test(MeetingForm::class)->call('open', $meeting->id)->set('title', 'Changed');
        app(MeetingService::class)->cancel($meeting->fresh()); // cancelled in another tab
        Queue::fake();
        $form->call('save')->assertSet('show', false);

        $this->assertSame($meeting->title, $meeting->fresh()->title);
        Queue::assertNothingPushed();
    }

    public function test_each_calendar_file_lists_only_its_own_recipient(): void
    {
        $meeting = Meeting::factory()->create(['organizer_id' => User::factory()->create(['email' => 'boss@example.com'])]);
        MeetingAttendee::factory()->create(['meeting_id' => $meeting->id, 'email' => 'a@client.test']);
        MeetingAttendee::factory()->create(['meeting_id' => $meeting->id, 'email' => 'b@client.test']);

        $ics = str_replace("\r\n ", '', IcsBuilder::build($meeting->fresh(), IcsBuilder::METHOD_REQUEST, 'a@client.test')); // unfold
        $this->assertStringContainsString('mailto:a@client.test', $ics);
        $this->assertStringNotContainsString('b@client.test', $ics);
        $this->assertStringContainsString('mailto:boss@example.com', $ics);

        $this->assertStringContainsString('b@client.test', str_replace("
 ", '', IcsBuilder::build($meeting->fresh())));
    }

    public function test_the_attached_file_in_an_invitation_hides_other_guests(): void
    {
        Mail::fake();
        Queue::fake();
        $meeting = Meeting::factory()->create();
        MeetingAttendee::factory()->create(['meeting_id' => $meeting->id, 'email' => 'a@client.test']);
        MeetingAttendee::factory()->create(['meeting_id' => $meeting->id, 'email' => 'b@client.test']);

        (new SendMeetingMail($meeting->id, 'invite', 'a@client.test', null, $meeting->attendees()->where('email', 'a@client.test')->value('id')))->handle();

        // The mail is told who it is for, and the builder (tested above) then lists only that person.
        Mail::assertSent(MeetingInviteMail::class, fn (MeetingInviteMail $mail) => $mail->recipientEmail === 'a@client.test' && count($mail->attachments()) === 1);
    }

    public function test_a_stale_invite_never_revives_a_cancelled_meeting_or_a_removed_person(): void
    {
        Mail::fake();
        Queue::fake();
        $meeting = Meeting::factory()->create(['status' => Meeting::STATUS_CANCELLED]);
        $attendee = MeetingAttendee::factory()->create(['meeting_id' => $meeting->id, 'email' => 'a@client.test']);

        (new SendMeetingMail($meeting->id, 'invite', 'a@client.test', null, $attendee->id))->handle();
        (new SendMeetingMail($meeting->id, 'update', 'a@client.test', null, $attendee->id))->handle();
        Mail::assertNothingSent();

        (new SendMeetingMail($meeting->id, 'cancel', 'a@client.test', null, $attendee->id))->handle();
        Mail::assertSent(MeetingInviteMail::class, 1);

        $live = Meeting::factory()->create();
        (new SendMeetingMail($live->id, 'invite', 'gone@client.test', null, 999999))->handle(); // attendee row no longer exists
        Mail::assertSent(MeetingInviteMail::class, 1);
    }

    public function test_someone_who_declined_still_sees_the_meeting_and_can_change_their_mind(): void
    {
        Queue::fake();
        $owner = $this->user('agent');
        $guest = $this->user('agent');
        $meeting = Meeting::factory()->create(['organizer_id' => $owner->id, 'title' => 'Budget talk', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);
        MeetingAttendee::factory()->create(['meeting_id' => $meeting->id, 'user_id' => $guest->id, 'email' => $guest->email, 'response' => 'declined']);

        Livewire::actingAs($guest)->test(Calendar::class)
            ->assertSee('Budget talk')
            ->call('openMeeting', $meeting->id)
            ->call('respond', $meeting->id, 'accepted');

        $this->assertSame('accepted', $meeting->attendees()->first()->response);
    }

    public function test_saving_is_rate_limited_per_person(): void
    {
        Queue::fake();
        $me = $this->user('agent');
        RateLimiter::clear('meetings-save:'.$me->id);
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit('meetings-save:'.$me->id, 3600);
        }

        Livewire::actingAs($me)->test(MeetingForm::class)
            ->call('open', null, now(config('app.business_timezone'))->addDay()->format('Y-m-d'))
            ->set('title', 'One too many')
            ->call('save')
            ->assertHasErrors(['title']);

        $this->assertSame(0, Meeting::count());
    }
}
