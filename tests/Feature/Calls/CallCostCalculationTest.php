<?php

declare(strict_types=1);

namespace Tests\Feature\Calls;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Verifies the integer-kobo cost math from
 * AfricasTalkingWebhookController::finalizeCall via end-to-end webhook
 * post. Math: ceil(duration_seconds * rate_per_minute_kobo / 60).
 */
class CallCostCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Setting::set('africastalking_username', 'sandbox');
        Setting::set('africastalking_api_key', Crypt::encryptString('atsk_test'));
        Setting::set('africastalking_virtual_number', '+2348100000000');
        Setting::set('africastalking_rate_per_minute_kobo', '600');  // ₦6/min
    }

    private function postWebhook(array $payload)
    {
        // No at_webhook_secret configured here → the webhook accepts the bare
        // path (auth relies on the IP allowlist / rate limit in that mode).
        return $this->post(route('webhook.africastalking.voice'), $payload);
    }

    public function test_ninety_seconds_at_six_naira_per_minute_costs_900_kobo(): void
    {
        $call = $this->makeCall('sess_90s');

        $this->postWebhook([
            'sessionId' => 'sess_90s',
            'status' => 'Completed',
            'direction' => 'Outbound',
            'durationInSeconds' => '90',
        ])->assertOk();

        $fresh = $call->fresh();
        $this->assertSame(90, $fresh->duration_seconds);
        $this->assertSame(900, $fresh->cost_estimate_kobo);  // 90s * 600 / 60 = 900
    }

    public function test_zero_duration_costs_zero_kobo(): void
    {
        $call = $this->makeCall('sess_0s');

        $this->postWebhook([
            'sessionId' => 'sess_0s',
            'status' => 'Completed',
            'direction' => 'Outbound',
            'durationInSeconds' => '0',
        ])->assertOk();

        $this->assertSame(0, $call->fresh()->cost_estimate_kobo);
    }

    private function makeCall(string $sessionId): CallLog
    {
        $owner = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $owner->assignRole(User::ROLE_ADMIN);
        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'is_active' => true, 'last_seen_at' => now()]);
        $agent->assignRole(User::ROLE_AGENT);
        $instance = WhatsAppInstance::factory()->create(['user_id' => $owner->id]);
        $contact = Contact::factory()->create(['user_id' => $owner->id, 'phone' => '23480'.fake()->unique()->numerify('########')]);
        $conversation = Conversation::create([
            'user_id' => $owner->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $agent->id,
            'unread_count' => 0,
        ]);

        return CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'outbound',
            'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
            'provider_session_id' => $sessionId,
            'status' => CallLog::STATUS_CONNECTED,
            'started_at' => now()->subMinutes(2),
            'placed_by_user_id' => $agent->id,
            'from_phone' => '+2348100000000',
            'to_phone' => $contact->phone,
        ]);
    }
}
