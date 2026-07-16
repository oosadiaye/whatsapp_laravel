<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard for Production-Audit blocker B1.
 *
 * `contacts` combines softDeletes() with a plain unique(user_id, phone) index.
 * Eloquent's SoftDeletes global scope hides trashed rows from an ordinary
 * lookup, but the DB unique constraint is NOT scoped to deleted_at — so a plain
 * firstOrCreate/updateOrCreate/firstOrNew on a previously-deleted number would
 * miss the trashed row and then throw an uncaught QueryException on insert
 * (500ing inbound webhooks, dying mid-batch on imports). These tests pin the
 * *IncludingTrashed resolvers that revive instead of colliding.
 */
class ContactSoftDeleteResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_or_new_including_trashed_revives_a_soft_deleted_contact(): void
    {
        $user = User::factory()->create();
        $contact = Contact::create(['user_id' => $user->id, 'phone' => '2348010000000', 'name' => 'Old']);
        $contact->delete();
        $this->assertSoftDeleted('contacts', ['id' => $contact->id]);

        $resolved = Contact::firstOrNewIncludingTrashed(['user_id' => $user->id, 'phone' => '2348010000000']);

        $this->assertTrue($resolved->exists, 'should resolve the existing (trashed) row, not a new instance');
        $this->assertSame($contact->id, $resolved->id);
        $this->assertFalse($resolved->trashed());
        $this->assertNull($resolved->fresh()->deleted_at, 'the row must be restored');
    }

    public function test_first_or_create_including_trashed_does_not_collide_and_keeps_firstOrCreate_semantics(): void
    {
        $user = User::factory()->create();
        Contact::create(['user_id' => $user->id, 'phone' => '2348010000001', 'name' => 'Original'])->delete();

        // A plain Contact::firstOrCreate here would throw a QueryException on the
        // unversioned unique index. This must revive the row instead.
        $contact = Contact::firstOrCreateIncludingTrashed(
            ['user_id' => $user->id, 'phone' => '2348010000001'],
            ['name' => 'ShouldBeIgnoredOnRevive', 'is_active' => true],
        );

        $this->assertFalse($contact->trashed());
        $this->assertSame(1, Contact::where('phone', '2348010000001')->count(), 'no duplicate row');
        // firstOrCreate semantics: $values are applied only on CREATE, so the
        // revived row keeps its original name.
        $this->assertSame('Original', $contact->fresh()->name);
    }

    public function test_update_or_create_including_trashed_revives_and_updates(): void
    {
        $user = User::factory()->create();
        Contact::create(['user_id' => $user->id, 'phone' => '2348010000002', 'name' => 'Before'])->delete();

        $contact = Contact::updateOrCreateIncludingTrashed(
            ['user_id' => $user->id, 'phone' => '2348010000002'],
            ['name' => 'After'],
        );

        $this->assertFalse($contact->trashed());
        $this->assertSame(1, Contact::where('phone', '2348010000002')->count());
        // updateOrCreate semantics: $values ARE applied on the revived row.
        $this->assertSame('After', $contact->fresh()->name);
    }

    public function test_helpers_still_create_a_fresh_row_when_no_match_exists(): void
    {
        $user = User::factory()->create();

        $contact = Contact::firstOrCreateIncludingTrashed(
            ['user_id' => $user->id, 'phone' => '2348010000003'],
            ['name' => 'Brand New', 'is_active' => true],
        );

        $this->assertTrue($contact->wasRecentlyCreated);
        $this->assertSame('Brand New', $contact->name);
        $this->assertTrue($contact->is_active);
    }
}
