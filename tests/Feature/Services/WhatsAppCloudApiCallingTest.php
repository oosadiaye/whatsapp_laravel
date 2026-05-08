<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\WhatsAppApiException;
use App\Models\WhatsAppInstance;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppCloudApiCallingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pre_accept_call_posts_correct_payload_with_no_sdp(): void
    {
        $instance = WhatsAppInstance::factory()->create([
            'phone_number_id' => '123456789',
            'access_token' => 'EAAtest',
        ]);
        $service = $this->app->make(WhatsAppCloudApiService::class);

        Http::fake([
            '*/123456789/calls' => Http::response(['success' => true], 200),
        ]);

        $service->preAcceptCall($instance, 'wacid.abc123');

        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($request->url(), '123456789/calls')
                && $body['action'] === 'pre_accept'
                && $body['call_id'] === 'wacid.abc123'
                && $body['messaging_product'] === 'whatsapp'
                && !isset($body['session']);
        });
    }

    public function test_pre_accept_call_posts_with_sdp_when_provided(): void
    {
        $instance = WhatsAppInstance::factory()->create();
        $service = $this->app->make(WhatsAppCloudApiService::class);

        Http::fake(['*' => Http::response([], 200)]);

        $service->preAcceptCall($instance, 'wacid.abc', "v=0\r\no=...");

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $body['action'] === 'pre_accept'
                && $body['session']['sdp_type'] === 'answer'
                && $body['session']['sdp'] === "v=0\r\no=...";
        });
    }

    public function test_pre_accept_call_does_not_throw_on_4xx(): void
    {
        $instance = WhatsAppInstance::factory()->create();
        $service = $this->app->make(WhatsAppCloudApiService::class);

        Http::fake(['*' => Http::response(['error' => 'bad'], 400)]);

        // Pre-accept is OPTIONAL — failure logs warning but does NOT throw.
        // Call should still ring on the agent's screen even without pre-accept.
        $service->preAcceptCall($instance, 'wacid.abc');

        $this->assertTrue(true, 'preAcceptCall did not throw on 4xx — correct behavior');
    }

    public function test_accept_call_posts_sdp_answer_correctly(): void
    {
        $instance = WhatsAppInstance::factory()->create([
            'phone_number_id' => '999',
        ]);
        $service = $this->app->make(WhatsAppCloudApiService::class);

        Http::fake(['*' => Http::response([], 200)]);

        $service->acceptCall($instance, 'wacid.xyz', 'sdp-answer-blob');

        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($request->url(), '999/calls')
                && $body['action'] === 'accept'
                && $body['call_id'] === 'wacid.xyz'
                && $body['session']['sdp_type'] === 'answer'
                && $body['session']['sdp'] === 'sdp-answer-blob';
        });
    }

    public function test_accept_call_throws_on_4xx(): void
    {
        $instance = WhatsAppInstance::factory()->create();
        $service = $this->app->make(WhatsAppCloudApiService::class);

        Http::fake(['*' => Http::response(['error' => 'bad'], 400)]);

        $this->expectException(WhatsAppApiException::class);
        $service->acceptCall($instance, 'wacid.abc', 'sdp');
    }
}
