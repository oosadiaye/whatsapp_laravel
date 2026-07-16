<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactDeleteCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_contact_recomputes_group_contact_count(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $group = ContactGroup::create(['user_id' => $admin->id, 'name' => 'Leads', 'contact_count' => 0]);
        $contacts = Contact::factory()->count(3)->create(['user_id' => $admin->id]);
        $group->contacts()->attach($contacts->pluck('id'));
        $group->update(['contact_count' => 3]);

        $this->actingAs($admin)
            ->delete(route('contacts.destroy', $contacts->first()))
            ->assertRedirect();

        $this->assertSame(2, $group->fresh()->contact_count);
    }
}
