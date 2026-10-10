<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Resolves the active AI provider, API key, and model from a single place.
 *
 * Precedence (highest to lowest):
 *   1. Settings page value (DB, encrypted at rest via Setting::getEncrypted/setEncrypted)
 *   2. Environment variable / config/ai.php defaults
 *
 * Mirrors the pattern in {@see GeminiConfig} so the whole AI pipeline agrees
 * on "is AI configured?" and which provider/model to use.
 */
final class AiConfig
{
    /**
     * Get the active AI provider ('gemini' | 'openrouter').
     */
    public static function provider(): string
    {
        try {
            $fromSettings = Setting::getEncrypted('ai_provider');
            if (filled($fromSettings) && in_array($fromSettings, ['gemini', 'openrouter'], true)) {
                return $fromSettings;
            }
        } catch (QueryException) {
            // Settings table not available (e.g., isolated unit test) — fall through.
        }

        return (string) config('ai.provider', 'gemini');
    }

    /**
     * Get the API key for the active provider.
     */
    public static function key(): ?string
    {
        $provider = self::provider();

        try {
            $settingKey = $provider === 'openrouter' ? 'openrouter_api_key' : 'gemini_api_key';
            $fromSettings = Setting::getEncrypted($settingKey);
            if (filled($fromSettings)) {
                return $fromSettings;
            }
        } catch (QueryException) {
            // Fall through to env.
        }

        $envKey = $provider === 'openrouter'
            ? config('services.openrouter.key')
            : config('services.gemini.key');

        return $envKey !== '' ? $envKey : null;
    }

    /**
     * Get the model for a given tier ('draft' | 'classify' | 'summarise').
     *
     * Precedence: Settings (ai_model_<tier>) → config/ai.php models.<tier> → tier default.
     */
    public static function model(string $tier = 'draft'): string
    {
        $validTiers = ['draft', 'classify', 'summarise'];
        if (! in_array($tier, $validTiers, true)) {
            $tier = 'draft';
        }

        try {
            $settingKey = 'ai_model_'.$tier;
            $fromSettings = Setting::getEncrypted($settingKey);
            if (filled($fromSettings)) {
                return $fromSettings;
            }
        } catch (QueryException) {
            // Fall through.
        }

        return (string) config("ai.models.{$tier}", config('ai.models.draft'));
    }

    /**
     * Get the request timeout in seconds.
     */
    public static function timeout(): int
    {
        return (int) config('ai.timeout', 60);
    }

    /**
     * Get the default max output tokens.
     */
    public static function maxOutputTokens(): int
    {
        return (int) config('ai.max_output_tokens', 2048);
    }

    /**
     * Check if a feature flag is enabled.
     */
    public static function featureEnabled(string $feature): bool
    {
        try {
            $settingKey = 'ai_feature_'.$feature;
            $fromSettings = Setting::get($settingKey);
            if ($fromSettings !== null) {
                return (bool) $fromSettings;
            }
        } catch (QueryException) {
            // Fall through to config.
        }

        return (bool) config("ai.features.{$feature}", false);
    }

    /**
     * Get all feature flags as a keyed array.
     */
    public static function features(): array
    {
        $features = config('ai.features', []);
        $result = [];

        foreach ($features as $key => $default) {
            try {
                $settingKey = 'ai_feature_'.$key;
                $fromSettings = Setting::get($settingKey);
                $result[$key] = $fromSettings !== null ? (bool) $fromSettings : (bool) $default;
            } catch (QueryException) {
                $result[$key] = (bool) $default;
            }
        }

        return $result;
    }

    /**
     * Check if the active provider has a usable key configured.
     */
    public static function isConfigured(): bool
    {
        return filled(self::key());
    }

    /**
     * Get the OpenRouter base URL.
     */
    public static function openRouterBaseUrl(): string
    {
        return (string) config('ai.openrouter.base_url', 'https://openrouter.ai/api/v1');
    }

    /**
     * Get the OpenRouter referer header.
     */
    public static function openRouterReferer(): string
    {
        return (string) config('ai.openrouter.referer', 'https://blastiq.app');
    }

    /**
     * Get the OpenRouter title header.
     */
    public static function openRouterTitle(): string
    {
        return (string) config('ai.openrouter.title', 'BlastIQ');
    }

    /**
     * Get the Gemini base URL.
     */
    public static function geminiBaseUrl(): string
    {
        return (string) config('ai.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');
    }

    /**
     * Get the display name for the active provider.
     */
    public static function providerDisplayName(): string
    {
        return self::provider() === 'openrouter' ? 'OpenRouter' : 'Google Gemini';
    }

    /**
     * Get a curated list of models for the active provider.
     * This is a static fallback; the Settings page can also live-fetch from OpenRouter.
     */
    public static function curatedModels(): array
    {
        return match (self::provider()) {
            'openrouter' => [
                'anthropic/claude-3.5-sonnet'          => 'Claude 3.5 Sonnet (Anthropic)',
                'anthropic/claude-3.5-haiku'           => 'Claude 3.5 Haiku (Anthropic)',
                'openai/gpt-4o'                        => 'GPT-4o (OpenAI)',
                'openai/gpt-4o-mini'                   => 'GPT-4o Mini (OpenAI)',
                'google/gemini-2.0-flash-001'          => 'Gemini 2.0 Flash (Google)',
                'google/gemini-2.0-flash-lite-001'     => 'Gemini 2.0 Flash-Lite (Google)',
                'meta-llama/llama-3.1-405b-instruct'   => 'Llama 3.1 405B (Meta)',
                'meta-llama/llama-3.1-70b-instruct'    => 'Llama 3.1 70B (Meta)',
                'mistralai/mistral-large'              => 'Mistral Large (Mistral)',
            ],
            'gemini' => [
                'gemini-2.0-flash'       => 'Gemini 2.0 Flash',
                'gemini-2.0-flash-lite'  => 'Gemini 2.0 Flash-Lite',
                'gemini-1.5-pro'         => 'Gemini 1.5 Pro',
                'gemini-1.5-flash'       => 'Gemini 1.5 Flash',
            ],
            default => [],
        };
    }
}