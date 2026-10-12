<?php

declare(strict_types=1);

return [

    /*
    | fake   — deterministic, offline, zero cost. Default everywhere except prod.
    | openai — the real provider.
    | claude — kept working; switching back is one env var.
    */
    'driver' => env('AI_DRIVER', 'fake'),

    'fake_latency_ms' => (int) env('AI_FAKE_LATENCY_MS', 0),
    'fixture_path' => base_path('tests/Fixtures/ai'),

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),

            /*
            | Prompt versions name a model in this platform's own registry,
            | which is provider-agnostic. This maps those names onto OpenAI
            | ids so the same prompt runs against either provider without the
            | prompt table being rewritten.
            */
            'model_map' => [
                'claude-opus-5' => env('OPENAI_MODEL_PREMIUM', 'gpt-4o'),
                'claude-sonnet-5' => env('OPENAI_MODEL_MID', 'gpt-4o'),
                'claude-haiku-4-5-20251001' => env('OPENAI_MODEL_CHEAP', 'gpt-4o-mini'),
            ],

            'default_model' => env('OPENAI_MODEL', 'gpt-4o'),
        ],

        'claude' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'version' => '2023-06-01',
        ],
    ],

    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 120),
    'max_retries' => (int) env('AI_MAX_RETRIES', 3),

    /*
    | Task tiering is the single biggest cost lever: never use a frontier model
    | for mechanical work. The authoritative routing lives in the ai_models
    | table (prompt_versions.ai_model_id); these are the bootstrap defaults
    | used by seeders.
    */
    'default_models' => [
        'premium' => 'claude-opus-5',
        'mid' => 'claude-sonnet-5',
        'cheap' => 'claude-haiku-4-5-20251001',
    ],

    /*
    | Fallback chain when the primary tier is unavailable. Degrading the tier
    | is recorded on the invocation so quality regressions can be traced back
    | to a provider incident.
    */
    'fallback_tier' => [
        'premium' => 'mid',
        'mid' => 'cheap',
        'cheap' => null,
    ],

    'cache_enabled' => (bool) env('AI_CACHE_ENABLED', true),
    'cache_ttl_days' => 90,

    // Prompt/response snapshots are retained 30 days for debugging, then pruned.
    'store_snapshots' => (bool) env('AI_STORE_SNAPSHOTS', true),
    'snapshot_retention_days' => 30,

    // Platform-wide circuit breaker. 0 disables it (not recommended in prod).
    'daily_budget_inr' => (float) env('AI_DAILY_BUDGET_INR', 2000),

    // Provider prices are quoted in USD; the registry stores INR.
    'inr_per_usd' => (float) env('AI_INR_PER_USD', 88.0),

];
