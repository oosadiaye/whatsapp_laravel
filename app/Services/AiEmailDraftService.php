<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Log;

/**
 * Drafts email content via the provider-agnostic AiClient.
 * Keeps the same public API (returns ?string) so existing callers/tests keep working.
 */
class AiEmailDraftService
{
    public function __construct(
        private readonly AiClient $ai,
    ) {}

    public function draft(array $params): ?string
    {
        if (! $this->ai instanceof \App\Services\Ai\AiClient) {
            return null;
        }

        $prompt = $this->buildPrompt($params);

        try {
            $result = $this->ai->chat(
                messages: [
                    ['role' => 'user', 'content' => $prompt],
                ],
                opts: [
                    'tier' => 'draft',
                    'temperature' => 0.7,
                    'max_tokens' => 1024,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('AiEmailDraftService exception', ['error' => $e->getMessage()]);
            return null;
        }

        if ($result === null) {
            return null;
        }

        return trim($result->text);
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