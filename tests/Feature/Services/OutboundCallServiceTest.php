<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppInstance;
use App\Services\OutboundCallService;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboundCallServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_initiate_creates_call_log_with_meta_id_and_user_attribution(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'calls' => [['id' => 'wacid.outbound_test']],
        ], 200)]);

        $user = User::factory()->create();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $user->id]);
        $contact = Contact::factory()->create(['user_id' => $user->id, 'phone' => '2348011122233']);
        $conv = Conversation::factory()->create([
            'user_id' => $user->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
        ]);

        $service = new OutboundCallService($this->app->make(WhatsAppCloudApiService::class));

        $callLog = $service->initiate($conv, $user);

        $this->assertNotNull($callLog->id);
        $this->assertSame('outbound', $callLog->direction);
        $this->assertSame('initiated', $callLog->status);
        $this->assertSame('wacid.outbound_test', $callLog->meta_call_id);
        $this->assertSame($user->id, $callLog->placed_by_user_id);
        $this->assertSame($conv->id, $callLog->conversation_id);
        $this->assertSame($contact->phone, $callLog->to_phone);
    }

    public function test_end_marks_call_log_as_ended_optimistically(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

        $callLog = CallLog::factory()->inFlight()->create([
            'meta_call_id' => 'wacid.live',
        ]);

        $service = new OutboundCallService($this->app->make(WhatsAppCloudApiService::class));
        $service->end($callLog);

        $callLog->refresh();
        $this->assertSame('ended', $callLog->status);
        $this->assertNotNull($callLog->ended_at);
    }
}
