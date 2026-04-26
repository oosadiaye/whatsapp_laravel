<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\Campaign;
use App\Models\MessageLog;
use App\Models\WhatsAppInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Webhook handler tests cover the security boundary (verify-token handshake +
 * HMAC signature validation) and the payload parser (status mapping, log
 * updates, campaign counter increments).
 *
 * The HMAC test is the most important one — without that signature check,
 * anyone on the internet could POST fake delivery receipts and corrupt our
 * analytics. Every test path that mutates state must run through validation.
 */
class CloudWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_verify_returns_challenge_when_token_matches(): void
    {
        $instance = WhatsAppInstance::factory()->cloud()->create([
            'webhook_verify_token' => 'EXPECTED_TOKEN',
        ]);

        $response = $this->get(route('webhook.cloud.verify', [
            'instance' => $instance->id,
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'EXPECTED_TOKEN',
            'hub_challenge' => 'CHALLENGE_42',
        ]));

        $response->assertOk();
        $this->assertSame('CHALLENGE_42', $response->getContent());
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
    }

    public function test_get_verify_rejects_mismatched_token(): void
    {
        $instance = WhatsAppInstance::factory()->cloud()->create([
            'webhook_verify_token' => 'EXPECTED_TOKEN',
        ]);

        $this->get(route('webhook.cloud.verify', [
            'instance' => $instance->id,
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'WRONG_TOKEN',
            'hub_challenge' => 'CHALLENGE_42',
        ]))->assertForbidden();
    }

    public function test_get_verify_rejects_non_subscribe_mode(): void
    {
        $instance = WhatsAppInstance::factory()->cloud()->create([
            'webhook_verify_token' => 'TOKEN',
        ]);

        $this->get(route('webhook.cloud.verify', [
            'instance' => $instance->id,
            'hub_mode' => 'unsubscribe',
            'hub_verify_token' => 'TOKEN',
            'hub_challenge' => 'X',
        ]))->assertForbidden();
    }

    public function test_post_without_signature_header_is_rejected(): void
    {
        $instance = WhatsAppInstance::factory()->cloud()->create();

        $this->postJson(route('webhook.cloud.handle', $instance), [
            'entry' => [],
        ])->assertForbidden();
    }

    public function test_post_with_invalid_signature_is_rejected(): void
    {
        $instance = WhatsAppInstance::factory()->cloud()->create([
            'app_secret' => 'CORRECT_SECRET',
        ]);

        $payload = ['entry' => [['changes' => []]]];

        $this->postJson(
            route('webhook.cloud.handle', $instance),
            $payload,
            ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'WRONG_SECRET')],
        )->assertForbidden();
    }

    public function test_post_with_valid_signature_marks_message_delivered(): void
    {
        [$instance, $log] = $this->seedInstanceWithLog('CORRECT_SECRET');

        $payload = $this->statusPayload($log->whatsapp_message_id, 'delivered');

        $this->postWithSignature($instance, $payload, 'CORRECT_SECRET')->assertOk();

        $log->refresh();
        $this->assertSame('DELIVERED', $log->status);
        $this->assertNotNull($log->delivered_at);
        $this->assertSame(1, $log->campaign->fresh()->delivered_count);
    }

    public function test_read_status_increments_read_counter_and_records_timestamp(): void
    {
        [$instance, $log] = $this->seedInstanceWithLog('SECRET');

        $payload = $this->statusPayload($log->whatsapp_message_id, 'read', timestamp: 1700000000);

        $this->postWithSignature($instance, $payload, 'SECRET')->assertOk();

        $log->refresh();
        $this->assertSame('READ', $log->status);
        $this->assertSame('2023-11-14 22:13:20', $log->read_at?->toDateTimeString());
        $this->assertSame(1, $log->campaign->fresh()->read_count);
    }

    public function test_failed_status_records_error_message_from_first_error(): void
    {
        [$instance, $log] = $this->seedInstanceWithLog('SECRET');

        $payload = $this->statusPayload($log->whatsapp_message_id, 'failed', errors: [
            ['code' => 131_026, 'title' => 'Receiver is incapable', 'message' => 'Receiver number not on WhatsApp'],
        ]);

        $this->postWithSignature($instance, $payload, 'SECRET')->assertOk();

        $log->refresh();
        $this->assertSame('FAILED', $log->status);
        $this->assertStringContainsString('not on WhatsApp', $log->error_message);
        $this->assertSame(1, $log->campaign->fresh()->failed_count);
    }

    public function test_unknown_message_id_is_silently_dropped(): void
    {
        $instance = WhatsAppInstance::factory()->cloud()->create(['app_secret' => 'S']);

        // Status for a message_id that doesn't exist in our DB — typical for
        // inbound replies or messages sent through other means.
        $payload = $this->statusPayload('wamid.unknown_id', 'delivered');

        $this->postWithSignature($instance, $payload, 'S')->assertOk();

        // No exception, no DB record created. Just acknowledged.
        $this->assertSame(0, MessageLog::count());
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function postWithSignature(WhatsAppInstance $instance, array $payload, string $secret)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

        return $this->call(
            'POST',
            route('webhook.cloud.handle', $instance),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $body,
        );
    }

    private function statusPayload(string $messageId, string $status, ?int $timestamp = null, array $errors = []): array
    {
        $statusBlock = [
            'id' => $messageId,
            'status' => $status,
            'recipient_id' => '2348012345678',
        ];

        if ($timestamp !== null) {
            $statusBlock['timestamp'] = (string) $timestamp;
        }

        if ($errors !== []) {
            $statusBlock['errors'] = $errors;
        }

        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => 'PHONE_ID', 'display_phone_number' => '+12345'],
                        'statuses' => [$statusBlock],
                    ],
                ]],
            ]],
        ];
    }

    /** @return array{0: WhatsAppInstance, 1: MessageLog} */
    private function seedInstanceWithLog(string $secret): array
    {
        $instance = WhatsAppInstance::factory()->cloud()->create(['app_secret' => $secret]);

        $campaign = Campaign::factory()->create([
            'user_id' => $instance->user_id,
            'instance_id' => $instance->id,
        ]);

        $log = MessageLog::create([
            'campaign_id' => $campaign->id,
            'phone' => '2348012345678',
            'status' => 'SENT',
            'whatsapp_message_id' => 'wamid.HBg=',
            'sent_at' => now(),
        ]);

        return [$instance, $log];
    }
}
