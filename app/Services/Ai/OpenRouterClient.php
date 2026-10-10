<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Support\AiConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpenRouter AI client implementation.
 *
 * Calls OpenRouter's chat/completions endpoint (OpenAI-compatible).
 * Returns null on ANY failure (graceful degradation) — never throws.
 * Logs warnings server-side only.
 */
final class OpenRouterClient implements AiClient
{
    public function chat(array $messages, array $opts = []): ?AiResult
    {
        $key = AiConfig::key();
        if ($key === null) {
            Log::debug('OpenRouterClient: no API key configured');
            return null;
        }

        $model = $opts['model'] ?? AiConfig::model($opts['tier'] ?? 'draft');
        $maxTokens = $opts['max_tokens'] ?? AiConfig::maxOutputTokens();
        $temperature = $opts['temperature'] ?? 0.7;

        $url = rtrim(AiConfig::openRouterBaseUrl(), '/').'/chat/completions';

        try {
            $response = Http::timeout(AiConfig::timeout())
                ->withHeaders([
                    'Authorization' => 'Bearer '.$key,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => AiConfig::openRouterReferer(),
                    'X-Title' => AiConfig::openRouterTitle(),
                ])
                ->post($url, [
                    'model' => $model,
                    'messages' => $messages,
                    'max_tokens' => $maxTokens,
                    'temperature' => $temperature,
                ]);
        } catch (Throwable $e) {
            Log::warning('OpenRouter transport failure', ['error' => $e->getMessage()]);
            return null;
        }

        if (! $response->successful()) {
            Log::warning('OpenRouter request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        $body = $response->json();
        $choice = $body['choices'][0] ?? null;

        if (! $choice) {
            Log::warning('OpenRouter response missing choices', ['body' => $body]);
            return null;
        }

        $message = $choice['message'] ?? null;
        $text = $message['content'] ?? null;

        if ($text === null) {
            Log::warning('OpenRouter response missing message content', ['choice' => $choice]);
            return null;
        }

        $usage = $body['usage'] ?? [];
        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);

        // OpenRouter doesn't return cost directly; estimate based on model if needed.
        // For now, leave null — can be computed later from token counts + model pricing.
        $costUsd = null;

        return new AiResult(
            text: trim((string) $text),
            model: $model,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            costUsd: $costUsd,
        );
    }
}