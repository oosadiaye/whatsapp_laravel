<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\GeminiConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiEmailDraftService
{
    public function draft(array $params): ?string
    {
        $key = GeminiConfig::key();
        if ($key === null) {
            return null;
        }

        $prompt = $this->buildPrompt($params);

        try {
            $response = Http::timeout(30)
                ->withHeader('Content-Type', 'application/json')
                ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key='.$key, [
                    'contents' => [
                        [
                            'parts' => [['text' => $prompt]],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 1024,
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Gemini email draft failed', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            $body = $response->json();
            $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if ($text === null) {
                return null;
            }

            return trim($text);
        } catch (\Throwable $e) {
            Log::warning('Gemini email draft exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function buildPrompt(array $params): string
    {
        $recipient = $params['recipient_name'] ?? 'the recipient';
        $goal = $params['goal'] ?? 'follow up';
        $tone = $params['tone'] ?? 'professional';
        $context = $params['context'] ?? '';
        $subject = $params['subject'] ?? '';

        $lines = [
            'Write a cold email in plain text. Do NOT include a subject line — only the body.',
            "Recipient: {$recipient}",
            "Goal: {$goal}",
            "Tone: {$tone}",
        ];

        if ($context !== '') {
            $lines[] = "Context: {$context}";
        }

        if ($subject !== '') {
            $lines[] = "Reference subject: {$subject}";
        }

        $lines[] = 'Do not include any greetings or sign-offs in the response — just the email body paragraph(s). Return only the body text, no JSON, no explanation.';

        return implode("\n", $lines);
    }
}
