<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable once it leaves draft. Jobs pin the version they started with, so a
 * mid-flight prompt release never mixes behaviour within one book — and any
 * question can be traced to the exact prompt that produced it, forever.
 */
class PromptVersion extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'output_schema' => 'array',
            'params' => 'array',
            'cache_segments' => 'array',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PromptTemplate::class, 'prompt_template_id');
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(PromptEvaluation::class);
    }

    public function isMutable(): bool
    {
        return $this->status === 'draft';
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return data_get($this->params, $key, $default);
    }
}
