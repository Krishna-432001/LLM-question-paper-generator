<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/**
 * Model registry with pricing. Routing lives here rather than in code, so
 * switching a task from the premium tier to the mid tier — the single biggest
 * AI cost lever — is a database update.
 */
class AiModel extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'supports_structured_output' => 'boolean',
            'input_price_per_mtok' => 'float',
            'output_price_per_mtok' => 'float',
            'cached_input_price_per_mtok' => 'float',
        ];
    }

    public static function byKey(string $modelKey): ?self
    {
        return static::query()->where('model_key', $modelKey)->first();
    }

    /** Cost in INR for a single call. Prices are stored per million tokens. */
    public function costInr(int $inputTokens, int $outputTokens, int $cachedInputTokens = 0): float
    {
        $rate = fn (?float $perMtok, int $tokens): float => (($perMtok ?? 0.0) * $tokens) / 1_000_000;

        return round(
            $rate($this->input_price_per_mtok, $inputTokens)
            + $rate($this->output_price_per_mtok, $outputTokens)
            + $rate($this->cached_input_price_per_mtok, $cachedInputTokens),
            6
        );
    }
}
