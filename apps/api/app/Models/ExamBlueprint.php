<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The real exam pattern, expressed as data. Adding SSC CGL means inserting a
 * blueprint row — no code change.
 */
class ExamBlueprint extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'difficulty_mix' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function examPaper(): BelongsTo
    {
        return $this->belongsTo(ExamPaper::class);
    }

    public function syllabusVersion(): BelongsTo
    {
        return $this->belongsTo(SyllabusVersion::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(BlueprintSection::class, 'blueprint_id')->orderBy('sequence');
    }
}
