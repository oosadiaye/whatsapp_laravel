<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | AI Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Controls which LLM provider is used for AI features (drafting replies,
    | generating content, summarising calls). The active provider is selected
    | via the Settings page (superadmin) and stored encrypted in the DB.
    | This config provides defaults and feature flags.
    |
    | Precedence for provider/key/model: Settings (DB, encrypted) → env fallback.
    */

    'provider' => env('AI_PROVIDER', 'gemini'),

    /*
    | Model defaults per tier. Keys match the tier names used in AiConfig::model().
    | Override per env if needed; the Settings page can also set a specific model.
    */
    'models' => [
        'draft'     => env('AI_MODEL_DRAFT', 'gemini-2.0-flash'),
        'classify'  => env('AI_MODEL_CLASSIFY', 'gemini-2.0-flash'),
        'summarise' => env('AI_MODEL_SUMMARISE', 'gemini-2.0-flash'),
    ],

    'openrouter' => [
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'referer'  => env('OPENROUTER_REFERER', 'https://blastiq.app'),
        'title'    => env('OPENROUTER_TITLE', 'BlastIQ'),
    ],

    'gemini' => [
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

    /*
    | Request timeout (seconds) for all providers.
    */
    'timeout' => env('AI_TIMEOUT', 60),

    /*
    | Default max output tokens per request (can be overridden per call).
    */
    'max_output_tokens' => env('AI_MAX_OUTPUT_TOKENS', 2048),

    /*
    | Per-feature flags. ALL default to FALSE (off by default).
    | The Settings page toggles these on/off for superadmins.
    */
    'features' => [
        'whatsapp_suggest'  => env('AI_FEATURE_WHATSAPP_SUGGEST', false),
        'email_draft'       => env('AI_FEATURE_EMAIL_DRAFT', false),
        'campaign_content'  => env('AI_FEATURE_CAMPAIGN_CONTENT', false),
        'call_followup'     => env('AI_FEATURE_CALL_FOLLOWUP', false),
    ],

    /*
    | Cost control caps (USD). 0 = no cap.
    | Tracked via AuditService per AI call.
    */
    'cost_caps' => [
        'daily_per_user'  => env('AI_COST_DAILY_PER_USER', 0),
        'monthly_per_org' => env('AI_COST_MONTHLY_PER_ORG', 0),
    ],

];