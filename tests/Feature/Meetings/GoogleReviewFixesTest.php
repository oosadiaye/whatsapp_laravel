<?php

declare(strict_types=1);

namespace Tests\Feature\Meetings;

use App\Jobs\ImportGoogleCalendar;
use App\Livewire\Meetings\Calendar;
use App\Livewire\Meetings\MeetingForm;
use App\Models\GoogleConnection;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\Setting;
use App\Models\User;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleCalendarSync;
use App\Services\Google\GoogleEventMapper;
use App\Services\Meetings\MeetingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Regression tests for the problems found in the security review of the Google integration. */
final class GoogleReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Setting::set('google_client_id', 'cid');
        Setting::setEncrypted('google_client_secret', 'GOCSPX-secret-value');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /** Tomorrow 10:00-11:00 in the business timezone, pinned so tests do not depend on the hour they run. */
    private function when(): array
    {
        $start = now(config('app.business_timezone'))->addDay()->setTime(10, 0)->utc();

        return ['starts_at' => $start, 'ends_at' => $start->clone()->addHour()];
    }

    public function test_a_manager_on_everyone_never_sees_a_colleagues_google_calendar_titles(): void
    {
        $alice = $this->user('agent');
        $bob = $this->user('manager');
        Meeting::factory()->create(['organizer_id' => $alice->id, 'title' => 'Therapy', 'imported' => true, 'external_provider' => 'google', 'external_id' => 'p1', ...$this->when()]);
        Meeting::factory()->create(['organizer_id' => $alice->id, 'title' => 'Team sync', ...$this->when()]);
        Meeting::factory()->create(['organizer_id' => $bob->id, 'title' => 'My own imported one', 'imported' => true, 'external_provider' => 'google', 'external_id' => 'p2', ...$this->when()]);

        Livewire::actingAs($bob)->test(Calendar::class)
            ->call('setScope', 'everyone')
            ->assertSee('Team sync')                 // BlastIQ meetings are shared with managers
            ->assertSee('My own imported one')       // their own mirror is theirs
            ->assertDontSee('Therapy');              // someone else's mirror is private
    }

    public function test_a_failed_google_cancel_is_not_undone_by_the_next_sync(): void
    {
        $user = $this->user('agent');
        $connection = GoogleConnection::factory()->create(['user_id' => $user->id]);
        $when = $this->when();
        $meeting = Meeting::factory()->create(['organizer_id' => $user->id, 'title' => 'Pitch', 'external_provider' => 'google', 'external_id' => 'g1', ...$when]);
        Queue::fake();

        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::sequence()
            ->push([], 500)                                    // the DELETE fails
            ->push(['items' => [[                              // the event is still in Google at the next sync
                'id' => 'g1', 'status' => 'confirmed', 'summary' => 'Pitch',
                'start' => ['dateTime' => $when['starts_at']->toIso8601String()], 'end' => ['dateTime' => $when['ends_at']->toIso8601String()],
            ]]])]);

        app(MeetingService::class)->cancel($meeting->fresh());
        app(GoogleCalendarSync::class)->import($connection->fresh());

        $this->assertTrue($meeting->fresh()->isCancelled());   // stays cancelled
    }

    public function test_sync_does_not_overwrite_what_blastiq_holds_for_its_own_meetings(): void
    {
        $user = $this->user('agent');
        $connection = GoogleConnection::factory()->create(['user_id' => $user->id]);
        $when = $this->when();
        $meeting = Meeting::factory()->create([
            'organizer_id' => $user->id, 'title' => 'Plan', 'description' => 'Agenda: a <b and c> d, x < y', 'location' => 'Room 4',
            'external_provider' => 'google', 'external_id' => 'g2', ...$when,
        ]);
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['items' => [[
            'id' => 'g2', 'status' => 'confirmed', 'summary' => 'Old title in Google', 'description' => 'normalised', 'location' => 'Elsewhere',
            'start' => ['dateTime' => $when['starts_at']->clone()->addHours(3)->toIso8601String()], 'end' => ['dateTime' => $when['ends_at']->clone()->addHours(3)->toIso8601String()],
            'hangoutLink' => 'https://meet.google.com/abc-defg-hij', 'htmlLink' => 'https://www.google.com/calendar/event?eid=g2',
        ]]])]);

        app(GoogleCalendarSync::class)->import($connection);

        $meeting->refresh();
        $this->assertSame('Plan', $meeting->title);
        $this->assertSame('Agenda: a <b and c> d, x < y', $meeting->description);
        $this->assertSame('Room 4', $meeting->location);
        $this->assertTrue($meeting->starts_at->equalTo($when['starts_at']));
        $this->assertSame('https://meet.google.com/abc-defg-hij', $meeting->video_url);   // the Meet link IS taken back
        $this->assertFalse($meeting->imported);
    }

    public function test_only_the_organiser_can_change_a_meeting_held_in_their_google_calendar(): void
    {
        $owner = $this->user('agent');
        $manager = $this->user('manager');
        $meeting = Meeting::factory()->create(['organizer_id' => $owner->id, 'external_provider' => 'google', 'external_id' => 'g3', ...$this->when()]);
        $plain = Meeting::factory()->create(['organizer_id' => $owner->id, ...$this->when()]);

        $this->assertTrue($meeting->canBeManagedBy($owner));
        $this->assertFalse($meeting->canBeManagedBy($manager));     // would be acting as the owner inside Google
        $this->assertTrue($plain->canBeManagedBy($manager));        // ordinary meetings: unchanged

        Livewire::actingAs($manager)->test(Calendar::class)->call('setScope', 'everyone')
            ->call('cancelMeeting', $meeting->id)->assertForbidden();
        Livewire::actingAs($manager)->test(MeetingForm::class)->call('open', $meeting->id)->assertForbidden();
    }

    public function test_scheduling_for_a_colleague_never_writes_into_their_google_calendar(): void
    {
        Queue::fake();
        Http::fake();
        $manager = $this->user('manager');
        $colleague = $this->user('agent');
        GoogleConnection::factory()->create(['user_id' => $colleague->id]);
        GoogleConnection::factory()->create(['user_id' => $manager->id]);

        Livewire::actingAs($manager)->test(MeetingForm::class)
            ->call('open', null, now(config('app.business_timezone'))->addDays(2)->format('Y-m-d'))
            ->set('organizerId', $colleague->id)->set('createInGoogle', true)
            ->assertDontSee('Create it in my Google Calendar')
            ->set('title', 'For Chidi')->call('save')->assertHasNoErrors();

        Http::assertNothingSent();
        $this->assertSame($colleague->id, Meeting::firstWhere('title', 'For Chidi')->organizer_id);
    }

    public function test_only_known_video_hosts_become_a_join_button(): void
    {
        $phish = ['conferenceData' => ['entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://evil.example.com/join/abc']]]];
        $this->assertNull(GoogleEventMapper::videoUrl($phish));

        $this->assertNull(GoogleEventMapper::videoUrl(['hangoutLink' => 'https://meet.google.com.evil.example.com/x']));
        $this->assertNull(GoogleEventMapper::videoUrl(['hangoutLink' => 'https://evil.example.com/meet.google.com']));
        $this->assertSame('https://meet.google.com/abc-defg-hij', GoogleEventMapper::videoUrl(['hangoutLink' => 'https://meet.google.com/abc-defg-hij']));
        $this->assertSame('https://acme.zoom.us/j/1', GoogleEventMapper::videoUrl(['conferenceData' => ['entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://acme.zoom.us/j/1']]]]));
    }

    public function test_calendar_markers_are_not_imported_and_duplicate_guests_are_collapsed(): void
    {
        $base = ['id' => 'x', 'start' => ['dateTime' => now()->addDay()->toIso8601String()], 'end' => ['dateTime' => now()->addDay()->addHour()->toIso8601String()]];

        foreach (['outOfOffice', 'focusTime', 'workingLocation'] as $type) {
            $this->assertNull(GoogleEventMapper::fromGoogle($base + ['eventType' => $type]));
        }
        $this->assertNotNull(GoogleEventMapper::fromGoogle($base + ['eventType' => 'default']));

        $mapped = GoogleEventMapper::fromGoogle($base + ['attendees' => [
            ['email' => 'Ann@x.test', 'responseStatus' => 'accepted'], ['email' => 'ann@x.test', 'responseStatus' => 'declined'], ['email' => str_repeat('a', 200).'@x.test'],
        ]]);
        $this->assertSame(['ann@x.test'], array_column($mapped['attendees'], 'email'));
    }

    public function test_a_second_sync_while_one_is_running_is_refused_not_duplicated(): void
    {
        $connection = GoogleConnection::factory()->create();
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['items' => []])]);
        $lock = Cache::lock('gcal-sync:'.$connection->id, 60);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(GoogleApiException::class);
            app(GoogleCalendarSync::class)->import($connection);
        } finally {
            $lock->release();
        }
    }

    public function test_sync_now_during_a_running_sync_shows_a_message_not_an_error_page(): void
    {
        $user = $this->user('agent');
        $connection = GoogleConnection::factory()->create(['user_id' => $user->id]);
        $lock = Cache::lock('gcal-sync:'.$connection->id, 60);
        $lock->get();
        Http::fake();

        try {
            $this->actingAs($user)->post(route('google.sync'))->assertRedirect()->assertSessionHas('error', fn (string $m) => str_contains($m, 'already running'));
        } finally {
            $lock->release();
        }
    }

    public function test_connecting_loads_the_calendar_in_the_background(): void
    {
        Queue::fake();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600, 'scope' => 'openid email https://www.googleapis.com/auth/calendar.events']),
            'openidconnect.googleapis.com/*' => Http::response(['email' => 'me@corp.test']),
        ]);
        $user = $this->user('admin');
        $location = $this->actingAs($user)->get(route('google.connect'))->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        $this->actingAs($user)->get(route('google.callback', ['state' => $q['state'], 'code' => 'c']))->assertSessionHas('success');

        Queue::assertPushed(ImportGoogleCalendar::class);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/calendar/v3/'));   // no calendar call in the web request
    }

    public function test_the_background_import_records_a_failure_instead_of_crashing(): void
    {
        $connection = GoogleConnection::factory()->create();
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response([], 503)]);

        (new ImportGoogleCalendar($connection->id))->handle(app(GoogleCalendarSync::class));

        $this->assertNotNull($connection->fresh()->last_error);
    }

    public function test_attendee_answers_still_flow_back_for_pushed_meetings(): void
    {
        $user = $this->user('agent');
        $connection = GoogleConnection::factory()->create(['user_id' => $user->id]);
        $when = $this->when();
        $meeting = Meeting::factory()->create(['organizer_id' => $user->id, 'external_provider' => 'google', 'external_id' => 'g9', ...$when]);
        $row = MeetingAttendee::factory()->create(['meeting_id' => $meeting->id, 'email' => 'ann@client.test']);
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['items' => [[
            'id' => 'g9', 'status' => 'confirmed', 'summary' => 'x',
            'start' => ['dateTime' => $when['starts_at']->toIso8601String()], 'end' => ['dateTime' => $when['ends_at']->toIso8601String()],
            'attendees' => [['email' => 'ann@client.test', 'responseStatus' => 'accepted']],
        ]]])]);

        app(GoogleCalendarSync::class)->import($connection);

        $this->assertSame('accepted', $row->fresh()->response);
    }
}
