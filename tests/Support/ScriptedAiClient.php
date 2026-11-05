<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Ai\AiClient;
use App\Services\Ai\AiResult;

/** Returns its replies in order (null = provider failure) and null once they run out. */
final class ScriptedAiClient implements AiClient
{
    /** @var list<array<int, array{role: string, content: string}>> */
    public array $calls = [];

    private int $next = 0;

    /**
     * @param  list<?string>  $replies
     */
    public function __construct(private readonly array $replies) {}

    public function chat(array $messages, array $opts = []): ?AiResult
    {
        $this->calls[] = $messages;
        $reply = $this->replies[$this->next++] ?? null;

        return $reply === null ? null : new AiResult($reply, 'scripted', 10, 10);
    }
}
