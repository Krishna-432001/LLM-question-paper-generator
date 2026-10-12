<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Self-referencing tree with an ltree path. Real syllabi vary in depth, so
 * fixed unit/topic/subtopic tables would not fit them.
 */
class ExamTopic extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_leaf' => 'boolean',
            'weight' => 'float',
        ];
    }

    public function syllabusVersion(): BelongsTo
    {
        return $this->belongsTo(SyllabusVersion::class);
    }

    public function examUnit(): BelongsTo
    {
        return $this->belongsTo(ExamUnit::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sequence');
    }

    public function canonicalTopic(): BelongsTo
    {
        return $this->belongsTo(CanonicalTopic::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ExamTopicTranslation::class);
    }

    /** All descendants, using the ltree index rather than recursive queries. */
    public function scopeUnderPath(Builder $query, string $path): Builder
    {
        return $query->whereRaw('path <@ ?::ltree', [$path]);
    }

    public function scopeLeaves(Builder $query): Builder
    {
        return $query->where('is_leaf', true);
    }
}
