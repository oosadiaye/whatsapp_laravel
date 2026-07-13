<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\User;
use App\Models\Voicemail;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoicemailInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_inbox_lists_voicemails_for_a_permitted_user(): void
    {
        $admin = $this->makeUser('admin'); // has conversations.view_all
        Voicemail::factory()->create(['from_phone' => '+2348055556666']);

        $this->actingAs($admin)
            ->get(route('voicemails.index'))
            ->assertOk()
            ->assertSee('+2348055556666');
    }

    public function test_inbox_is_gated_by_conversation_visibility(): void
    {
        $user = User::factory()->create(['is_active' => true]); // no role → no perms

        $this->actingAs($user)
            ->get(route('voicemails.index'))
            ->assertForbidden();
    }

    public function test_mark_heard_records_who_and_when(): void
    {
        $admin = $this->makeUser('admin');
        $vm = Voicemail::factory()->create(['is_heard' => false]);

        $this->actingAs($admin)
            ->post(route('voicemails.markHeard', $vm))
            ->assertRedirect();

        $vm->refresh();
        $this->assertTrue($vm->is_heard);
        $this->assertSame($admin->id, $vm->heard_by_user_id);
        $this->assertNotNull($vm->heard_at);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
