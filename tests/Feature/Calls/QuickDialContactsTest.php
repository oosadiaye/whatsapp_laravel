<?php

declare(strict_types=1);

namespace Tests\Feature\Calls;

use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Quick Dial picker no longer ships contacts inline on every workspace load;
 * they are fetched lazily from route('calls.contacts') on modal open. These pin
 * the endpoint (auth gate + payload shape + the bound limit).
 */
class QuickDialContactsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function dialerWithInstance(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin'); // admin has calls.dial
        WhatsAppInstance::factory()->create(['user_id' => $user->id, 'is_default' => true]);

        return $user;
    }

    public function test_contacts_are_returned_for_a_user_with_calls_dial(): void
    {
        $user = $this->dialerWithInstance();
        Contact::factory()->count(3)->create(['phone' => fn () => '080'.fake()->numerify('#######')]);

        $response = $this->actingAs($user)->getJson(route('calls.contacts'));

        $response->assertOk();
        $this->assertCount(3, $response->json());
        $response->assertJsonStructure([['id', 'name', 'phone']]);
    }

    public function test_contacts_are_empty_for_a_user_without_calls_dial(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        WhatsAppInstance::factory()->create(['user_id' => $user->id, 'is_default' => true]);
        Contact::factory()->count(2)->create();

        // The modal is only shown to users with calls.dial, so a user without it
        // is denied rather than handed contacts.
        $this->actingAs($user)->getJson(route('calls.contacts'))
            ->assertForbidden();
    }

    public function test_contacts_are_bounded_to_a_reasonable_limit(): void
    {
        $user = $this->dialerWithInstance();
        Contact::factory()->count(600)->create(['phone' => fn () => '080'.fake()->numerify('#######')]);

        $this->actingAs($user)->getJson(route('calls.contacts'))
            ->assertOk()
            ->assertJsonCount(500);
    }
}
