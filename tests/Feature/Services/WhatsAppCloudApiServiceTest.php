<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\WhatsAppApiException;
use App\Models\User;
use App\Models\WhatsAppInstance;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifies the Cloud API HTTP wrapper:
 *  - URLs target graph.facebook.com with the pinned API version
 *  - Bearer token comes from the instance's encrypted access_token
 *  - Pagination cursor is followed
 *  - Failures raise WhatsAppApiException, not bare Throwable
 *
 * Uses Http::fake() so no real HTTP happens. Each test asserts both the
 * outbound request shape AND the parsed response.
 */
class WhatsAppCloudApiServiceTest extends TestCase
{
    use RefreshDatabase;

    private WhatsAppCloudApiService $service;
    private WhatsAppInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new WhatsAppCloudApiService();
        $this->instance = $this->makeCloudInstance();
    }

    public function test_send_text_posts_to_messages_endpoint_with_correct_payload(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '2348012345678', 'wa_id' => '2348012345678']],
                'messages' => [['id' => 'wamid.HBg=']],
            ], 200),
        ]);

        $result = $this->service->sendText($this->instance, '2348012345678', 'Hello!');

        $this->assertSame('wamid.HBg=', $result['messages'][0]['id']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/v20.0/PHONE_ID_FAKE/messages')
                && $request->header('Authorization')[0] === 'Bearer ACCESS_TOKEN_FAKE'
                && $request['type'] === 'text'
                && $request['text']['body'] === 'Hello!'
                && $request['to'] === '2348012345678';
        });
    }

    public function test_send_text_strips_non_digit_characters_from_phone(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]], 200)]);

        $this->service->sendText($this->instance, '+234 (801) 234-5678', 'Hi');

        Http::assertSent(fn (Request $r) => $r['to'] === '2348012345678');
    }

    public function test_send_template_includes_language_and_components(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.tpl']]], 200)]);

        $components = [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Jane']]],
        ];

        $this->service->sendTemplate(
            $this->instance,
            '2348012345678',
            'welcome_v1',
            'en_US',
            $components,
        );

        Http::assertSent(function (Request $request) use ($components) {
            return $request['type'] === 'template'
                && $request['template']['name'] === 'welcome_v1'
                && $request['template']['language']['code'] === 'en_US'
                && $request['template']['components'] === $components;
        });
    }

    public function test_send_media_omits_caption_for_audio_type(): void
    {
        // Cloud API rejects 'caption' on audio messages — service must not send it.
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]], 200)]);

        $this->service->sendMedia(
            $this->instance,
            '2348012345678',
            'https://cdn.example.com/clip.ogg',
            'audio',
            'this caption should be dropped',
        );

        Http::assertSent(function (Request $request) {
            return $request['type'] === 'audio'
                && $request['audio']['link'] === 'https://cdn.example.com/clip.ogg'
                && ! array_key_exists('caption', $request['audio']);
        });
    }

    public function test_fetch_templates_walks_pagination_cursor(): void
    {
        Http::fake([
            // First page returns a `paging.next` URL pointing to the second page.
            'graph.facebook.com/v20.0/WABA_FAKE/message_templates?limit=100' => Http::response([
                'data' => [
                    ['id' => 't1', 'name' => 'a', 'language' => 'en_US', 'status' => 'APPROVED', 'components' => []],
                    ['id' => 't2', 'name' => 'b', 'language' => 'en_US', 'status' => 'APPROVED', 'components' => []],
                ],
                'paging' => [
                    'next' => 'https://graph.facebook.com/v20.0/WABA_FAKE/message_templates?after=PAGE2',
                ],
            ], 200),
            'graph.facebook.com/v20.0/WABA_FAKE/message_templates?after=PAGE2' => Http::response([
                'data' => [
                    ['id' => 't3', 'name' => 'c', 'language' => 'en_US', 'status' => 'PENDING', 'components' => []],
                ],
            ], 200),
        ]);

        $templates = $this->service->fetchTemplates($this->instance);

        $this->assertCount(3, $templates);
        $this->assertSame(['a', 'b', 'c'], array_column($templates, 'name'));
    }

    public function test_fetch_templates_handles_flat_array_when_no_paging_envelope(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [['id' => 't1', 'name' => 'only', 'language' => 'en_US', 'status' => 'APPROVED', 'components' => []]],
            ], 200),
        ]);

        $this->assertCount(1, $this->service->fetchTemplates($this->instance));
    }

    public function test_create_template_uppercases_category(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'tpl_new', 'status' => 'PENDING'], 200)]);

        $this->service->createTemplate(
            $this->instance,
            'order_confirmation',
            'utility',  // lowercase from local enum
            'en_US',
            [['type' => 'BODY', 'text' => 'Order #{{1}} confirmed']],
        );

        Http::assertSent(fn (Request $r) => $r['category'] === 'UTILITY');
    }

    public function test_failure_raises_whatsapp_api_exception(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 401),
        ]);

        $this->expectException(WhatsAppApiException::class);
        $this->expectExceptionMessageMatches('/401/');

        $this->service->sendText($this->instance, '234', 'hi');
    }

    public function test_unconfigured_instance_fails_fast_before_any_http(): void
    {
        // Explicitly unset credentials to verify the guard, not network failure.
        $bare = WhatsAppInstance::factory()->create([
            'waba_id' => null,
            'phone_number_id' => null,
            'access_token' => null,
        ]);

        Http::fake();
        $this->expectException(WhatsAppApiException::class);
        $this->expectExceptionMessageMatches('/missing Cloud API credentials/');

        $this->service->sendText($bare, '234', 'hi');

        Http::assertNothingSent();
    }

    private function makeCloudInstance(): WhatsAppInstance
    {
        return WhatsAppInstance::factory()->create([
            'waba_id' => 'WABA_FAKE',
            'phone_number_id' => 'PHONE_ID_FAKE',
            'access_token' => 'ACCESS_TOKEN_FAKE',
            'app_secret' => 'APP_SECRET_FAKE',
            'webhook_verify_token' => 'VERIFY_FAKE',
        ]);
    }
}
