<?php

declare(strict_types=1);

namespace Tests\Feature\Meetings;

use App\Jobs\SendMeetingMail;
use App\Livewire\Meetings\Calendar;
use App\Livewire\Meetings\MeetingForm;
use App\Models\Contact;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

final class MeetingCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    public function test_every_role_can_open_the_page_and_the_nav_links_to_it(): void
    {
        foreach (['agent', 'user', 'manager', 'admin'] as $role) {
            $this->actingAs($this->user($role))->get(route('meetings.index'))
                ->assertOk()->assertSee('Meetings');
        }
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('meetings.index'))->assertRedirect(route('login'));
    }

    public function test_only_managers_hold_manage_all(): void
    {
        $this->assertTrue($this->user('manager')->can('meetings.manage_all'));
        $this->assertTrue($this->user('admin')->can('meetings.manage_all'));
        $this->assertFalse($this->user('user')->can('meetings.manage_all'));
        $this->assertFalse($this->user('agent')->can('meetings.manage_all'));
        $this->assertTrue($this->user('agent')->can('meetings.create'));
    }

    public function test_the_grid_shows_my_meetings_not_other_peoples(): void
    {
        $me = $this->user('agent');
        $other = $this->user('agent');
        Meeting::factory()->create(['organizer_id' => $me->id, 'title' => 'My standup', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);
        Meeting::factory()->create(['organizer_id' => $other->id, 'title' => 'Secret board meeting', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);

        Livewire::actingAs($me)->test(Calendar::class)
            ->assertSee('My standup')
            ->assertDontSee('Secret board meeting');
    }

    public function test_a_manager_can_switch_to_everyone_but_an_agent_cannot(): void
    {
        $agent = $this->user('agent');
        $manager = $this->user('manager');
        Meeting::factory()->create(['organizer_id' => $agent->id, 'title' => 'Agent sync', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);

        Livewire::actingAs($manager)->test(Calendar::class)
            ->assertDontSee('Agent sync')
            ->call('setScope', 'everyone')
            ->assertSee('Agent sync');

        Livewire::actingAs($agent)->test(Calendar::class)
            ->call('setScope', 'everyone')
            ->assertSet('scope', 'mine');
    }

    public function test_a_stranger_cannot_open_edit_or_cancel_someone_elses_meeting(): void
    {
        $owner = $this->user('agent');
        $stranger = $this->user('agent');
        $meeting = Meeting::factory()->create(['organizer_id' => $owner->id]);

        Livewire::actingAs($stranger)->test(Calendar::class)
            ->call('openMeeting', $meeting->id)->assertForbidden();
        Livewire::actingAs($stranger)->test(Calendar::class)
            ->call('cancelMeeting', $meeting->id)->assertForbidden();
        $this->actingAs($stranger)->get(route('meetings.ics', $meeting))->assertForbidden();
        $this->assertFalse($meeting->fresh()->isCancelled());
    }

    public function test_an_invited_colleague_can_see_and_answer_but_not_cancel(): void
    {
        Queue::fake();
        $owner = $this->user('agent');
        $guest = $this->user('agent');
        $meeting = Meeting::factory()->create(['organizer_id' => $owner->id]);
        MeetingAttendee::factory()->create(['meeting_id' => $meeting->id, 'user_id' => $guest->id, 'email' => $guest->email]);

        Livewire::actingAs($guest)->test(Calendar::class)
            ->call('openMeeting', $meeting->id)->assertSee($meeting->title)
            ->call('respond', $meeting->id, 'accepted')
            ->call('cancelMeeting', $meeting->id)->assertForbidden();

        $this->assertSame('accepted', $meeting->attendees()->first()->response);
        $this->actingAs($guest)->get(route('meetings.ics', $meeting))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=UTF-8');
    }

    public function test_the_organiser_can_cancel_and_attendees_are_told(): void
    {
        Queue::fake();
        $owner = $this->user('agent');
        $meeting = Meeting::factory()->create(['organizer_id' => $owner->id]);
        MeetingAttendee::factory()->create(['meeting_id' => $meeting->id]);

        Livewire::actingAs($owner)->test(Calendar::class)
            ->call('cancelMeeting', $meeting->id);

        $this->assertTrue($meeting->fresh()->isCancelled());
        Queue::assertPushed(SendMeetingMail::class, fn ($j) => $j->kind === 'cancel');
    }

    public function test_the_team_panel_shows_in_a_meeting_without_leaking_the_title(): void
    {
        $me = $this->user('agent');
        $busy = $this->user('agent');
        $meeting = Meeting::factory()->create(['organizer_id' => $busy->id, 'title' => 'Layoff planning', 'starts_at' => now()->subMinutes(10), 'ends_at' => now()->addMinutes(20)]);

        Livewire::actingAs($me)->test(Calendar::class)
            ->assertSee('In a meeting until')
            ->assertDontSee('Layoff planning');
    }

    public function test_creating_a_meeting_through_the_form_invites_staff_contacts_and_emails(): void
    {
        Queue::fake();
        $me = $this->user('agent');
        $colleague = $this->user('agent');
        $contact = Contact::factory()->create(['user_id' => $me->id, 'email' => 'client@example.com', 'name' => 'Client']);
        $date = Carbon::now(config('app.business_timezone'))->addDays(2)->format('Y-m-d');

        Livewire::actingAs($me)->test(MeetingForm::class)
            ->call('open', null, $date)
            ->set('title', 'Quarterly review')
            ->set('startTime', '10:00')->set('endTime', '11:00')
            ->set('videoUrl', 'https://meet.google.com/abc-defg-hij')
            ->set('staffIds', [$colleague->id])
            ->call('pickContact', $contact->id)
            ->set('externalEmails', 'partner@example.com, other@example.com')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('meeting-saved');

        $meeting = Meeting::firstWhere('title', 'Quarterly review');
        $this->assertSame($me->id, $meeting->organizer_id);
        $this->assertEqualsCanonicalizing(
            [$colleague->email, 'client@example.com', 'partner@example.com', 'other@example.com'],
            $meeting->attendees()->pluck('email')->all(),
        );
        Queue::assertPushed(SendMeetingMail::class, 4);
        // 10:00 in the business timezone is stored as UTC.
        $this->assertSame(
            Carbon::createFromFormat('Y-m-d H:i', $date.' 10:00', config('app.business_timezone'))->utc()->toDateTimeString(),
            $meeting->starts_at->toDateTimeString(),
        );
    }

    public function test_form_validation_catches_bad_input(): void
    {
        Queue::fake();
        $me = $this->user('agent');
        $date = Carbon::now(config('app.business_timezone'))->addDay()->format('Y-m-d');

        Livewire::actingAs($me)->test(MeetingForm::class)
            ->call('open', null, $date)
            ->set('title', '')
            ->call('save')->assertHasErrors(['title'])
            ->set('title', 'x')->set('startTime', '11:00')->set('endTime', '10:00')
            ->call('save')->assertHasErrors(['endTime'])
            ->set('endTime', '12:00')->set('videoUrl', 'http://insecure.example.com')
            ->call('save')->assertHasErrors(['videoUrl'])
            ->set('videoUrl', 'javascript:alert(1)')
            ->call('save')->assertHasErrors(['videoUrl'])
            ->set('videoUrl', '')->set('externalEmails', 'good@example.com, nonsense')
            ->call('save')->assertHasErrors(['externalEmails']);

        $this->assertSame(0, Meeting::count());
    }

    public function test_a_new_meeting_in_the_past_is_refused(): void
    {
        $me = $this->user('agent');

        Livewire::actingAs($me)->test(MeetingForm::class)
            ->call('open', null, '2020-01-01')
            ->set('title', 'Old')
            ->call('save')->assertHasErrors(['date']);
    }

    public function test_a_contact_you_cannot_see_cannot_be_invited(): void
    {
        Queue::fake();
        $me = $this->user('user');
        $me->forceFill(['restrict_to_own' => true])->save(); // on by default for this role when created in the app
        $theirs = Contact::factory()->create(['user_id' => $this->user('user')->id, 'email' => 'private@example.com']);
        $date = Carbon::now(config('app.business_timezone'))->addDay()->format('Y-m-d');

        Livewire::actingAs($me)->test(MeetingForm::class)
            ->call('open', null, $date)
            ->set('title', 'Pitch')
            ->call('pickContact', $theirs->id)
            ->call('save');

        $this->assertSame(0, MeetingAttendee::where('email', 'private@example.com')->count());
    }

    public function test_a_stranger_cannot_open_the_edit_form_for_someone_elses_meeting(): void
    {
        $meeting = Meeting::factory()->create(['organizer_id' => $this->user('agent')->id]);

        Livewire::actingAs($this->user('agent'))->test(MeetingForm::class)
            ->call('open', $meeting->id)->assertForbidden();
    }

    public function test_the_form_warns_about_a_clashing_colleague_without_blocking(): void
    {
        Queue::fake();
        $me = $this->user('agent');
        $busy = $this->user('agent');
        $date = Carbon::now(config('app.business_timezone'))->addDays(3)->format('Y-m-d');
        $start = Carbon::createFromFormat('Y-m-d H:i', $date.' 10:00', config('app.business_timezone'))->utc();
        Meeting::factory()->create(['organizer_id' => $busy->id, 'title' => 'Their private thing', 'starts_at' => $start, 'ends_at' => $start->clone()->addHour()]);

        Livewire::actingAs($me)->test(MeetingForm::class)
            ->call('open', null, $date)
            ->set('startTime', '10:30')->set('endTime', '11:30')
            ->set('staffIds', [$busy->id])
            ->assertSee('already have something')
            ->assertSee($busy->name)
            ->assertDontSee('Their private thing');
    }

    public function test_task_due_dates_appear_on_the_calendar(): void
    {
        $me = $this->user('agent');
        $board = \App\Models\Board::factory()->create();
        $task = \App\Models\Task::factory()->create([
            'board_id' => $board->id, 'user_id' => $me->id, 'title' => 'File the report',
            'due_at' => now()->addHour(), 'status' => 'todo',
        ]);
        $task->assignees()->attach($me->id);

        Livewire::actingAs($me)->test(Calendar::class)->assertSee('File the report');
    }
}
