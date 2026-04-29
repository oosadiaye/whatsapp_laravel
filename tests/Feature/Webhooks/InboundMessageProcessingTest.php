<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\WhatsAppInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Verifies inbound webhook processing creates contacts, conversations, and
 * messages correctly. The webhook controller's signature/payload-parsing
 * is already covered by CloudWebhookTest — this focuses on the new inbound
 * branch that delegates to InboundMessageProcessor.
 */
class InboundMessageProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_inbound_text_message_creates_contact_conversation_and_message(): void
    {
        $instance = WhatsAppInstance::factory()->create(['app_secret' => 'SECRET']);

        $payload = $this->messagePayload($instance->phone_number_id, [[
            'from' => '2348012345678',
            'id' => 'wamid.first_msg',
            'timestamp' => '1714000000',
            'type' => 'text',
            'text' => ['body' => 'Hello, can you help?'],
        ]], [['wa_id' => '2348012345678', 'profile' => ['name' => 'Jane Doe']]]);

        $this->postWithSignature($instance, $payload, 'SECRET')->assertOk();

        // Contact created with WhatsApp profile name
        $contact = Contact::where('phone', '2348012345678')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Jane Doe', $contact->name);
        $this->assertSame($instance->user_id, $contact->user_id);

        // Conversation created
        $conv = Conversation::where('contact_id', $contact->id)->first();
        $this->assertNotNull($conv);
        $this->assertSame($instance->id, $conv->whatsapp_instance_id);
        $this->assertSame(1, $conv->unread_count);
        $this->assertNotNull($conv->last_inbound_at);

        // Message stored
        $msg = ConversationMessage::first();
        $this->assertSame('inbound', $msg->direction);
        $this->assertSame('text', $msg->type);
        $this->assertSame('Hello, can you help?', $msg->body);
        $this->assertSame('wamid.first_msg', $msg->whatsapp_message_id);
    }

    public function test_duplicate_webhook_does_not_create_duplicate_message(): void
    {
        // Meta retries webhooks if our 200 ack is slow — same wamid arrives twice.
        $instance = WhatsAppInstance::factory()->create(['app_secret' => 'SECRET']);
        $payload = $this->messagePayload($instance->phone_number_id, [[
            'from' => '2348011111111',
            'id' => 'wamid.duplicate_test',
            'timestamp' => '1714000000',
            'type' => 'text',
            'text' => ['body' => 'hi'],
        ]]);

        $this->postWithSignature($instance, $payload, 'SECRET')->assertOk();
        $this->postWithSignature($instance, $payload, 'SECRET')->assertOk();

        $this->assertSame(1, ConversationMessage::count());
        $this->assertSame(1, Conversation::first()->unread_count); // not double-incremented
    }

    public function test_second_message_from_same_contact_reuses_conversation(): void
    {
        $instance = WhatsAppInstance::factory()->create(['app_secret' => 'SECRET']);

        // First message
        $this->postWithSignature($instance, $this->messagePayload($instance->phone_number_id, [[
            'from' => '2348012345678',
            'id' => 'wamid.msg1',
            'timestamp' => '1714000000',
            'type' => 'text',
            'text' => ['body' => 'first'],
        ]]), 'SECRET');

        // Second message
        $this->postWithSignature($instance, $this->messagePayload($instance->phone_number_id, [[
            'from' => '2348012345678',
            'id' => 'wamid.msg2',
            'timestamp' => '1714000060',
            'type' => 'text',
            'text' => ['body' => 'second'],
        ]]), 'SECRET');

        $this->assertSame(1, Conversation::count());
        $this->assertSame(2, ConversationMessage::count());
        $this->assertSame(2, Conversation::first()->unread_count);
    }

    public function test_image_message_downloads_media_and_stores_locally(): void
    {
        Storage::fake();

        $instance = WhatsAppInstance::factory()->create(['app_secret' => 'SECRET']);

        // Mock Meta's two-step media flow:
        //   1. GET /{media_id} → returns signed download URL + metadata
        //   2. GET <signed_url> → returns binary
        Http::fake([
            'graph.facebook.com/v20.0/MEDIA_ID_123' => Http::response([
                'url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?signed=...',
                'mime_type' => 'image/jpeg',
                'sha256' => 'abc',
                'file_size' => 12345,
                'id' => 'MEDIA_ID_123',
                'messaging_product' => 'whatsapp',
            ], 200),
            'lookaside.fbsbx.com/*' => Http::response('FAKE_BINARY_BYTES', 200),
        ]);

        $payload = $this->messagePayload($instance->phone_number_id, [[
            'from' => '2348012345678',
            'id' => 'wamid.image_msg',
            'timestamp' => '1714000000',
            'type' => 'image',
            'image' => ['id' => 'MEDIA_ID_123', 'caption' => 'Look at this', 'mime_type' => 'image/jpeg'],
        ]]);

        $this->postWithSignature($instance, $payload, 'SECRET')->assertOk();

        $msg = ConversationMessage::first();
        $this->assertSame('image', $msg->type);
        $this->assertSame('Look at this', $msg->body);
        $this->assertNotNull($msg->media_path);
        $this->assertSame('image/jpeg', $msg->media_mime);
        $this->assertSame(strlen('FAKE_BINARY_BYTES'), $msg->media_size_bytes);
        Storage::disk('local')->assertExists($msg->media_path);
    }

    public function test_existing_contact_is_reused_not_duplicated(): void
    {
        $instance = WhatsAppInstance::factory()->create(['app_secret' => 'SECRET']);
        $existingContact = Contact::create([
            'user_id' => $instance->user_id,
            'phone' => '2348099999999',
            'name' => 'Already Known',
        ]);

        $this->postWithSignature($instance, $this->messagePayload($instance->phone_number_id, [[
            'from' => '2348099999999',
            'id' => 'wamid.from_known_contact',
            'timestamp' => '1714000000',
            'type' => 'text',
            'text' => ['body' => 'hi'],
        ]]), 'SECRET');

        $this->assertSame(1, Contact::count());
        // Name preserved (not overwritten by phone fallback or WhatsApp profile name)
        $this->assertSame('Already Known', $existingContact->fresh()->name);
    }

    public function test_message_without_id_is_silently_skipped(): void
    {
        // Defensive: malformed Meta payloads shouldn't crash the webhook.
        $instance = WhatsAppInstance::factory()->create(['app_secret' => 'SECRET']);
        $payload = $this->messagePayload($instance->phone_number_id, [[
            'from' => '2348012345678',
            // no 'id' field
            'type' => 'text',
            'text' => ['body' => 'oops'],
        ]]);

        $this->postWithSignature($instance, $payload, 'SECRET')->assertOk();

        $this->assertSame(0, ConversationMessage::count());
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function postWithSignature(WhatsAppInstance $instance, array $payload, string $secret)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

        return $this->call(
            'POST',
            route('webhook.cloud.handle', $instance),
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $body,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $contacts
     * @return array<string, mixed>
     */
    private function messagePayload(string $phoneNumberId, array $messages, array $contacts = []): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => $phoneNumberId, 'display_phone_number' => '+12345'],
                        'contacts' => $contacts,
                        'messages' => $messages,
                    ],
                ]],
            ]],
        ];
    }
}
