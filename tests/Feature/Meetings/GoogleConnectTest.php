<?php

declare(strict_types=1);

namespace Tests\Feature\Meetings;

use App\Models\GoogleConnection;
use App\Models\Meeting;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Settings -> Google Calendar, the OAuth round trip, Test connection, Sync now and Disconnect. */
final class GoogleConnectTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'email' => 'admin@corp.test']);
        $this->admin->assignRole('admin');
    }

    private function configure(): void
    {
        Setting::set('google_client_id', 'cid-123.apps.googleusercontent.com');
        Setting::setEncrypted('google_client_secret', 'GOCSPX-secret-value');
    }

    private function startOAuth(): string
    {
        $response = $this->actingAs($this->admin)->get(route('google.connect', ['return' => 'settings']));
        $response->assertRedirect();
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query['state'];
    }

    private function fakeGoogleSuccess(array $tokenOverrides = [], array $events = []): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(array_merge([
                'access_token' => 'ya29.access', 'refresh_token' => '1//refresh', 'expires_in' => 3599,
                'scope' => 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/calendar.events',
            ], $tokenOverrides)),
            'openidconnect.googleapis.com/*' => Http::response(['email' => 'me@corp.test']),
            'www.googleapis.com/calendar/v3/*' => Http::response(['items' => $events, 'timeZone' => 'Africa/Lagos', 'summary' => 'me@corp.test']),
        ]);
    }

    public function test_settings_saves_the_client_and_encrypts_the_secret(): void
    {
        $this->actingAs($this->admin)->put(route('settings.update'), [
            'google_client_id' => 'cid-123.apps.googleusercontent.com',
            'google_client_secret' => 'GOCSPX-secret-value',
        ])->assertSessionHasNoErrors();

        $this->assertSame('cid-123.apps.googleusercontent.com', Setting::get('google_client_id'));
        $this->assertNotSame('GOCSPX-secret-value', Setting::get('google_client_secret'));
        $this->assertSame('GOCSPX-secret-value', Setting::getEncrypted('google_client_secret'));

        // Leaving the secret blank keeps the saved one, and the page never prints it.
        $this->actingAs($this->admin)->put(route('settings.update'), ['google_client_secret' => ''])->assertSessionHasNoErrors();
        $this->assertSame('GOCSPX-secret-value', Setting::getEncrypted('google_client_secret'));
        $this->actingAs($this->admin)->get(route('settings.index'))
            ->assertOk()->assertSee('Google Calendar &amp; Meet', false)->assertSee(route('google.callback'))
            ->assertDontSee('GOCSPX-secret-value');
    }

    public function test_settings_rejects_a_client_id_with_odd_characters(): void
    {
        $this->actingAs($this->admin)->put(route('settings.update'), ['google_client_id' => 'bad id"<script>'])
            ->assertSessionHasErrors('google_client_id');
    }

    public function test_connect_without_credentials_explains_what_to_do(): void
    {
        $this->actingAs($this->admin)->get(route('google.connect', ['return' => 'settings']))
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('error');
    }

    public function test_connect_redirects_to_google_with_state_scope_and_offline_access(): void
    {
        $this->configure();

        $location = $this->actingAs($this->admin)->get(route('google.connect'))->headers->get('Location');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $this->assertSame('cid-123.apps.googleusercontent.com', $q['client_id']);
        $this->assertSame(route('google.callback'), $q['redirect_uri']);
        $this->assertSame('offline', $q['access_type']);
        $this->assertSame('code', $q['response_type']);
        $this->assertStringContainsString('calendar.events', $q['scope']);
        $this->assertSame('admin@corp.test', $q['login_hint']);
        $this->assertSame(40, strlen($q['state']));
    }

    public function test_callback_stores_encrypted_tokens_and_fills_the_calendar(): void
    {
        $this->configure();
        $this->fakeGoogleSuccess(events: [[
            'id' => 'evt1', 'status' => 'confirmed', 'summary' => 'Client call',
            'start' => ['dateTime' => now()->addDay()->toIso8601String()], 'end' => ['dateTime' => now()->addDay()->addHour()->toIso8601String()],
            'hangoutLink' => 'https://meet.google.com/abc-defg-hij',
        ]]);
        $state = $this->startOAuth();

        $this->actingAs($this->admin)->get(route('google.callback', ['state' => $state, 'code' => 'auth-code']))
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('success');

        $connection = GoogleConnection::firstWhere('user_id', $this->admin->id);
        $this->assertSame('me@corp.test', $connection->google_email);
        $this->assertSame('ya29.access', $connection->access_token);
        $this->assertSame('1//refresh', $connection->refresh_token);
        // Encrypted in the database, not stored as plain text.
        $raw = DB::table('google_connections')->first();
        $this->assertStringNotContainsString('ya29.access', (string) $raw->access_token);
        $this->assertStringNotContainsString('1//refresh', (string) $raw->refresh_token);

        $meeting = Meeting::firstWhere('external_id', 'evt1');
        $this->assertTrue($meeting->imported);
        $this->assertSame($this->admin->id, $meeting->organizer_id);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $meeting->video_url);
    }

    public function test_callback_rejects_a_wrong_or_missing_state(): void
    {
        $this->configure();
        $this->fakeGoogleSuccess();
        $this->startOAuth();

        $this->actingAs($this->admin)->get(route('google.callback', ['state' => str_repeat('x', 40), 'code' => 'c']))
            ->assertSessionHas('error');
        $this->actingAs($this->admin)->get(route('google.callback', ['code' => 'c']))->assertSessionHas('error');

        $this->assertSame(0, GoogleConnection::count());
        Http::assertNothingSent();
    }

    public function test_a_state_issued_to_someone_else_is_refused(): void
    {
        $this->configure();
        $this->fakeGoogleSuccess();
        $state = $this->startOAuth();

        $intruder = User::factory()->create(['is_active' => true]);
        $intruder->assignRole('admin');
        // The intruder replays the admin's state in their own session: it was never issued to them.
        $this->actingAs($intruder)->get(route('google.callback', ['state' => $state, 'code' => 'c']))->assertSessionHas('error');

        $this->assertSame(0, GoogleConnection::count());
    }

    public function test_callback_handles_a_denied_consent_screen(): void
    {
        $this->configure();
        $state = $this->startOAuth();

        $this->actingAs($this->admin)->get(route('google.callback', ['state' => $state, 'error' => 'access_denied']))
            ->assertSessionHas('info');
        $this->assertSame(0, GoogleConnection::count());
    }

    public function test_callback_requires_the_calendar_permission_and_a_refresh_token(): void
    {
        $this->configure();

        $this->fakeGoogleSuccess(['scope' => 'openid email']);
        $this->actingAs($this->admin)->get(route('google.callback', ['state' => $this->startOAuth(), 'code' => 'c']))->assertSessionHas('error');
        $this->assertSame(0, GoogleConnection::count());

        $this->fakeGoogleSuccess(['refresh_token' => null]);
        $this->actingAs($this->admin)->get(route('google.callback', ['state' => $this->startOAuth(), 'code' => 'c']))->assertSessionHas('error');
        $this->assertSame(0, GoogleConnection::count());
    }

    public function test_wrong_client_credentials_are_reported_in_plain_words(): void
    {
        $this->configure();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_client', 'error_description' => 'Unauthorized'], 401)]);

        $this->actingAs($this->admin)->get(route('google.callback', ['state' => $this->startOAuth(), 'code' => 'c']))
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'Client ID or Client secret'));
    }

    public function test_test_connection_reports_success_and_failure(): void
    {
        $this->configure();
        GoogleConnection::factory()->create(['user_id' => $this->admin->id, 'google_email' => 'me@corp.test']);

        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::sequence()
            ->push(['items' => [], 'timeZone' => 'Africa/Lagos'])
            ->push(['error' => ['code' => 403, 'message' => 'Google Calendar API has not been used in project 1 before or it is disabled.', 'errors' => [['reason' => 'accessNotConfigured']]]], 403)]);
        $this->actingAs($this->admin)->post(route('google.test'), ['return' => 'settings'])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'me@corp.test') && str_contains($m, 'Africa/Lagos'));

        $this->actingAs($this->admin)->post(route('google.test'), ['return' => 'settings'])
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'Enable'));
        $this->assertNotNull(GoogleConnection::first()->last_error);
    }

    public function test_test_connection_says_what_is_missing(): void
    {
        $this->actingAs($this->admin)->post(route('google.test'))->assertSessionHas('error', fn (string $m) => str_contains($m, 'Client ID'));

        $this->configure();
        $this->actingAs($this->admin)->post(route('google.test'))->assertSessionHas('error', fn (string $m) => str_contains($m, 'not connected'));
    }

    public function test_an_expired_token_is_refreshed_and_a_revoked_one_asks_to_reconnect(): void
    {
        $this->configure();
        $connection = GoogleConnection::factory()->create(['user_id' => $this->admin->id, 'expires_at' => now()->subMinute()]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'fresh-token', 'expires_in' => 3600])
                ->push(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400),
            'www.googleapis.com/calendar/v3/*' => Http::response(['items' => []]),
        ]);
        $this->actingAs($this->admin)->post(route('google.test'))->assertSessionHas('success');
        $this->assertSame('fresh-token', $connection->fresh()->access_token);

        GoogleConnection::query()->update(['expires_at' => now()->subMinute()]); // the stale model above would see no change
        $this->actingAs($this->admin)->post(route('google.test'))->assertSessionHas('error', fn (string $m) => str_contains($m, 'Connect Google Calendar again'));
        $this->assertSame(GoogleConnection::STATUS_NEEDS_REAUTH, $connection->fresh()->status);
    }

    public function test_sync_now_imports_and_reports_counts(): void
    {
        $this->configure();
        GoogleConnection::factory()->create(['user_id' => $this->admin->id]);
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['items' => [[
            'id' => 'e1', 'summary' => 'Standup', 'start' => ['dateTime' => now()->addHours(5)->toIso8601String()], 'end' => ['dateTime' => now()->addHours(6)->toIso8601String()],
        ]]])]);

        $this->actingAs($this->admin)->post(route('google.sync'), ['return' => 'meetings'])
            ->assertRedirect(route('meetings.index'))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, '1 new'));
        $this->assertSame(1, Meeting::where('external_id', 'e1')->count());
    }

    public function test_disconnect_removes_the_connection_and_only_the_imported_copies(): void
    {
        $this->configure();
        GoogleConnection::factory()->create(['user_id' => $this->admin->id]);
        Meeting::factory()->create(['organizer_id' => $this->admin->id, 'external_provider' => 'google', 'external_id' => 'imp', 'imported' => true]);
        $mine = Meeting::factory()->create(['organizer_id' => $this->admin->id, 'external_provider' => 'google', 'external_id' => 'pushed', 'imported' => false]);
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response('', 200)]);

        $this->actingAs($this->admin)->post(route('google.disconnect'))->assertSessionHas('success');

        $this->assertSame(0, GoogleConnection::count());
        $this->assertSame(0, Meeting::where('external_id', 'imp')->count());
        $this->assertNotNull(Meeting::find($mine->id));
    }

    public function test_connections_are_private_to_each_person(): void
    {
        $this->configure();
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('agent');
        GoogleConnection::factory()->create(['user_id' => $this->admin->id]);
        Http::fake();

        // The other person has no connection of their own: nothing is tested, synced or disconnected on the admin's.
        $this->actingAs($other)->post(route('google.test'))->assertSessionHas('error');
        $this->actingAs($other)->post(route('google.disconnect'));
        $this->assertSame(1, GoogleConnection::count());
        Http::assertNothingSent();
    }

    public function test_the_meetings_page_shows_connection_status_and_never_the_tokens(): void
    {
        $this->configure();
        GoogleConnection::factory()->create(['user_id' => $this->admin->id, 'google_email' => 'me@corp.test', 'access_token' => 'SECRET-ACCESS', 'refresh_token' => 'SECRET-REFRESH']);

        $this->actingAs($this->admin)->get(route('meetings.index'))
            ->assertOk()->assertSee('me@corp.test')->assertSee('Test connection')
            ->assertDontSee('SECRET-ACCESS')->assertDontSee('SECRET-REFRESH');
    }
}
