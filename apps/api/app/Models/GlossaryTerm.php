<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GlossaryTerm extends Model
{
    use HasUuidV7;

    protected $table = 'term_glossary';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeForPair(Builder $query, string $sourceLanguageId, string $targetLanguageId): Builder
    {
        return $query->where('source_language_id', $sourceLanguageId)
            ->where('target_language_id', $targetLanguageId)
            ->where('is_active', true);
    }
}
