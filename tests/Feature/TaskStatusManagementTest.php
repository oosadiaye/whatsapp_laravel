<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskStatusManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(TaskStatusSeeder::class);
    }

    private function makeUser(?string $role = 'super_admin'): User
    {
        $user = User::factory()->create(['is_active' => true]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function makeTask(?string $status = null): Task
    {
        $owner = $this->makeUser();

        $board = Board::create([
            'user_id' => $owner->id,
            'name' => 'Board '.$owner->id,
            'slug' => 'board-'.$owner->id,
        ]);

        // A unique position within the column: the schema forbids two cards
        // sharing a (board, status, position).
        $status = $status ?? 'todo';
        $position = (int) Task::where('board_id', $board->id)->where('status', $status)->max('position') + 1;

        return Task::create([
            'user_id' => $owner->id,
            'board_id' => $board->id,
            'title' => 'A card',
            'status' => $status,
            'position' => $position,
        ]);
    }

    public function test_page_requires_the_status_manage_permission(): void
    {
        $this->actingAs($this->makeUser('agent'));

        $this->get(route('task-statuses.index'))->assertForbidden();
    }

    public function test_manager_may_manage_statuses(): void
    {
        $this->actingAs($this->makeUser('manager'));

        $this->get(route('task-statuses.index'))->assertOk();
    }

    public function test_an_administrator_can_add_a_status_at_runtime(): void
    {
        $this->actingAs($this->makeUser());

        $this->post(route('task-statuses.store'), [
            'name' => 'Blocked',
            'color' => 'red',
        ])->assertRedirect(route('task-statuses.index'));

        // No deploy, no config edit, no cache clear.
        $this->assertDatabaseHas('task_statuses', [
            'slug' => 'blocked',
            'name' => 'Blocked',
            'color' => 'red',
        ]);

        $this->assertArrayHasKey('blocked', TaskStatus::columns());
    }

    public function test_a_new_status_is_accepted_as_a_task_column(): void
    {
        $this->actingAs($this->makeUser());

        $this->post(route('task-statuses.store'), [
            'name' => 'Blocked',
            'color' => 'red',
        ])->assertRedirect(route('task-statuses.index'));

        $this->assertSame('todo', TaskStatus::defaultSlug());
        $this->assertContains('blocked', array_keys(TaskStatus::columns()));
    }

    public function test_duplicate_status_names_get_a_unique_key_instead_of_failing(): void
    {
        $this->actingAs($this->makeUser());

        $this->post(route('task-statuses.store'), ['name' => 'Review', 'color' => 'red']);
        $this->post(route('task-statuses.store'), ['name' => 'Review', 'color' => 'red']);

        $this->assertDatabaseHas('task_statuses', ['slug' => 'review']);
        $this->assertDatabaseHas('task_statuses', ['slug' => 'review-2']);
    }

    public function test_a_status_can_be_renamed_without_touching_cards(): void
    {
        $this->actingAs($this->makeUser());
        $status = TaskStatus::where('slug', 'review')->firstOrFail();

        $this->put(route('task-statuses.update', $status), [
            'name' => 'QA Review',
            'slug' => 'review',
            'color' => 'violet',
        ])->assertRedirect(route('task-statuses.index'));

        $this->assertDatabaseHas('task_statuses', [
            'id' => $status->id,
            'name' => 'QA Review',
            'color' => 'violet',
        ]);
    }

    public function test_a_key_cannot_change_while_cards_reference_it(): void
    {
        $this->actingAs($this->makeUser());
        $status = TaskStatus::where('slug', 'review')->firstOrFail();
        $this->makeTask('review');

        $this->put(route('task-statuses.update', $status), [
            'name' => 'QA Review',
            'slug' => 'qa_review',
            'color' => 'violet',
        ])->assertSessionHas('error');

        $this->assertDatabaseHas('task_statuses', ['id' => $status->id, 'slug' => 'review']);
    }

    public function test_promoting_a_default_demotes_the_previous_one(): void
    {
        $this->actingAs($this->makeUser());
        $review = TaskStatus::where('slug', 'review')->firstOrFail();

        $this->put(route('task-statuses.update', $review), [
            'name' => 'Review',
            'slug' => 'review',
            'color' => 'yellow',
            'is_default' => true,
        ])->assertRedirect(route('task-statuses.index'));

        $this->assertSame('review', TaskStatus::defaultSlug());
        $this->assertSame(
            1,
            TaskStatus::where('is_default', true)->count(),
            'Exactly one column may be the default.'
        );
    }

    public function test_the_default_status_cannot_be_deleted(): void
    {
        $this->actingAs($this->makeUser());
        $todo = TaskStatus::where('slug', 'todo')->firstOrFail();

        $this->delete(route('task-statuses.destroy', $todo))->assertSessionHas('error');

        $this->assertDatabaseHas('task_statuses', ['id' => $todo->id]);
    }

    public function test_a_status_holding_cards_cannot_be_deleted(): void
    {
        $this->actingAs($this->makeUser());
        $review = TaskStatus::where('slug', 'review')->firstOrFail();
        $this->makeTask('review');

        $this->delete(route('task-statuses.destroy', $review))->assertSessionHas('error');

        $this->assertDatabaseHas('task_statuses', ['id' => $review->id]);
    }

    public function test_an_empty_status_can_be_deleted(): void
    {
        $this->actingAs($this->makeUser());

        $this->post(route('task-statuses.store'), ['name' => 'Blocked', 'color' => 'red']);

        $blocked = TaskStatus::where('slug', 'blocked')->firstOrFail();

        $this->delete(route('task-statuses.destroy', $blocked))->assertRedirect(route('task-statuses.index'));

        $this->assertDatabaseMissing('task_statuses', ['id' => $blocked->id]);
        $this->assertArrayNotHasKey('blocked', TaskStatus::columns());
    }

    public function test_an_invalid_colour_is_rejected(): void
    {
        $this->actingAs($this->makeUser());

        $this->post(route('task-statuses.store'), [
            'name' => 'Sneaky',
            'color' => 'bg-red-500"><script>alert(1)</script>',
        ])->assertSessionHasErrors('color');

        $this->assertDatabaseMissing('task_statuses', ['name' => 'Sneaky']);
    }

    public function test_the_board_falls_back_to_config_when_the_table_is_empty(): void
    {
        // An install whose seeder has not run must still render a board rather
        // than throw on a missing column.
        TaskStatus::query()->delete();

        $columns = TaskStatus::columns();

        $this->assertArrayHasKey('todo', $columns);
        $this->assertSame('To Do', $columns['todo']['name']);
        $this->assertSame('todo', TaskStatus::defaultSlug());
    }

    public function test_the_fallback_shape_carries_a_limit_key(): void
    {
        // The board view reads $col['limit'] unconditionally, so the fallback
        // has to return the same shape as the table or an unseeded install
        // renders differently from a seeded one.
        TaskStatus::query()->delete();

        $this->assertArrayHasKey('limit', TaskStatus::columns()['todo']);
        $this->assertNull(TaskStatus::columns()['todo']['limit']);
    }

    public function test_a_limit_can_be_set_and_cleared_at_runtime(): void
    {
        $this->actingAs($this->makeUser());
        $review = TaskStatus::where('slug', 'review')->firstOrFail();

        $this->put(route('task-statuses.update', $review), [
            'name' => 'Review',
            'slug' => 'review',
            'color' => 'yellow',
            'wip_limit' => 3,
        ])->assertRedirect(route('task-statuses.index'));

        $this->assertSame(3, $review->fresh()->wip_limit);
        $this->assertSame(3, TaskStatus::columns()['review']['limit']);

        // An emptied field must clear it, not fail on "required" - otherwise
        // there is no way to back out of a limit through the UI.
        $this->put(route('task-statuses.update', $review), [
            'name' => 'Review',
            'slug' => 'review',
            'color' => 'yellow',
            'wip_limit' => '',
        ])->assertRedirect(route('task-statuses.index'));

        $this->assertNull($review->fresh()->wip_limit);
        $this->assertNull(TaskStatus::columns()['review']['limit']);
    }

    public function test_a_limit_of_zero_is_rejected(): void
    {
        // "Nothing may ever enter this column" is a typo, not a policy.
        $this->actingAs($this->makeUser());

        $this->post(route('task-statuses.store'), [
            'name' => 'Sealed',
            'color' => 'red',
            'wip_limit' => 0,
        ])->assertSessionHasErrors('wip_limit');

        $this->assertDatabaseMissing('task_statuses', ['name' => 'Sealed']);
    }

    public function test_a_limit_cannot_be_set_on_a_finished_column(): void
    {
        $this->actingAs($this->makeUser());
        $done = TaskStatus::where('slug', 'done')->firstOrFail();

        $this->put(route('task-statuses.update', $done), [
            'name' => 'Done',
            'slug' => 'done',
            'color' => 'green',
            'is_completed' => 1,
            'wip_limit' => 3,
        ])->assertSessionHas('error');

        $this->assertNull($done->fresh()->wip_limit);
        $this->assertNull(TaskStatus::columns()['done']['limit']);
    }

    public function test_flagging_a_column_finished_retires_its_limit(): void
    {
        $this->actingAs($this->makeUser());
        $review = TaskStatus::where('slug', 'review')->firstOrFail();

        $this->put(route('task-statuses.update', $review), [
            'name' => 'Review',
            'slug' => 'review',
            'color' => 'yellow',
            'wip_limit' => 3,
        ]);

        $this->assertSame(3, $review->fresh()->wip_limit);

        $this->put(route('task-statuses.update', $review), [
            'name' => 'Review',
            'slug' => 'review',
            'color' => 'yellow',
            'is_completed' => 1,
        ])->assertRedirect(route('task-statuses.index'));

        // Cleared, not left dormant: re-opening the column later should not
        // silently resurrect a ceiling somebody set months ago.
        $this->assertTrue($review->fresh()->is_completed);
        $this->assertNull($review->fresh()->wip_limit);
    }

    public function test_a_stored_limit_on_a_finished_column_is_ignored(): void
    {
        // Belt and braces for a row written before the retirement rule existed,
        // or straight to the database: a permanently red Done column would
        // train people to ignore the badge everywhere else.
        $done = TaskStatus::where('slug', 'done')->firstOrFail();
        $done->forceFill(['wip_limit' => 3])->save();

        $this->assertNull($done->fresh()->effectiveLimit());
        $this->assertNull(TaskStatus::columns()['done']['limit']);
    }
}
