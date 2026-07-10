<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

/**
 * TEMPORARY — data fixture for the Africa's Talking Playwright verification
 * run. Not part of the normal seed chain. Safe to delete after testing.
 */
class PlaywrightTestSeeder extends Seeder
{
    public function run(): void
    {
        // AT voice settings so the softphone token mints + the Call button is live.
        Setting::set('africastalking_username', 'sandbox');
        Setting::set('africastalking_api_key', Crypt::encryptString('atsk_playwright_key'));
        Setting::set('africastalking_virtual_number', '+2348100000000');
        Setting::set('africastalking_rate_per_minute_kobo', '600');

        /** @var User $admin */
        $admin = User::where('email', 'admin@blastiq.com')->firstOrFail();
        $admin->forceFill(['last_seen_at' => now()])->save();

        // An online agent so inbound round-robin has someone to <Dial>.
        $agent = User::firstOrCreate(
            ['email' => 'agent@blastiq.com'],
            [
                'name' => 'Agent One',
                'password' => Hash::make('password'),
                'role' => User::ROLE_AGENT,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );
        $agent->assignRole(User::ROLE_AGENT);
        $agent->forceFill(['last_seen_at' => now()])->save();

        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);

        $contact = Contact::factory()->create([
            'user_id' => $admin->id,
            'name' => 'Test Customer',
            'phone' => '2348011223344',
        ]);

        $conversation = Conversation::create([
            'user_id' => $admin->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $admin->id,
            'unread_count' => 0,
        ]);

        // A live outbound AT call placed by the admin, so the webhook
        // outbound call-control path has a session to bridge.
        CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => CallLog::DIRECTION_OUTBOUND,
            'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
            'provider_session_id' => 'pw_sess_out',
            'status' => CallLog::STATUS_RINGING,
            'started_at' => now(),
            'placed_by_user_id' => $admin->id,
            'from_phone' => '+2348100000000',
            'to_phone' => $contact->phone,
        ]);

        $this->command?->info("PW conversation_id={$conversation->id} admin_id={$admin->id} agent_id={$agent->id}");
    }
}
