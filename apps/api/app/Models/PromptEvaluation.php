<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The gate that turns prompt engineering into a measurable process: a version
 * cannot be promoted to active until it beats the incumbent on the golden set.
 */
class PromptEvaluation extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'passed' => 'boolean',
            'evaluated_at' => 'datetime',
        ];
    }

    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(PromptVersion::class);
    }
}
