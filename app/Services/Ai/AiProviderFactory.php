<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Support\AiConfig;

/**
 * Factory that returns the correct AiClient implementation based on the
 * active provider configured in AiConfig.
 */
final class AiProviderFactory
{
    public function make(): AiClient
    {
        return match (AiConfig::provider()) {
            'openrouter' => new OpenRouterClient(),
            'gemini'     => new GeminiClient(),
            default      => new GeminiClient(), // Safe fallback
        };
    }
}