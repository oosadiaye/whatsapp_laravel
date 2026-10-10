<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Ai;

use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\AiClient;
use App\Services\Ai\OpenRouterClient;
use App\Services\Ai\GeminiClient;
use App\Support\AiConfig;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Pins the AiProviderFactory contract: returns the correct client
 * based on the active provider in AiConfig.
 */
class AiProviderFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('ai.provider', 'gemini');
    }

    public function test_make_returns_gemini_client_when_provider_is_gemini(): void
    {
        Config::set('ai.provider', 'gemini');

        $factory = new AiProviderFactory();
        $client = $factory->make();

        $this->assertInstanceOf(AiClient::class, $client);
        $this->assertInstanceOf(GeminiClient::class, $client);
    }

    public function test_make_returns_openrouter_client_when_provider_is_openrouter(): void
    {
        Config::set('ai.provider', 'openrouter');

        $factory = new AiProviderFactory();
        $client = $factory->make();

        $this->assertInstanceOf(AiClient::class, $client);
        $this->assertInstanceOf(OpenRouterClient::class, $client);
    }

    public function test_make_returns_gemini_client_as_fallback_for_unknown_provider(): void
    {
        Config::set('ai.provider', 'unknown-provider');

        $factory = new AiProviderFactory();
        $client = $factory->make();

        $this->assertInstanceOf(GeminiClient::class, $client);
    }
}