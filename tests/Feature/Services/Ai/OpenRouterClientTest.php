<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Ai;

use App\Services\Ai\OpenRouterClient;
use App\Services\Ai\AiResult;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Pins the OpenRouterClient contract: chat() returns AiResult on success,
 * null on any failure (graceful degradation), never throws.
 */
class OpenRouterClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('ai.provider', 'openrouter');
        Config::set('ai.openrouter.base_url', 'https://openrouter.ai/api/v1');
        Config::set('ai.openrouter.referer', 'https://test.app');
        Config::set('ai.openrouter.title', 'TestApp');
        // Off here so the older tests can pin exact max_tokens; the headroom tests turn it on.
        Config::set('ai.openrouter.reasoning_headroom', 0);
        Config::set('ai.timeout', 30);
        Config::set('ai.max_output_tokens', 1024);
        Config::set('ai.models.draft', 'openai/gpt-4o-mini');
        Config::set('services.openrouter.key', 'test-openrouter-key');
    }

    public function test_chat_returns_ai_result_on_success(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Hello, world!',
                    ],
                ]],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 5,
                ],
            ], 200),
        ]);

        $client = new OpenRouterClient();
        $result = $client->chat([
            ['role' => 'user', 'content' => 'Say hello'],
        ]);

        $this->assertInstanceOf(AiResult::class, $result);
        $this->assertSame('Hello, world!', $result->text);
        $this->assertSame('openai/gpt-4o-mini', $result->model);
        $this->assertSame(10, $result->promptTokens);
        $this->assertSame(5, $result->completionTokens);
        $this->assertNull($result->costUsd);
    }

    public function test_reasoning_headroom_is_added_to_the_requested_max_tokens(): void
    {
        // Reasoning models spend hidden "thinking" tokens out of max_tokens. With a 400 token
        // budget they stopped mid-thought and returned no answer (finish_reason=length).
        Config::set('ai.openrouter.reasoning_headroom', 1600);
        Http::fake(['openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'OK']]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ], 200)]);

        (new OpenRouterClient)->chat([['role' => 'user', 'content' => 'x']], ['max_tokens' => 400]);

        Http::assertSent(fn ($request) => $request->data()['max_tokens'] === 2000);
    }

    public function test_the_headroom_also_applies_to_the_default_max_tokens(): void
    {
        Config::set('ai.openrouter.reasoning_headroom', 1000);
        Http::fake(['openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'OK']]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ], 200)]);

        (new OpenRouterClient)->chat([['role' => 'user', 'content' => 'x']]);

        Http::assertSent(fn ($request) => $request->data()['max_tokens'] === 2024); // 1024 default + 1000
    }

    public function test_the_shipped_default_headroom_is_generous_enough_for_reasoning_models(): void
    {
        $this->assertSame(1600, (int) (require base_path('config/ai.php'))['openrouter']['reasoning_headroom']);
    }

    public function test_an_answer_cut_off_by_the_token_limit_returns_null_and_logs_why(): void
    {
        Log::spy();
        Http::fake(['openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [['finish_reason' => 'length', 'message' => ['content' => '', 'reasoning' => str_repeat('thinking ', 200)]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 400],
        ], 200)]);

        $result = (new OpenRouterClient)->chat([['role' => 'user', 'content' => 'x']], ['max_tokens' => 400]);

        $this->assertNull($result);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'truncated'))->once();
    }

    public function test_chat_uses_custom_model_when_provided(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'OK'],
                ]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ], 200),
        ]);

        $client = new OpenRouterClient();
        $client->chat(
            messages: [['role' => 'user', 'content' => 'test']],
            opts: ['model' => 'anthropic/claude-3.5-sonnet'],
        );

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $data['model'] === 'anthropic/claude-3.5-sonnet';
        });
    }

    public function test_chat_uses_custom_temperature_and_max_tokens(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'OK'],
                ]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ], 200),
        ]);

        $client = new OpenRouterClient();
        $client->chat(
            messages: [['role' => 'user', 'content' => 'test']],
            opts: ['temperature' => 0.5, 'max_tokens' => 500],
        );

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $data['temperature'] === 0.5 && $data['max_tokens'] === 500;
        });
    }

    public function test_chat_sends_correct_headers(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'OK'],
                ]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ], 200),
        ]);

        $client = new OpenRouterClient();
        $client->chat([['role' => 'user', 'content' => 'test']]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-openrouter-key')
                && $request->hasHeader('HTTP-Referer', 'https://test.app')
                && $request->hasHeader('X-Title', 'TestApp')
                && $request->hasHeader('Content-Type', 'application/json');
        });
    }

    public function test_chat_returns_null_on_non_2xx_response(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'error' => 'Rate limit exceeded',
            ], 429),
        ]);

        $client = new OpenRouterClient();
        $result = $client->chat([['role' => 'user', 'content' => 'test']]);

        $this->assertNull($result);
    }

    public function test_chat_returns_null_on_transport_exception(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });

        $client = new OpenRouterClient();
        $result = $client->chat([['role' => 'user', 'content' => 'test']]);

        $this->assertNull($result);
    }

    public function test_chat_returns_null_when_choices_missing(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [],
            ], 200),
        ]);

        $client = new OpenRouterClient();
        $result = $client->chat([['role' => 'user', 'content' => 'test']]);

        $this->assertNull($result);
    }

    public function test_chat_returns_null_when_message_content_missing(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant'],
                ]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ], 200),
        ]);

        $client = new OpenRouterClient();
        $result = $client->chat([['role' => 'user', 'content' => 'test']]);

        $this->assertNull($result);
    }

    public function test_chat_returns_null_when_no_api_key(): void
    {
        Config::set('services.openrouter.key', '');

        $client = new OpenRouterClient();
        $result = $client->chat([['role' => 'user', 'content' => 'test']]);

        $this->assertNull($result);
    }
}