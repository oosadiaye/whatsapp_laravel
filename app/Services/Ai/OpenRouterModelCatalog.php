<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Support\AiConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Browsable list of the models OpenRouter offers, for the Settings picker.
 *
 * The /models endpoint is public (no key needed) and large, so the normalised
 * result is cached. A failed fetch returns [] and is NOT cached, so the next
 * page load retries instead of showing an empty list for an hour.
 */
final class OpenRouterModelCatalog
{
    private const CACHE_KEY = 'ai.openrouter.models.v1';

    private const CACHE_SECONDS = 3600;

    /**
     * @return list<array{id: string, name: string, free: bool, vision: bool, context: int, prompt_per_million: ?float, completion_per_million: ?float}>
     */
    public function all(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $models = $this->fetch();
        if ($models !== []) {
            Cache::put(self::CACHE_KEY, $models, self::CACHE_SECONDS);
        }

        return $models;
    }

    /**
     * @return list<array{id: string, name: string, free: bool, vision: bool, context: int, prompt_per_million: ?float, completion_per_million: ?float}>
     */
    private function fetch(): array
    {
        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->get(rtrim(AiConfig::openRouterBaseUrl(), '/').'/models');
        } catch (Throwable $e) {
            Log::warning('OpenRouter model list transport failure', ['error' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('OpenRouter model list failed', ['status' => $response->status()]);

            return [];
        }

        $rows = $response->json('data');
        if (! is_array($rows)) {
            return [];
        }

        $models = [];
        foreach ($rows as $row) {
            $model = $this->normalise($row);
            if ($model !== null) {
                $models[] = $model;
            }
        }

        usort($models, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $models;
    }

    /**
     * @return array{id: string, name: string, free: bool, vision: bool, context: int, prompt_per_million: ?float, completion_per_million: ?float}|null
     */
    private function normalise(mixed $row): ?array
    {
        if (! is_array($row) || ! is_string($row['id'] ?? null) || $row['id'] === '') {
            return null;
        }

        $outputs = $row['architecture']['output_modalities'] ?? ['text'];
        if (! in_array('text', (array) $outputs, true)) {
            return null; // image/audio generators can't answer a text prompt
        }

        $inputs = (array) ($row['architecture']['input_modalities'] ?? ['text']);
        $prompt = $this->price($row['pricing']['prompt'] ?? null);
        $completion = $this->price($row['pricing']['completion'] ?? null);

        return [
            'id' => $row['id'],
            'name' => (string) ($row['name'] ?? $row['id']),
            // Negative prices mean "varies" (routers) — never advertise those as free.
            'free' => $prompt === 0.0 && $completion === 0.0,
            'vision' => in_array('image', $inputs, true),
            'context' => (int) ($row['context_length'] ?? 0),
            'prompt_per_million' => $prompt === null ? null : round($prompt * 1_000_000, 4),
            'completion_per_million' => $completion === null ? null : round($completion * 1_000_000, 4),
        ];
    }

    private function price(mixed $value): ?float
    {
        if (! is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return (float) $value;
    }
}
