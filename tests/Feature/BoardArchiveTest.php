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
 * Board archiving: out of circulation, not gone.
 *
 * The load-bearing assertion in this file is that archiving does not cascade.
 * Deleting a board soft-deletes every card on it and nothing in the UI can undo
 * that, so "hide the board" and "remove the board and its contents" have to be
 * different operations with different consequences.
 */
class BoardArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeUser(?string $role = 'super_admin'): User
    {
        $user = User::factory()->create(['is_active' => true]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function makeBoard(User $owner, string $name = 'Support'): Board
    {
        return Board::create([
            'user_id' => $owner->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(),
        ]);
    }

    private function makeTask(User $owner, Board $board, string $title = 'A card'): Task
    {
        // A unique position within the column: the schema forbids two cards
        // sharing a (board, status, position).
        $position = (int) Task::where('board_id', $board->id)->where('status', 'todo')->max('position') + 1;

        return Task::create([
            'user_id' => $owner->id,
            'board_id' => $board->id,
            'title' => $title,
            'status' => 'todo',
            'position' => $position,
        ]);
    }

    public function test_archiving_hides_the_board_from_the_list(): void
    {
        $this->actingAs($this->makeUser());
        $board = $this->makeBoard($this->makeUser(), 'Support');

        $this->post(route('boards.archive', $board))->assertRedirect(route('boards.index'));

        $this->assertNotNull($board->fresh()->archived_at);
        $this->get(route('boards.index'))->assertDontSee($board->name);
    }

    public function test_archiving_keeps_every_card_and_the_board_itself(): void
    {
        $this->actingAs($this->makeUser());
        $owner = $this->makeUser();
        $board = $this->makeBoard($owner);

        $task = $this->makeTask($owner, $board, 'Call the customer back');

        $this->post(route('boards.archive', $board));

        // Nothing is deleted, nothing is soft-deleted. This is the whole point:
        // an archive that cascaded would have to be undone card by card, and
        // anything missed stays lost.
        $this->assertDatabaseHas('boards', ['id' => $board->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'deleted_at' => null]);
        $this->assertSame('Call the customer back', $task->fresh()->title);
    }

    public function test_an_archived_board_is_still_reachable_by_url(): void
    {
        $this->actingAs($this->makeUser());
        $owner = $this->makeUser();
        $board = $this->makeBoard($owner, 'Retired work');
        $this->makeTask($owner, $board, 'Old but intact');

        $this->post(route('boards.archive', $board));

        // A stale bookmark should land somewhere that explains itself, not
        // somewhere that silently bounces.
        $this->get(route('boards.show', $board))
            ->assertOk()
            ->assertSee('Retired work')
            ->assertSee('Archived')
            ->assertSee('Old but intact');
    }

    public function test_archived_boards_are_listed_on_request(): void
    {
        $this->actingAs($this->makeUser());
        $board = $this->makeBoard($this->makeUser(), 'Retired work');

        $this->post(route('boards.archive', $board));

        // Archived is hidden, not deleted: one query away.
        $this->get(route('boards.index', ['archived' => 1]))
            ->assertOk()
            ->assertSee('Retired work');
    }

    public function test_an_archived_board_never_becomes_the_default_board(): void
    {
        $this->actingAs($this->makeUser());
        $owner = $this->makeUser();

        $first = $this->makeBoard($owner, 'First');
        $second = $this->makeBoard($owner, 'Second');

        $this->post(route('boards.archive', $first));

        // Without the live() filter, archiving the oldest board would make /tasks
        // drop everyone onto an archived board.
        $this->get(route('tasks.index'))
            ->assertOk()
            ->assertSee('Second')
            ->assertDontSee('First');
    }

    public function test_an_archived_board_is_not_in_the_board_switcher(): void
    {
        $owner = $this->makeUser();
        $this->actingAs($owner);

        $first = $this->makeBoard($owner, 'First');
        $second = $this->makeBoard($owner, 'Second');

        $this->post(route('boards.archive', $first));

        // Asserted on the tab's own wire:key rather than the board name, which
        // legitimately appears in the page header and the archived banner. The
        // point is that the switcher does not offer a jump into retired work.
        $this->get(route('boards.show', $first))
            ->assertOk()
            ->assertDontSeeHtml('board-tab-'.$first->id)
            ->assertSeeHtml('board-tab-'.$second->id);
    }

    public function test_restoring_a_board_brings_it_back_intact(): void
    {
        $this->actingAs($this->makeUser());
        $owner = $this->makeUser();
        $board = $this->makeBoard($owner, 'Retired work');
        $task = $this->makeTask($owner, $board, 'Old but intact');

        $this->post(route('boards.archive', $board));
        $this->post(route('boards.unarchive', $board))->assertRedirect(route('boards.show', $board));

        $this->assertNull($board->fresh()->archived_at);

        // Total by construction: archiving never touched the cards, so restoring
        // never has to bring anything back.
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'deleted_at' => null]);
        $this->get(route('boards.index'))->assertSee('Retired work');
    }

    public function test_archiving_is_idempotent(): void
    {
        $this->actingAs($this->makeUser());
        $board = $this->makeBoard($this->makeUser());

        $this->post(route('boards.archive', $board));
        $first = $board->fresh()->archived_at;

        $this->travel(5)->minutes();
        $this->post(route('boards.archive', $board));

        // A stale link re-clicked should not move the timestamp, or "archived
        // 3 March" stops meaning anything.
        $this->assertEquals($first, $board->fresh()->archived_at);
    }

    public function test_unarchiving_a_live_board_is_a_no_op(): void
    {
        $this->actingAs($this->makeUser());
        $board = $this->makeBoard($this->makeUser());

        $this->post(route('boards.unarchive', $board))->assertRedirect(route('boards.show', $board));

        $this->assertNull($board->fresh()->archived_at);
    }

    public function test_an_agent_may_not_archive(): void
    {
        $agent = $this->makeUser('agent');
        $this->actingAs($agent);
        $board = $this->makeBoard($this->makeUser());

        $this->post(route('boards.archive', $board))->assertForbidden();

        $this->assertNull($board->fresh()->archived_at);
    }

    public function test_a_viewer_may_not_archive(): void
    {
        $viewer = $this->makeUser(null);
        $this->actingAs($viewer);
        $board = $this->makeBoard($this->makeUser());

        $this->post(route('boards.archive', $board))->assertForbidden();

        $this->assertNull($board->fresh()->archived_at);
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $board = $this->makeBoard($this->makeUser());

        $this->post(route('boards.archive', $board))->assertRedirect(route('login'));
    }

    public function test_deleting_a_board_still_cascades(): void
    {
        $this->actingAs($this->makeUser());
        $owner = $this->makeUser();
        $board = $this->makeBoard($owner);
        $task = $this->makeTask($owner, $board);

        $this->delete(route('boards.destroy', $board))->assertRedirect(route('boards.index'));

        // Archive and delete are genuinely different operations, and this is the
        // one that loses the cards.
        $this->assertSoftDeleted('boards', ['id' => $board->id]);
        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }

    public function test_a_deleted_board_does_not_resurface_as_the_default_board(): void
    {
        $this->actingAs($this->makeUser());
        $owner = $this->makeUser();

        $first = $this->makeBoard($owner, 'First');
        $this->makeBoard($owner, 'Second');

        $this->delete(route('boards.destroy', $first));

        $this->get(route('tasks.index'))->assertOk()->assertSee('Second');
    }

    public function test_an_archived_board_is_excluded_from_archived_listings(): void
    {
        $this->actingAs($this->makeUser());
        $board = $this->makeBoard($this->makeUser(), 'Live work');

        // The two lists partition, so the "N archived" count cannot double-count
        // a board that is merely deleted-and-not-archived, or a restored one.
        $this->get(route('boards.index', ['archived' => 1]))
            ->assertOk()
            ->assertDontSee('Live work');
    }
}
