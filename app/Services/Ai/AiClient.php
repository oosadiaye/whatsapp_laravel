<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Provider-agnostic AI chat client interface.
 *
 * All implementations MUST:
 * - Return null on ANY failure (network, auth, rate limit, parse error) — never throw.
 * - Log warnings server-side only; the caller treats null as "no suggestion".
 * - Accept OpenAI-style messages: [{role: 'system'|'user'|'assistant', content: string}, ...]
 */
interface AiClient
{
    /**
     * Send a chat completion request.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $opts  Optional overrides: 'model', 'max_tokens', 'temperature', 'tier'
     * @return AiResult|null  The result, or null on any failure (graceful degradation).
     */
    public function chat(array $messages, array $opts = []): ?AiResult;
}