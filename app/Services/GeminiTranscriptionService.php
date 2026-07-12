<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\TranscriptionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Talks to Google Gemini's generateContent endpoint. Gemini is multimodal, so
 * a single request turns call audio into a verbatim transcript AND a short
 * summary + key points — no separate speech-to-text step.
 *
 * The service is deliberately thin and stateless: give it the audio bytes and
 * MIME type, get back a normalised {transcript, summary, key_points} array.
 * Every failure mode (missing key, non-2xx, unparseable body) surfaces as a
 * {@see TranscriptionException} so the caller can mark the call failed without
 * leaking the API key.
 */
class GeminiTranscriptionService
{
    private const PROMPT = <<<'TXT'
You are analysing the audio of a recorded customer support phone call between an agent and a customer.
1. Transcribe the conversation verbatim, labelling speakers as "Agent:" and "Customer:" where possible.
2. Write a concise 1-2 sentence summary of what the call was about and its outcome.
3. Extract 3 to 6 short key points: decisions made, requests, complaints, and any follow-up actions.
Respond ONLY with JSON matching the provided schema. If the audio is silent or unintelligible, return empty strings and an empty key_points array.
TXT;

    /**
     * @return array{transcript: string, summary: string, key_points: array<int, string>}
     *
     * @throws TranscriptionException
     */
    public function transcribeAndSummarize(string $audioContents, string $mimeType): array
    {
        $key = (string) config('services.gemini.key');
        if ($key === '') {
            throw new TranscriptionException('Gemini API key is not configured (set GEMINI_API_KEY).');
        }

        $model = (string) config('services.gemini.model', 'gemini-2.0-flash');
        $baseUrl = rtrim((string) config('services.gemini.base_url'), '/');
        // Key goes in a header, NOT the query string — so it can never appear in
        // a transport exception message, a log, or the ai_error we persist/show.
        $url = "{$baseUrl}/models/{$model}:generateContent";

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $key])
                ->timeout(120)
                ->acceptJson()
                ->post($url, $this->payload($audioContents, $mimeType));
        } catch (Throwable $e) {
            // Log the underlying cause server-side only; surface a generic message
            // so no transport detail reaches ai_error / the UI.
            Log::debug('Gemini transport failure', ['error' => $e->getMessage()]);

            throw new TranscriptionException('Could not reach Gemini.', 0, $e);
        }

        if ($response->failed()) {
            throw new TranscriptionException("Gemini returned HTTP {$response->status()}.");
        }

        return $this->parse((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $audioContents, string $mimeType): array
    {
        return [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => self::PROMPT],
                    ['inline_data' => [
                        'mime_type' => $mimeType,
                        'data' => base64_encode($audioContents),
                    ]],
                ],
            ]],
            'generationConfig' => [
                // Force machine-parseable output shaped like our return type.
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'transcript' => ['type' => 'string'],
                        'summary' => ['type' => 'string'],
                        'key_points' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['transcript', 'summary', 'key_points'],
                ],
                'temperature' => 0.2,
            ],
        ];
    }

    /**
     * @return array{transcript: string, summary: string, key_points: array<int, string>}
     *
     * @throws TranscriptionException
     */
    private function parse(string $text): array
    {
        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new TranscriptionException('Gemini response was not valid JSON.');
        }

        return [
            'transcript' => trim((string) ($decoded['transcript'] ?? '')),
            'summary' => trim((string) ($decoded['summary'] ?? '')),
            'key_points' => $this->cleanKeyPoints($decoded['key_points'] ?? []),
        ];
    }

    /**
     * Coerce whatever Gemini returned into a clean list of non-empty strings —
     * it occasionally emits blanks, whitespace, or numbers.
     *
     * @param  mixed  $points
     * @return array<int, string>
     */
    private function cleanKeyPoints($points): array
    {
        if (! is_array($points)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($p) => trim((string) (is_scalar($p) ? $p : '')), $points),
            static fn (string $p) => $p !== '',
        ));
    }
}
