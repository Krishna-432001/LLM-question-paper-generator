<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per model call. This is the record that makes every generated
 * question traceable to the exact prompt version, model and cost that
 * produced it — and makes AI spend attributable per tenant.
 */
class AiInvocation extends Model
{
    use HasUuidV7;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'request_snapshot' => 'array',
            'cache_hit' => 'boolean',
            'degraded' => 'boolean',
            'cost_inr' => 'decimal:6',
        ];
    }

    public function aiJob(): BelongsTo
    {
        return $this->belongsTo(AiJob::class);
    }

    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(PromptVersion::class);
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }
}
