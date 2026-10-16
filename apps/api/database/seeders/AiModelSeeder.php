<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AiModel;
use Illuminate\Database\Seeder;

final class AiModelSeeder extends Seeder
{
    /**
     * Prices are USD per million tokens, converted to INR at seed time.
     * Task-tier routing lives on prompt_versions.ai_model_id, so moving an
     * operation from the premium tier to the mid tier — the single largest
     * cost lever — is a database update.
     *
     * [provider, model_key, display, tier, context, in$/Mtok, out$/Mtok, cached_in$/Mtok]
     *
     * Both providers are registered regardless of AI_DRIVER. The cost ledger
     * resolves a row by the model id the provider actually reports, so a run
     * against OpenAI must find an OpenAI row or every invocation is costed at
     * zero and the budget guard stops guarding anything.
     */
    private const MODELS = [
        ['anthropic', 'claude-opus-5', 'Claude Opus 5', 'premium', 1_000_000, 5.00, 25.00, 0.50],
        ['anthropic', 'claude-sonnet-5', 'Claude Sonnet 5', 'mid', 1_000_000, 2.00, 10.00, 0.20],
        ['anthropic', 'claude-haiku-4-5', 'Claude Haiku 4.5', 'cheap', 200_000, 1.00, 5.00, 0.10],
        // What the tiers call by default (ai.providers.claude.model_map). The
        // ledger prices each call by the model that answered, so these rows
        // are what make a run's cost show up at all.
        ['anthropic', 'claude-opus-5-5', 'Claude Opus 5.5', 'premium', 1_000_000, 4.00, 20.00, 0.20],
        ['anthropic', 'claude-sonnet-5-5', 'Claude Sonnet 5.5', 'premium', 1_000_000, 2.00, 10.00, 0.20],
        ['anthropic', 'claude-haiku-5-5', 'Claude Haiku 5.5', 'cheap', 1_000_000, 0.10, 0.50, 0.01],

        ['openai', 'gpt-4o', 'GPT-4o', 'premium', 128_000, 2.50, 10.00, 1.25],
        ['openai', 'gpt-4o-mini', 'GPT-4o mini', 'cheap', 128_000, 0.15, 0.60, 0.075],
        ['openai', 'gpt-4.1', 'GPT-4.1', 'premium', 1_000_000, 2.00, 8.00, 0.50],
        ['openai', 'gpt-4.1-mini', 'GPT-4.1 mini', 'mid', 1_000_000, 0.40, 1.60, 0.10],
    ];

    public function run(): void
    {
        $rate = (float) config('ai.inr_per_usd', 88.0);

        foreach (self::MODELS as [$provider, $key, $name, $tier, $context, $in, $out, $cached]) {
            AiModel::query()->updateOrCreate(
                ['model_key' => $key],
                [
                    'provider' => $provider,
                    'display_name' => $name,
                    'tier' => $tier,
                    'context_window' => $context,
                    'input_price_per_mtok' => round($in * $rate, 4),
                    'output_price_per_mtok' => round($out * $rate, 4),
                    'cached_input_price_per_mtok' => round($cached * $rate, 4),
                    'supports_structured_output' => true,
                    'is_active' => true,
                ],
            );
        }

        // Local Ollama models cost nothing per token. Registered so the AI
        // history shows which local model answered each call.
        $local = array_unique(array_merge(
            [(string) config('ai.providers.ollama.default_model')],
            array_values((array) config('ai.providers.ollama.model_map')),
        ));

        foreach ($local as $tag) {
            AiModel::query()->updateOrCreate(
                ['model_key' => $tag],
                [
                    'provider' => 'ollama',
                    'display_name' => "{$tag} (local)",
                    'tier' => 'premium',
                    'context_window' => (int) config('ai.providers.ollama.context_window'),
                    'input_price_per_mtok' => 0,
                    'output_price_per_mtok' => 0,
                    'cached_input_price_per_mtok' => 0,
                    'supports_structured_output' => true,
                    'is_active' => true,
                ],
            );
        }

        // Self-hosted embeddings: recurring per-chunk cost, so we own it.
        AiModel::query()->updateOrCreate(
            ['model_key' => 'multilingual-e5-large'],
            [
                'provider' => 'self_hosted',
                'display_name' => 'Multilingual E5 Large (1024d)',
                'tier' => 'embedding',
                'context_window' => 512,
                'input_price_per_mtok' => 0,
                'output_price_per_mtok' => 0,
                'supports_structured_output' => false,
                'is_active' => true,
            ],
        );
    }
}
