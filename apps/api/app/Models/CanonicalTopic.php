<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The cross-exam taxonomy. The same real-world topic appears in many syllabi
 * under different names; mapping them all onto one canonical node is what lets
 * a newly added exam inherit the entire existing question bank at zero
 * generation cost.
 */
class CanonicalTopic extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['aliases' => 'array'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function examTopics(): HasMany
    {
        return $this->hasMany(ExamTopic::class);
    }

    public function scopeUnderPath(Builder $query, string $path): Builder
    {
        return $query->whereRaw('path <@ ?::ltree', [$path]);
    }

    public function scopeInDomain(Builder $query, string $domain): Builder
    {
        return $query->where('domain', $domain);
    }
}
