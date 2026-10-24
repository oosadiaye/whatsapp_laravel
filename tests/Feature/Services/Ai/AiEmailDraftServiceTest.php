<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Ai;

use App\Services\Ai\AiClient;
use App\Services\Ai\AiResult;
use App\Services\AiEmailDraftService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pins the AiEmailDraftService contract: draft() returns a string
 * via the provider-agnostic AiClient, or null on failure.
 */
class AiEmailDraftServiceTest extends TestCase
{
    public function test_draft_returns_text_via_ai_client(): void
    {
        // Mock the AiClient binding
        $mockClient = new class implements AiClient {
            public function chat(array $messages, array $opts = []): ?AiResult
            {
                return new AiResult(
                    text: 'Draft email body here.',
                    model: 'test-model',
                    promptTokens: 10,
                    completionTokens: 20,
                    costUsd: 0.001,
                );
            }
        };

        $this->app->instance(AiClient::class, $mockClient);

        $service = app(AiEmailDraftService::class);
        $result = $service->draft([
            'recipient_name' => 'John Doe',
            'goal' => 'follow up',
            'tone' => 'professional',
            'context' => 'Previous conversation about pricing.',
            'subject' => 'Re: Pricing',
        ]);

        $this->assertSame('Draft email body here.', $result);
    }

    public function test_draft_returns_null_when_ai_client_returns_null(): void
    {
        $mockClient = new class implements AiClient {
            public function chat(array $messages, array $opts = []): ?AiResult
            {
                return null;
            }
        };

        $this->app->instance(AiClient::class, $mockClient);

        $service = app(AiEmailDraftService::class);
        $result = $service->draft(['recipient_name' => 'John']);

        $this->assertNull($result);
    }

    public function test_draft_builds_correct_prompt(): void
    {
        $capturedPrompt = null;

        $mockClient = new class($capturedPrompt) implements AiClient {
            public function __construct(public mixed &$capturedPrompt) {}

            public function chat(array $messages, array $opts = []): ?AiResult
            {
                $this->capturedPrompt = $messages[0]['content'] ?? '';
                return new AiResult(
                    text: 'Draft body',
                    model: 'test-model',
                    promptTokens: 10,
                    completionTokens: 20,
                );
            }
        };

        $this->app->instance(AiClient::class, $mockClient);

        $service = app(AiEmailDraftService::class);
        $service->draft([
            'recipient_name' => 'Jane Smith',
            'goal' => 'schedule demo',
            'tone' => 'friendly',
            'context' => 'Met at conference.',
            'subject' => 'Demo request',
        ]);

        $this->assertStringContainsString('Jane Smith', $capturedPrompt);
        $this->assertStringContainsString('schedule demo', $capturedPrompt);
        $this->assertStringContainsString('friendly', $capturedPrompt);
        $this->assertStringContainsString('Met at conference.', $capturedPrompt);
        $this->assertStringContainsString('Demo request', $capturedPrompt);
        $this->assertStringContainsString('Do not include any greetings or sign-offs', $capturedPrompt);
    }

    public function test_draft_uses_defaults_when_params_missing(): void
    {
        $capturedPrompt = null;

        $mockClient = new class($capturedPrompt) implements AiClient {
            public function __construct(public mixed &$capturedPrompt) {}

            public function chat(array $messages, array $opts = []): ?AiResult
            {
                $this->capturedPrompt = $messages[0]['content'] ?? '';
                return new AiResult(
                    text: 'Draft body',
                    model: 'test-model',
                    promptTokens: 10,
                    completionTokens: 20,
                );
            }
        };

        $this->app->instance(AiClient::class, $mockClient);

        $service = app(AiEmailDraftService::class);
        $service->draft([]);

        $this->assertStringContainsString('the recipient', $capturedPrompt);
        $this->assertStringContainsString('follow up', $capturedPrompt);
        $this->assertStringContainsString('professional', $capturedPrompt);
    }

    public function test_draft_returns_null_on_exception(): void
    {
        $mockClient = new class implements AiClient {
            public function chat(array $messages, array $opts = []): ?AiResult
            {
                throw new \Exception('AI service down');
            }
        };

        $this->app->instance(AiClient::class, $mockClient);

        $service = app(AiEmailDraftService::class);
        $result = $service->draft(['recipient_name' => 'John']);

        $this->assertNull($result);
    }
}