<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Board duplication (TASK-BOARD.md 3.5).
 *
 * The thing worth asserting is the negative: a duplicate is a *template*, not a
 * fork. Copying the cards would drag assignees, watchers, comments and activity
 * along silently, so the source keeps every card and the copy starts empty. The
 * other load-bearing bit is the slug — two concurrent duplicates must not collide,
 * which the unique index enforces and the controller handles by regenerating.
 */
class BoardDuplicateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(?string $role = 'super_admin'): User
    {
        $u = User::factory()->create(['is_active' => true]);

        if ($role !== null) {
            $u->assignRole($role);
        }

        return $u;
    }

    private function board(User $owner, string $name = 'Support'): Board
    {
        return Board::create([
            'user_id' => $owner->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(),
        ]);
    }

    public function test_duplicating_a_board_copies_name_description_and_slug_stem(): void
    {
        $this->actingAs($this->user());
        $board = $this->board($this->user(), 'Support');

        $this->post(route('boards.duplicate', $board))
            ->assertRedirect(route('boards.show', Board::where('name', 'Support (copy)')->first()));

        $copy = Board::where('name', 'Support (copy)')->firstOrFail();
        $this->assertSame('Support (copy)', $copy->name);
        $this->assertStringStartsWith('support-', $copy->slug);
        $this->assertNotSame($board->slug, $copy->slug);
    }

    public function test_a_duplicate_starts_empty_but_the_source_keeps_its_cards(): void
    {
        $owner = $this->user();
        $this->actingAs($owner);
        $board = $this->board($owner, 'Support');

        $card = Task::create([
            'user_id' => $owner->id,
            'board_id' => $board->id,
            'title' => 'Call the customer',
            'status' => 'todo',
            'position' => 1,
        ]);

        $this->post(route('boards.duplicate', $board));

        $copy = Board::where('name', 'Support (copy)')->firstOrFail();

        // The template starts empty; the fork would have cloned 400 rows of
        // relationships the duplicator never asked for.
        $this->assertDatabaseHas('boards', ['id' => $copy->id]);
        $this->assertSame(0, $copy->tasks()->count());
        $this->assertSame('Call the customer', $card->fresh()->title);
        $this->assertSame(1, $board->tasks()->count());
    }

    public function test_duplicate_generates_a_unique_slug_on_collision(): void
    {
        $owner = $this->user();
        $this->actingAs($owner);

        // A board with a deterministic slug, so we can predict the stem the
        // duplicator will reach for.
        $board = Board::create([
            'user_id' => $owner->id,
            'name' => 'Support',
            'slug' => 'support-team',
        ]);

        // Seed the exact slug the duplicator would reach for first.
        Board::create([
            'user_id' => $owner->id,
            'name' => 'Support (copy)',
            'slug' => 'support-team-copy',
        ]);

        $this->post(route('boards.duplicate', $board));

        $copy = Board::where('name', 'Support (copy)')->latest('id')->firstOrFail();
        $this->assertSame('support-team-copy-2', $copy->slug);
    }

    public function test_an_agent_may_duplicate_but_a_viewer_may_not(): void
    {
        $owner = $this->user();
        $board = $this->board($owner, 'Support');

        $this->actingAs($this->user('agent'));
        $this->post(route('boards.duplicate', $board))->assertRedirect();

        $this->actingAs($this->user(null)); // viewer, no role
        $this->post(route('boards.duplicate', $board))->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $board = $this->board($this->user(), 'Support');

        $this->post(route('boards.duplicate', $board))->assertRedirect(route('login'));
    }
}
