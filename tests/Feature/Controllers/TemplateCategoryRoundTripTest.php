<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TemplateCategoryRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_resync_preserves_the_local_category(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin'); // has templates.sync

        $instance = WhatsAppInstance::factory()->create([
            'waba_id' => 'w1', 'phone_number_id' => 'p1', 'access_token' => 'tok', 'app_secret' => 'sec',
        ]);

        // A 'reminder' template already submitted to Meta (Meta stored it as UTILITY).
        $template = MessageTemplate::create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
            'whatsapp_template_id' => 't1',
            'name' => 'appt_reminder',
            'language' => 'en_US',
            'category' => 'reminder',
            'status' => MessageTemplate::STATUS_APPROVED,
            'content' => 'Reminder body',
        ]);

        // Meta returns it as UTILITY on sync.
        Http::fake(['graph.facebook.com/*' => Http::response([
            'data' => [[
                'id' => 't1', 'name' => 'appt_reminder', 'language' => 'en_US',
                'category' => 'UTILITY', 'status' => 'APPROVED',
                'components' => [['type' => 'BODY', 'text' => 'Reminder body']],
            ]],
        ], 200)]);

        $this->actingAs($admin)
            ->post(route('templates.sync'), ['whatsapp_instance_id' => $instance->id])
            ->assertRedirect();

        // Category must NOT have drifted UTILITY -> transactional.
        $this->assertSame('reminder', $template->fresh()->category);
    }
}
