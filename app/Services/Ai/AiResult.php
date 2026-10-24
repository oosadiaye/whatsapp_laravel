<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Result of an AI chat completion call.
 */
final readonly class AiResult
{
    /**
     * @param  string  $text           The generated text content.
     * @param  string  $model          The model that generated the response.
     * @param  int     $promptTokens   Number of tokens in the prompt.
     * @param  int     $completionTokens Number of tokens in the completion.
     * @param  float|null $costUsd     Estimated cost in USD (nullable if provider doesn't return usage).
     */
    public function __construct(
        public string $text,
        public string $model,
        public int $promptTokens,
        public int $completionTokens,
        public ?float $costUsd = null,
    ) {}

    /**
     * Total tokens used.
     */
    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}