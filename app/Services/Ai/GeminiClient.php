<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Support\AiConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gemini AI client implementation (adapts the existing Gemini generateContent call).
 *
 * Implements the same AiClient interface so callers (AiEmailDraftService, etc.)
 * don't need to know which provider is active.
 * Returns null on ANY failure — never throws.
 */
final class GeminiClient implements AiClient
{
    public function chat(array $messages, array $opts = []): ?AiResult
    {
        $key = AiConfig::key();
        if ($key === null) {
            Log::debug('GeminiClient: no API key configured');
            return null;
        }

        // Convert OpenAI-style messages to Gemini's contents format.
        // We only support system + user messages; assistant messages are folded into the last user message.
        $contents = $this->convertMessages($messages);
        if ($contents === []) {
            return null;
        }

        $model = $opts['model'] ?? AiConfig::model($opts['tier'] ?? 'draft');
        $maxTokens = $opts['max_tokens'] ?? AiConfig::maxOutputTokens();
        $temperature = $opts['temperature'] ?? 0.7;

        $baseUrl = rtrim(AiConfig::geminiBaseUrl(), '/');
        $url = "{$baseUrl}/models/{$model}:generateContent";

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $key])
                ->timeout(AiConfig::timeout())
                ->acceptJson()
                ->post($url, [
                    'contents' => $contents,
                    'generationConfig' => [
                        'temperature' => $temperature,
                        'maxOutputTokens' => $maxTokens,
                    ],
                ]);
        } catch (Throwable $e) {
            Log::warning('Gemini transport failure', ['error' => $e->getMessage()]);
            return null;
        }

        if (! $response->successful()) {
            Log::warning('Gemini request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        $body = $response->json();
        $candidate = $body['candidates'][0] ?? null;

        if (! $candidate) {
            Log::warning('Gemini response missing candidates', ['body' => $body]);
            return null;
        }

        $parts = $candidate['content']['parts'] ?? [];
        $text = $parts[0]['text'] ?? null;

        if ($text === null) {
            Log::warning('Gemini response missing text content', ['candidate' => $candidate]);
            return null;
        }

        $usage = $body['usageMetadata'] ?? [];
        $promptTokens = (int) ($usage['promptTokenCount'] ?? 0);
        $completionTokens = (int) ($usage['candidatesTokenCount'] ?? 0);

        // Gemini doesn't return cost directly.
        $costUsd = null;

        return new AiResult(
            text: trim((string) $text),
            model: $model,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            costUsd: $costUsd,
        );
    }

    /**
     * Convert OpenAI-style messages to Gemini's contents format.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<int, array{role: string, parts: array<int, array{text: string}>}>
     */
    private function convertMessages(array $messages): array
    {
        $contents = [];
        $systemPrompt = '';

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';

            if ($role === 'system') {
                $systemPrompt .= ($systemPrompt !== '' ? "\n\n" : '').$content;
                continue;
            }

            $geminiRole = $role === 'assistant' ? 'model' : 'user';
            $text = $systemPrompt !== '' ? $systemPrompt."\n\n".$content : $content;
            $systemPrompt = ''; // Only prepend to the first user message

            $contents[] = [
                'role' => $geminiRole,
                'parts' => [['text' => $text]],
            ];
        }

        return $contents;
    }
}