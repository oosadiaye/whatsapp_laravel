<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Board;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

/**
 * The board's private Echo channel must not be broader than the board's own
 * route middleware, or a private channel could hand out a live view of a board
 * the user cannot open in the browser.
 */
class TaskBoardChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        /*
         * phpunit.xml pins BROADCAST_CONNECTION=null. Two consequences:
         *
         *  1. NullBroadcaster answers every auth request with an empty 200 and
         *     never consults a channel callback, so it can assert nothing.
         *  2. Broadcast::channel() in routes/channels.php registers callbacks on
         *     whichever driver is resolved at boot — i.e. the null one.
         *
         * So switch to Reverb (the real production driver; its auth path is
         * pure local HMAC and needs no broker), drop the cached null driver,
         * then re-require the channels file so the callbacks land on it.
         */
        config(['broadcasting.default' => 'reverb']);
        Broadcast::forgetDrivers();

        require base_path('routes/channels.php');
    }

    private function makeUser(?string $role = null): User
    {
        $user = User::factory()->create(['is_active' => true]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function makeBoard(User $owner): Board
    {
        return Board::create([
            'user_id' => $owner->id,
            'name' => 'Support',
            'slug' => 'support-'.$owner->id,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function authPayload(Board $board): array
    {
        return [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-boards.'.$board->id,
        ];
    }

    /** The broadcast auth endpoint is registered without a route name. */
    private function endpoint(): string
    {
        return '/broadcasting/auth';
    }

    public function test_a_user_with_tasks_view_may_authorize_a_board_channel(): void
    {
        $user = $this->makeUser('agent');
        $board = $this->makeBoard($user);

        $this->actingAs($user)
            ->postJson($this->endpoint(), $this->authPayload($board))
            ->assertOk();
    }

    public function test_a_manager_may_authorize_a_board_channel(): void
    {
        $user = $this->makeUser('manager');
        $board = $this->makeBoard($user);

        $this->actingAs($user)
            ->postJson($this->endpoint(), $this->authPayload($board))
            ->assertOk();
    }

    public function test_a_user_without_tasks_view_is_refused_a_board_channel(): void
    {
        $user = $this->makeUser();
        $board = $this->makeBoard($user);

        $this->actingAs($user)
            ->postJson($this->endpoint(), $this->authPayload($board))
            ->assertForbidden();
    }

    public function test_a_guest_is_refused_a_board_channel(): void
    {
        $board = $this->makeBoard($this->makeUser());

        $this->postJson($this->endpoint(), $this->authPayload($board))
            ->assertForbidden();
    }
}
