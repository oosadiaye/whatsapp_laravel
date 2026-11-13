<?php

declare(strict_types=1);

namespace Tests\Feature\Meetings;

use App\Jobs\SendMeetingMail;
use App\Livewire\Meetings\Calendar;
use App\Livewire\Meetings\MeetingForm;
use App\Models\GoogleConnection;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\Setting;
use App\Models\User;
use App\Services\Google\GoogleCalendarSync;
use App\Services\Meetings\MeetingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Pushing BlastIQ meetings to Google, importing Google events, and what the people see. */
final class GoogleSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private GoogleConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Setting::set('google_client_id', 'cid');
        Setting::setEncrypted('google_client_secret', 'GOCSPX-secret-value');
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('agent');
        $this->connection = GoogleConnection::factory()->create(['user_id' => $this->user->id]);
    }

    private function createdEvent(array $over = []): array
    {
        return array_merge([
            'id' => 'gevt1', 'htmlLink' => 'https://www.google.com/calendar/event?eid=x',
            'hangoutLink' => 'https://meet.google.com/new-meet-link',
        ], $over);
    }

    private function meeting(array $over = []): Meeting
    {
        $tomorrow = now(config('app.business_timezone'))->addDay()->setTime(10, 0)->utc();

        return Meeting::factory()->create(array_merge([
            'organizer_id' => $this->user->id, 'title' => 'Pitch', 'starts_at' => $tomorrow, 'ends_at' => $tomorrow->clone()->addHour(),
        ], $over));
    }

    public function test_push_creates_the_event_with_a_meet_room_and_stores_the_link(): void
    {
        $meeting = $this->meeting();
        MeetingAttendee::factory()->create(['meeting_id' => $meeting->id, 'email' => 'ann@client.test', 'name' => 'Ann']);
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response($this->createdEvent())]);

        $error = app(GoogleCalendarSync::class)->push($meeting->fresh());

        $this->assertNull($error);
        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST'
                && str_contains($r->url(), '/calendars/primary/events')
                && str_contains($r->url(), 'conferenceDataVersion=1')
                && str_contains($r->url(), 'sendUpdates=all')
                && $r->hasHeader('Authorization', 'Bearer '.$this->connection->access_token)
                && $r['summary'] === 'Pitch'
                && $r['attendees'][0]['email'] === 'ann@client.test'
                && $r['conferenceData']['createRequest']['conferenceSolutionKey']['type'] === 'hangoutsMeet';
        });
        $meeting->refresh();
        $this->assertSame('gevt1', $meeting->external_id);
        $this->assertSame('google', $meeting->external_provider);
        $this->assertSame('https://meet.google.com/new-meet-link', $meeting->video_url);
        $this->assertTrue($meeting->isGoogleManaged());
    }

    public function test_push_without_a_connection_or_when_google_fails_returns_a_message(): void
    {
        $stranger = User::factory()->create();
        $this->assertStringContainsString('not connected', app(GoogleCalendarSync::class)->push($this->meeting(['organizer_id' => $stranger->id])));

        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['error' => ['message' => 'boom']], 503)]);
        $meeting = $this->meeting();
        $this->assertStringContainsString('having trouble', app(GoogleCalendarSync::class)->push($meeting));
        $this->assertNull($meeting->fresh()->external_id);
    }

    public function test_form_creates_in_google_and_blastiq_sends_no_invitation_emails(): void
    {
        Queue::fake();
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response($this->createdEvent())]);
        $date = now(config('app.business_timezone'))->addDays(2)->format('Y-m-d');

        Livewire::actingAs($this->user)->test(MeetingForm::class)
            ->call('open', null, $date)
            ->assertSet('createInGoogle', true)
            ->set('title', 'Board call')->set('startTime', '10:00')->set('endTime', '11:00')
            ->set('externalEmails', 'client@example.com')
            ->call('save')->assertHasNoErrors()->assertDispatched('notify');

        $meeting = Meeting::firstWhere('title', 'Board call');
        $this->assertSame('gevt1', $meeting->external_id);
        $this->assertSame(['client@example.com'], $meeting->attendees()->pluck('email')->all());
        Queue::assertNotPushed(SendMeetingMail::class); // Google sends the invitations
    }

    public function test_form_falls_back_to_blastiq_emails_when_google_fails(): void
    {
        Queue::fake();
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['error' => ['message' => 'nope', 'errors' => [['reason' => 'accessNotConfigured']]]], 403)]);
        $date = now(config('app.business_timezone'))->addDays(2)->format('Y-m-d');

        Livewire::actingAs($this->user)->test(MeetingForm::class)
            ->call('open', null, $date)
            ->set('title', 'Board call')->set('externalEmails', 'client@example.com')
            ->call('save')->assertHasNoErrors()
            ->assertDispatched('notify', fn ($name, $params) => ($params['type'] ?? null) === 'error' && str_contains($params['message'], 'Enable'));

        $meeting = Meeting::firstWhere('title', 'Board call');
        $this->assertNotNull($meeting);               // the meeting is kept
        $this->assertNull($meeting->external_id);
        Queue::assertPushed(SendMeetingMail::class, fn (SendMeetingMail $j) => $j->kind === 'invite' && $j->email === 'client@example.com');
    }

    public function test_unticking_the_option_uses_plain_blastiq_invitations(): void
    {
        Queue::fake();
        Http::fake();
        $date = now(config('app.business_timezone'))->addDays(2)->format('Y-m-d');

        Livewire::actingAs($this->user)->test(MeetingForm::class)
            ->call('open', null, $date)->set('createInGoogle', false)
            ->set('title', 'Local only')->set('externalEmails', 'client@example.com')
            ->call('save')->assertHasNoErrors();

        Http::assertNothingSent();
        Queue::assertPushed(SendMeetingMail::class, 1);
    }

    public function test_the_option_is_off_for_people_who_have_not_connected(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('agent');

        Livewire::actingAs($other)->test(MeetingForm::class)
            ->call('open', null, now()->addDay()->format('Y-m-d'))
            ->assertSet('createInGoogle', false)
            ->assertDontSee('Create it in my Google Calendar');

        Livewire::actingAs($this->user)->test(MeetingForm::class)
            ->call('open', null, now()->addDay()->format('Y-m-d'))
            ->assertSee('Create it in my Google Calendar');
    }

    public function test_changing_and_cancelling_a_google_meeting_goes_to_google_not_to_email(): void
    {
        Queue::fake();
        $meeting = $this->meeting(['external_provider' => 'google', 'external_id' => 'gevt1']);
        MeetingAttendee::factory()->create(['meeting_id' => $meeting->id, 'email' => 'ann@client.test']);
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'gevt1'])]);
        $service = app(MeetingService::class);

        $service->update($meeting->fresh(), ['title' => 'Pitch v2', 'starts_at' => $meeting->starts_at, 'ends_at' => $meeting->ends_at], [['email' => 'ann@client.test']]);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/events/gevt1') && $r['summary'] === 'Pitch v2');
        $service->cancel($meeting->fresh());
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/events/gevt1'));

        $this->assertTrue($meeting->fresh()->isCancelled());
        $this->assertNull($service->warning);
        Queue::assertNotPushed(SendMeetingMail::class);
    }

    public function test_if_google_cannot_be_told_the_guests_are_emailed_and_the_organiser_is_warned(): void
    {
        Queue::fake();
        $meeting = $this->meeting(['external_provider' => 'google', 'external_id' => 'gevt1']);
        MeetingAttendee::factory()->create(['meeting_id' => $meeting->id, 'email' => 'ann@client.test']);
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response([], 500)]);
        $service = app(MeetingService::class);

        $service->cancel($meeting->fresh());

        $this->assertTrue($meeting->fresh()->isCancelled());
        $this->assertStringContainsString('could not be updated', (string) $service->warning);
        Queue::assertPushed(SendMeetingMail::class, fn (SendMeetingMail $j) => $j->kind === 'cancel');
    }

    public function test_deleting_in_google_what_is_already_gone_counts_as_done(): void
    {
        $meeting = $this->meeting(['external_provider' => 'google', 'external_id' => 'gone']);
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['error' => ['message' => 'Resource has been deleted']], 410)]);

        $this->assertNull(app(GoogleCalendarSync::class)->cancel($meeting));
    }

    public function test_import_mirrors_google_events_read_only_and_is_idempotent(): void
    {
        $start = now()->addDays(2)->setTime(9, 0);
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['items' => [
            ['id' => 'a1', 'status' => 'confirmed', 'summary' => 'Teams sync', 'description' => 'Join https://teams.microsoft.com/l/meetup-join/abc',
                'start' => ['dateTime' => $start->toIso8601String()], 'end' => ['dateTime' => $start->clone()->addHour()->toIso8601String()],
                'organizer' => ['email' => 'boss@corp.test', 'displayName' => 'The Boss'], 'htmlLink' => 'https://www.google.com/calendar/event?eid=a1',
                'attendees' => [['email' => 'ann@client.test', 'responseStatus' => 'accepted']]],
            ['id' => 'a2', 'status' => 'confirmed', 'summary' => 'Holiday', 'start' => ['date' => '2030-01-01'], 'end' => ['date' => '2030-01-02']],
        ]])]);
        $sync = app(GoogleCalendarSync::class);

        $first = $sync->import($this->connection);
        $second = $sync->import($this->connection->fresh());

        $this->assertSame(['created' => 1, 'updated' => 0, 'cancelled' => 0], $first);
        $this->assertSame(['created' => 0, 'updated' => 0, 'cancelled' => 0], $second);
        $meeting = Meeting::firstWhere('external_id', 'a1');
        $this->assertTrue($meeting->imported);
        $this->assertSame('The Boss', $meeting->external_organizer);
        $this->assertStringStartsWith('https://teams.microsoft.com/', $meeting->video_url);
        $this->assertSame('accepted', $meeting->attendees()->first()->response);
        $this->assertNull($meeting->attendees()->first()->user_id);
        $this->assertSame(1, $this->connection->fresh()->last_import_count);
        $this->assertNull($this->connection->fresh()->last_error);
        $this->assertFalse($meeting->canBeManagedBy($this->user));   // read-only copy
    }

    public function test_import_applies_changes_cancellations_and_deletions(): void
    {
        $start = now()->addDays(2)->setTime(9, 0);
        $event = fn (string $id, string $title, array $extra = []) => array_merge([
            'id' => $id, 'status' => 'confirmed', 'summary' => $title,
            'start' => ['dateTime' => $start->toIso8601String()], 'end' => ['dateTime' => $start->clone()->addHour()->toIso8601String()],
        ], $extra);
        $sync = app(GoogleCalendarSync::class);

        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::sequence()
            ->push(['items' => [$event('e1', 'One'), $event('e2', 'Two'), $event('e3', 'Three')]])
            ->push(['items' => [$event('e1', 'One (renamed)'), $event('e2', 'Two', ['status' => 'cancelled'])]])]);

        $sync->import($this->connection);
        $result = $sync->import($this->connection->fresh());

        $this->assertSame('One (renamed)', Meeting::firstWhere('external_id', 'e1')->title);
        $this->assertTrue(Meeting::firstWhere('external_id', 'e2')->isCancelled());     // cancelled in Google
        $this->assertTrue(Meeting::firstWhere('external_id', 'e3')->isCancelled());     // vanished from Google
        $this->assertSame(['created' => 0, 'updated' => 1, 'cancelled' => 2], $result);
    }

    public function test_an_event_i_declined_in_google_is_not_shown(): void
    {
        $start = now()->addDay();
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['items' => [[
            'id' => 'd1', 'summary' => 'Not for me', 'start' => ['dateTime' => $start->toIso8601String()], 'end' => ['dateTime' => $start->clone()->addHour()->toIso8601String()],
            'attendees' => [['email' => 'me@corp.test', 'self' => true, 'responseStatus' => 'declined']],
        ]]])]);

        app(GoogleCalendarSync::class)->import($this->connection);

        $this->assertSame(0, Meeting::where('external_id', 'd1')->count());
    }

    public function test_answers_to_a_pushed_meeting_flow_back_from_google(): void
    {
        $meeting = $this->meeting(['external_provider' => 'google', 'external_id' => 'gevt1', 'title' => 'Pitch']);
        $row = MeetingAttendee::factory()->create(['meeting_id' => $meeting->id, 'email' => 'ann@client.test']);
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['items' => [[
            'id' => 'gevt1', 'status' => 'confirmed', 'summary' => 'Pitch',
            'start' => ['dateTime' => $meeting->starts_at->toIso8601String()], 'end' => ['dateTime' => $meeting->ends_at->toIso8601String()],
            'attendees' => [['email' => 'ann@client.test', 'responseStatus' => 'accepted']],
        ]]])]);

        app(GoogleCalendarSync::class)->import($this->connection);

        $this->assertSame('accepted', $row->fresh()->response);
        $this->assertFalse($meeting->fresh()->imported);
        $this->assertSame(1, Meeting::where('external_id', 'gevt1')->count()); // no duplicate copy
    }

    public function test_imported_meetings_show_on_the_calendar_but_cannot_be_edited_or_cancelled(): void
    {
        $start = now()->addHours(3);
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['items' => [[
            'id' => 'v1', 'summary' => 'From Google', 'start' => ['dateTime' => $start->toIso8601String()], 'end' => ['dateTime' => $start->clone()->addHour()->toIso8601String()],
            'htmlLink' => 'https://www.google.com/calendar/event?eid=v1',
        ]]])]);
        app(GoogleCalendarSync::class)->import($this->connection);
        $imported = Meeting::firstWhere('external_id', 'v1');

        Livewire::actingAs($this->user)->test(Calendar::class)
            ->assertSee('From Google')
            ->call('openMeeting', $imported->id)
            ->assertSee('read-only copy')->assertSee('Open in Google Calendar')
            ->assertDontSee('Cancel meeting')
            ->call('cancelMeeting', $imported->id)->assertForbidden();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $this->assertFalse($imported->canBeManagedBy($admin));   // not even managers can change the mirror
    }

    public function test_the_scheduled_sync_command_imports_every_active_connection_and_skips_broken_ones(): void
    {
        $second = GoogleConnection::factory()->create();
        $revoked = GoogleConnection::factory()->create(['status' => GoogleConnection::STATUS_NEEDS_REAUTH]);
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['items' => []])]);

        $this->artisan('google:sync')->assertSuccessful();

        $this->assertNotNull($this->connection->fresh()->last_synced_at);
        $this->assertNotNull($second->fresh()->last_synced_at);
        $this->assertNull($revoked->fresh()->last_synced_at);
    }

    public function test_one_failing_account_does_not_stop_the_others(): void
    {
        $second = GoogleConnection::factory()->create();
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::sequence()->push([], 500)->push(['items' => []])]);

        $this->artisan('google:sync')->assertSuccessful();

        $this->assertNotNull($this->connection->fresh()->last_error);
        $this->assertNotNull($second->fresh()->last_synced_at);
    }

    public function test_the_sync_does_nothing_until_google_is_configured(): void
    {
        Setting::query()->delete();
        Setting::flushCache();
        Http::fake();

        $this->artisan('google:sync')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_reminders_skip_meetings_that_live_in_google(): void
    {
        config(['meetings.reminders_enabled' => true]);
        $meeting = $this->meeting(['starts_at' => now()->addMinutes(8), 'ends_at' => now()->addHour(), 'external_provider' => 'google', 'external_id' => 'gx']);
        Meeting::whereKey($meeting->id)->toBase()->update(['updated_at' => Carbon::now()->subDays(3)]);
        \Illuminate\Support\Facades\Mail::fake();

        $this->artisan('meetings:remind');

        \Illuminate\Support\Facades\Mail::assertNothingQueued();
    }
}
