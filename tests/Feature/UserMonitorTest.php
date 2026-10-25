<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrackUserSession;
use App\Livewire\UserMonitor;
use App\Models\User;
use App\Models\UserSession;
use App\Services\UserSessionMonitor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The user monitor: which people are signed in right now, from where, and an
 * admin's ability to sign them out. Tracking is our own table (not the session
 * store) so it works on any SESSION_DRIVER; a revoked row is what actually logs
 * the browser out on its next request.
 */
class UserMonitorTest extends TestCase
{
    use RefreshDatabase;

    /** A fixed session id, so successive test requests are the same browser session. */
    private const SID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Run one request from a browser holding SID through TrackUserSession directly.
     * (The HTTP test client mints a new session id per request, which would make
     * "same browser, next request" untestable.)
     *
     * @param  array<string,string>  $headers
     */
    private function browserRequest(User $user, array $headers = [], ?string $userAgent = null): Response
    {
        $this->actingAs($user);

        $request = Request::create('/dashboard', 'GET', server: array_filter([
            'HTTP_USER_AGENT' => $userAgent,
            'REMOTE_ADDR' => '203.0.113.9',
        ]));
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $store = app('session')->driver();
        $store->setId(self::SID);
        $store->start();
        $request->setLaravelSession($store);
        $request->setUserResolver(fn () => $user);

        return app(TrackUserSession::class)->handle($request, fn () => response('ok'));
    }

    // ── tracking ────────────────────────────────────────────────────────────

    public function test_an_authenticated_request_records_the_session(): void
    {
        $user = $this->userWithRole(User::ROLE_AGENT);

        $response = $this->browserRequest($user, userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/155.0 Safari/537.36');

        $this->assertSame(200, $response->getStatusCode());
        $row = UserSession::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(self::SID, $row->session_id);
        $this->assertNull($row->revoked_at);
        $this->assertSame('203.0.113.9', $row->ip_address);
        $this->assertStringContainsString('Chrome', (string) $row->user_agent);
    }

    public function test_guests_are_not_tracked(): void
    {
        $this->get(route('login'))->assertOk();

        $this->assertSame(0, UserSession::count());
    }

    public function test_last_active_is_not_rewritten_on_every_request(): void
    {
        $user = $this->userWithRole(User::ROLE_AGENT);
        $this->browserRequest($user);
        $row = UserSession::firstOrFail();
        $row->forceFill(['last_active_at' => now()->subSeconds(10)])->save();
        $before = $row->fresh()->last_active_at;

        $this->browserRequest($user);

        $this->assertSame(1, UserSession::count(), 'same browser session = one row');
        $this->assertTrue($before->equalTo($row->fresh()->last_active_at), 'writes are throttled to once per interval');

        $row->forceFill(['last_active_at' => now()->subSeconds(UserSessionMonitor::TOUCH_INTERVAL_SECONDS + 5)])->save();
        $stale = $row->fresh()->last_active_at;

        $this->browserRequest($user);

        $this->assertTrue($row->fresh()->last_active_at->gt($stale), 'a stale row is refreshed');
    }

    public function test_a_revoked_session_is_signed_out_on_its_next_request(): void
    {
        $user = $this->userWithRole(User::ROLE_AGENT);
        $this->assertSame(200, $this->browserRequest($user)->getStatusCode());

        UserSession::firstOrFail()->forceFill(['revoked_at' => now()])->save();

        $response = $this->browserRequest($user);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('login'), $response->headers->get('Location'));
        $this->assertGuest();
    }

    public function test_a_revoked_livewire_request_gets_a_page_expired_response_not_a_redirect(): void
    {
        $user = $this->userWithRole(User::ROLE_AGENT);
        $this->browserRequest($user);
        UserSession::firstOrFail()->forceFill(['revoked_at' => now()])->save();

        try {
            $this->browserRequest($user, ['X-Livewire' => 'true']);
            $this->fail('expected a 419');
        } catch (HttpException $e) {
            $this->assertSame(419, $e->getStatusCode());
        }
    }

    public function test_tracking_failure_never_breaks_the_site(): void
    {
        $user = $this->userWithRole(User::ROLE_AGENT);
        Schema::drop('user_sessions');

        $this->assertSame(200, $this->browserRequest($user)->getStatusCode());
    }

    // ── page access ─────────────────────────────────────────────────────────

    public function test_manager_can_view_but_agent_cannot(): void
    {
        $this->actingAs($this->userWithRole(User::ROLE_AGENT))
            ->get(route('user-monitor'))->assertForbidden();

        $this->actingAs($this->userWithRole(User::ROLE_MANAGER))
            ->get(route('user-monitor'))->assertOk()->assertSee('Signed-in users');
    }

    public function test_the_page_lists_active_sessions_and_hides_revoked_and_expired(): void
    {
        $viewer = $this->userWithRole(User::ROLE_ADMIN);
        $live = $this->userWithRole(User::ROLE_AGENT, 'Lively Agent');
        $gone = $this->userWithRole(User::ROLE_AGENT, 'Revoked Person');
        $stale = $this->userWithRole(User::ROLE_AGENT, 'Expired Person');
        $this->makeSession($live);
        $this->makeSession($gone, ['revoked_at' => now()]);
        $this->makeSession($stale, ['last_active_at' => now()->subHours(5)]);

        Livewire::actingAs($viewer)->test(UserMonitor::class)
            ->assertSee('Lively Agent')
            ->assertDontSee('Revoked Person')
            ->assertDontSee('Expired Person');
    }

    // ── logging people out ──────────────────────────────────────────────────

    public function test_admin_can_log_out_one_session(): void
    {
        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $agent = $this->userWithRole(User::ROLE_AGENT);
        $a = $this->makeSession($agent);
        $b = $this->makeSession($agent);

        Livewire::actingAs($admin)->test(UserMonitor::class)->call('logoutSession', $a->id);

        $this->assertNotNull($a->fresh()->revoked_at);
        $this->assertNull($b->fresh()->revoked_at, 'other devices stay signed in');
    }

    public function test_logging_a_user_out_everywhere_also_stops_call_routing_to_them(): void
    {
        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $agent = $this->userWithRole(User::ROLE_AGENT);
        $agent->forceFill(['last_seen_at' => now()])->save();
        $a = $this->makeSession($agent);
        $b = $this->makeSession($agent);

        Livewire::actingAs($admin)->test(UserMonitor::class)->call('logoutUser', $agent->id);

        $this->assertNotNull($a->fresh()->revoked_at);
        $this->assertNotNull($b->fresh()->revoked_at);
        $this->assertNull($agent->fresh()->last_seen_at, 'no longer "available" for the call router');
    }

    public function test_manager_without_users_edit_cannot_log_anyone_out(): void
    {
        $manager = $this->userWithRole(User::ROLE_MANAGER);
        $row = $this->makeSession($this->userWithRole(User::ROLE_AGENT));

        Livewire::actingAs($manager)->test(UserMonitor::class)
            ->call('logoutSession', $row->id)
            ->assertForbidden();

        $this->assertNull($row->fresh()->revoked_at);
    }

    public function test_an_admin_cannot_log_out_a_super_admin(): void
    {
        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $boss = $this->userWithRole(User::ROLE_SUPER_ADMIN);
        $row = $this->makeSession($boss);

        Livewire::actingAs($admin)->test(UserMonitor::class)
            ->call('logoutSession', $row->id)
            ->assertForbidden();

        $this->assertNull($row->fresh()->revoked_at);
    }

    public function test_you_cannot_log_out_the_session_you_are_using(): void
    {
        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $mine = $this->makeSession($admin, ['session_id' => session()->getId()]);

        Livewire::actingAs($admin)->test(UserMonitor::class)
            ->call('logoutSession', $mine->id);

        $this->assertNull($mine->fresh()->revoked_at);
    }

    public function test_log_out_everyone_else_spares_me_and_super_admins_when_i_am_an_admin(): void
    {
        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $mine = $this->makeSession($admin, ['session_id' => session()->getId()]);
        $agent = $this->makeSession($this->userWithRole(User::ROLE_AGENT));
        $manager = $this->makeSession($this->userWithRole(User::ROLE_MANAGER));
        $boss = $this->makeSession($this->userWithRole(User::ROLE_SUPER_ADMIN));

        Livewire::actingAs($admin)->test(UserMonitor::class)->call('logoutEveryoneElse');

        $this->assertNull($mine->fresh()->revoked_at);
        $this->assertNotNull($agent->fresh()->revoked_at);
        $this->assertNotNull($manager->fresh()->revoked_at);
        $this->assertNull($boss->fresh()->revoked_at);
    }

    // ── housekeeping ────────────────────────────────────────────────────────

    public function test_pruning_removes_expired_and_old_revoked_rows_only(): void
    {
        $u = $this->userWithRole(User::ROLE_AGENT);
        $fresh = $this->makeSession($u);
        $expired = $this->makeSession($u, ['last_active_at' => now()->subDays(3)]);
        $oldRevoked = $this->makeSession($u, ['revoked_at' => now()->subDays(2), 'last_active_at' => now()->subMinutes(5)]);
        $newRevoked = $this->makeSession($u, ['revoked_at' => now()->subMinutes(5)]);

        app(UserSessionMonitor::class)->prune();

        $this->assertNotNull($fresh->fresh());
        $this->assertNotNull($newRevoked->fresh(), 'a fresh revocation must survive so the browser still gets signed out');
        $this->assertNull($expired->fresh());
        $this->assertNull($oldRevoked->fresh());
    }

    public function test_user_agent_is_summarised_for_humans(): void
    {
        $this->assertSame('Chrome on Windows', UserSessionMonitor::describeAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/155.0 Safari/537.36'));
        $this->assertSame('Safari on iOS', UserSessionMonitor::describeAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile Safari/604.1'));
        $this->assertSame('Unknown device', UserSessionMonitor::describeAgent(null));
    }

    private function userWithRole(string $role, ?string $name = null): User
    {
        $user = User::factory()->create(array_filter([
            'role' => $role,
            'is_active' => true,
            'name' => $name,
        ]));
        $user->assignRole($role);

        return $user;
    }

    private function makeSession(User $user, array $overrides = []): UserSession
    {
        return UserSession::create(array_merge([
            'user_id' => $user->id,
            'session_id' => 'sess-'.bin2hex(random_bytes(8)),
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/155.0 Safari/537.36',
            'last_active_at' => now(),
        ], $overrides));
    }
}
