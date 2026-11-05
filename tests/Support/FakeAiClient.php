<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Ai\AiClient;
use App\Services\Ai\AiResult;

/** Returns a canned reply (or null, like a failed provider) and remembers the prompt. */
final class FakeAiClient implements AiClient
{
    /** @var list<array<int, array{role: string, content: string}>> */
    public array $calls = [];

    public function __construct(private readonly ?string $reply) {}

    public function chat(array $messages, array $opts = []): ?AiResult
    {
        $this->calls[] = $messages;

        return $this->reply === null ? null : new AiResult($this->reply, 'fake-model', 10, 10);
    }
}
