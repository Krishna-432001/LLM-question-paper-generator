<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A question carries no text.
 *
 * This row is the language-neutral truth: which option is correct, how hard it
 * is, what it is about, where it came from. Every rendering — including the
 * master language — lives in question_translations. That symmetry is what
 * makes adding the sixth language cost nothing structural.
 */
class Question extends Model
{
    use BelongsToOrganization;
    use HasUuidV7;

    public const SHARES_PLATFORM_CONTENT = true;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quality_score' => 'float',
            'ai_confidence' => 'float',
            'difficulty_score' => 'float',
            'p_value' => 'float',
            'is_time_sensitive' => 'boolean',
            'validity_from' => 'date',
            'validity_to' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(QuestionTranslation::class);
    }

    public function master(): HasOne
    {
        return $this->hasOne(QuestionTranslation::class)->where('is_master', true);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(QuestionSource::class);
    }

    public function examMappings(): HasMany
    {
        return $this->hasMany(QuestionExamMapping::class);
    }

    public function topicMappings(): HasMany
    {
        return $this->hasMany(QuestionTopicMapping::class);
    }

    public function rejections(): HasMany
    {
        return $this->hasMany(QuestionRejection::class);
    }

    public function validations(): HasMany
    {
        return $this->hasMany(QuestionValidation::class);
    }

    public function canonicalTopic(): BelongsTo
    {
        return $this->belongsTo(CanonicalTopic::class);
    }

    public function masterLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'master_language_id');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'APPROVED');
    }

    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->whereIn('status', ['AI_VALIDATED', 'PENDING_REVIEW']);
    }

    /** Only questions that actually exist in the requested language. */
    public function scopeAvailableIn(Builder $query, string $languageId): Builder
    {
        return $query->whereHas(
            'translations',
            fn (Builder $q) => $q->where('language_id', $languageId)
                ->whereIn('translation_status', ['AI_VALIDATED', 'HUMAN_APPROVED'])
        );
    }

    public function correctOptionKey(): ?string
    {
        return $this->options->firstWhere('is_correct', true)?->option_key;
    }

    public function translationIn(string $languageId): ?QuestionTranslation
    {
        return $this->translations->firstWhere('language_id', $languageId);
    }

    /**
     * The text a student reads in a given language: a validated translation
     * if there is one, otherwise the master. Selection already restricts a
     * paper to questions available in its language, so the fallback is only
     * reached for older papers.
     */
    public function textIn(string $languageId): ?QuestionTranslation
    {
        $translation = $this->translationIn($languageId);

        if ($translation !== null && in_array($translation->translation_status, ['AI_VALIDATED', 'HUMAN_APPROVED'], true)) {
            return $translation;
        }

        return $this->translations->firstWhere('is_master', true) ?? $this->master;
    }
}
