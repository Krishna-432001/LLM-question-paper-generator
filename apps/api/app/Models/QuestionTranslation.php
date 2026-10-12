<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionTranslation extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_master' => 'boolean',
            'quality_score' => 'float',
            'validation_report' => 'array',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereIn('translation_status', ['AI_VALIDATED', 'HUMAN_APPROVED']);
    }

    /** @return array<string, string|null> */
    public function optionMap(): array
    {
        return array_filter([
            'A' => $this->option_a,
            'B' => $this->option_b,
            'C' => $this->option_c,
            'D' => $this->option_d,
            'E' => $this->option_e,
        ], static fn (?string $v): bool => $v !== null && $v !== '');
    }

    /**
     * Normalized form used for exact-duplicate hashing: case, punctuation and
     * whitespace differences are not meaningful differences between questions.
     */
    public function normalizedStem(): string
    {
        $text = mb_strtolower(trim((string) $this->question_text));
        $text = preg_replace('/[^\p{L}\p{Nd}\s]/u', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
