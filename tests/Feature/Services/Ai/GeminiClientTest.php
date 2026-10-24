<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Ai;

use App\Services\Ai\GeminiClient;
use App\Services\Ai\AiResult;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pins the GeminiClient contract: chat() returns AiResult on success,
 * null on any failure (graceful degradation), never throws.
 * Adapts the existing Gemini generateContent call to the AiClient interface.
 */
class GeminiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('ai.provider', 'gemini');
        Config::set('ai.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');
        Config::set('ai.timeout', 30);
        Config::set('ai.max_output_tokens', 1024);
        Config::set('ai.models.draft', 'gemini-2.0-flash');
        Config::set('services.gemini.key', 'test-gemini-key');
    }

    public function test_chat_returns_ai_result_on_success(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => 'Hello, world!',
                        ]],
                    ],
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 10,
                    'candidatesTokenCount' => 5,
                ],
            ], 200),
        ]);

        $client = new GeminiClient();
        $result = $client->chat([
            ['role' => 'user', 'content' => 'Say hello'],
        ]);

        $this->assertInstanceOf(AiResult::class, $result);
        $this->assertSame('Hello, world!', $result->text);
        $this->assertSame('gemini-2.0-flash', $result->model);
        $this->assertSame(10, $result->promptTokens);
        $this->assertSame(5, $result->completionTokens);
        $this->assertNull($result->costUsd);
    }

    public function test_chat_converts_system_message_to_first_user_message(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'OK']]],
                ]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ], 200),
        ]);

        $client = new GeminiClient();
        $client->chat([
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $contents = $data['contents'];
            // First user message should contain system prompt
            return str_contains($contents[0]['parts'][0]['text'], 'You are helpful.')
                && str_contains($contents[0]['parts'][0]['text'], 'Hello');
        });
    }

    public function test_chat_converts_assistant_message_to_model_role(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'OK']]],
                ]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ], 200),
        ]);

        $client = new GeminiClient();
        $client->chat([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there'],
            ['role' => 'user', 'content' => 'How are you?'],
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $contents = $data['contents'];
            // Should have 3 messages: user, model, user
            return count($contents) === 3
                && $contents[0]['role'] === 'user'
                && $contents[1]['role'] === 'model'
                && $contents[2]['role'] === 'user';
        });
    }

    public function test_chat_uses_custom_model_when_provided(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'OK']]],
                ]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ], 200),
        ]);

        $client = new GeminiClient();
        $client->chat(
            messages: [['role' => 'user', 'content' => 'test']],
            opts: ['model' => 'gemini-1.5-pro'],
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'models/gemini-1.5-pro:generateContent');
        });
    }

    public function test_chat_uses_custom_temperature_and_max_tokens(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'OK']]],
                ]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ], 200),
        ]);

        $client = new GeminiClient();
        $client->chat(
            messages: [['role' => 'user', 'content' => 'test']],
            opts: ['temperature' => 0.5, 'max_tokens' => 500],
        );

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $data['generationConfig']['temperature'] === 0.5
                && $data['generationConfig']['maxOutputTokens'] === 500;
        });
    }

    public function test_chat_sends_api_key_in_header_not_query(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'OK']]],
                ]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ], 200),
        ]);

        $client = new GeminiClient();
        $client->chat([['role' => 'user', 'content' => 'test']]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-goog-api-key', 'test-gemini-key')
                && ! str_contains($request->url(), 'test-gemini-key');
        });
    }

    public function test_chat_returns_null_on_non_2xx_response(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => 'Quota exceeded',
            ], 429),
        ]);

        $client = new GeminiClient();
        $result = $client->chat([['role' => 'user', 'content' => 'test']]);

        $this->assertNull($result);
    }

    public function test_chat_returns_null_on_transport_exception(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });

        $client = new GeminiClient();
        $result = $client->chat([['role' => 'user', 'content' => 'test']]);

        $this->assertNull($result);
    }

    public function test_chat_returns_null_when_candidates_missing(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [],
            ], 200),
        ]);

        $client = new GeminiClient();
        $result = $client->chat([['role' => 'user', 'content' => 'test']]);

        $this->assertNull($result);
    }

    public function test_chat_returns_null_when_text_missing(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => []],
                ]],
            ], 200),
        ]);

        $client = new GeminiClient();
        $result = $client->chat([['role' => 'user', 'content' => 'test']]);

        $this->assertNull($result);
    }

    public function test_chat_returns_null_when_no_api_key(): void
    {
        Config::set('services.gemini.key', '');

        $client = new GeminiClient();
        $result = $client->chat([['role' => 'user', 'content' => 'test']]);

        $this->assertNull($result);
    }
}