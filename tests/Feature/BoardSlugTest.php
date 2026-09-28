<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Board;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The boards slug race (TASK-BOARD.md 3.6).
 *
 * Validation catches the duplicate in the normal path, but two POSTs can pass
 * validation concurrently before either writes. The unique index on
 * (user_id, slug) is the source of truth, and the controller turns a DB
 * rejection back into a slug error instead of a 500. These tests assert both
 * layers: the database actually refuses the second row, and the scoped rule
 * lets a different user reuse a slug.
 */
class BoardSlugTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role = 'super_admin'): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole($role);

        return $u;
    }

    public function test_the_database_refuses_a_duplicate_slug_for_the_same_owner(): void
    {
        $owner = $this->user();

        Board::create([
            'user_id' => $owner->id,
            'name' => 'Support',
            'slug' => 'support',
        ]);

        // Bypass the request validation on purpose: this is the safety net that
        // must hold when validation and the write race.
        $this->expectException(QueryException::class);

        Board::create([
            'user_id' => $owner->id,
            'name' => 'Support again',
            'slug' => 'support',
        ]);
    }

    public function test_different_owners_may_share_a_slug(): void
    {
        $a = $this->user();
        $b = $this->user();

        Board::create(['user_id' => $a->id, 'name' => 'Support', 'slug' => 'support']);

        $second = Board::create(['user_id' => $b->id, 'name' => 'Support', 'slug' => 'support']);

        $this->assertDatabaseHas('boards', ['id' => $second->id, 'slug' => 'support']);
    }

    public function test_store_validation_rejects_a_duplicate_slug_for_the_same_owner(): void
    {
        $owner = $this->user();
        $this->actingAs($owner);

        Board::create(['user_id' => $owner->id, 'name' => 'Support', 'slug' => 'support']);

        $other = $this->user();
        Board::create(['user_id' => $other->id, 'name' => 'Other', 'slug' => 'other']);

        $this->post(route('boards.store'), [
            'name' => 'Support copy',
            'slug' => 'support',
        ])->assertInvalid('slug');

        // Only the owner's slug is reserved; another user's is free to take.
        $this->post(route('boards.store'), [
            'name' => 'Borrowed key',
            'slug' => 'other',
        ])->assertRedirect();
    }

    public function test_update_validation_rejects_renaming_onto_another_own_board_slug(): void
    {
        $owner = $this->user();
        $this->actingAs($owner);

        $first = Board::create(['user_id' => $owner->id, 'name' => 'First', 'slug' => 'first']);
        $second = Board::create(['user_id' => $owner->id, 'name' => 'Second', 'slug' => 'second']);

        $this->put(route('boards.update', $second), [
            'name' => 'Second',
            'slug' => 'first',
        ])->assertInvalid('slug');

        // Renaming onto itself is fine.
        $this->put(route('boards.update', $second), [
            'name' => 'Second',
            'slug' => 'second',
        ])->assertRedirect();
    }

    public function test_a_duplicate_slug_is_surfaced_as_a_validation_error_not_a_crash(): void
    {
        $owner = $this->user();
        $this->actingAs($owner);

        // Same owner already holds this slug.
        Board::create(['user_id' => $owner->id, 'name' => 'Support', 'slug' => 'support']);

        // Validation is the normal gate; the point asserted here is that the
        // user gets a field error pointing at the slug rather than a 500.
        $this->post(route('boards.store'), [
            'name' => 'Support 2',
            'slug' => 'support',
        ])->assertInvalid('slug');

        $this->assertDatabaseMissing('boards', ['slug' => 'support-second']);
    }
}
