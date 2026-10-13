<?php

declare(strict_types=1);

namespace App\Domain\Translation;

use App\Models\Question;
use App\Models\QuestionTranslation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Where translations are written, and the rules about what may replace what.
 *
 *   nothing yet         → version 1
 *   STALE or REJECTED   → a NEW version; the old row stays as the record of
 *                         what was wrong, or of what the master used to say
 *   MACHINE             → overwritten in place (a write that never finished)
 *   NEEDS_REVIEW        → overwritten in place only if asked to; normally a
 *                         person is about to look at it and it is left alone
 *   AI_VALIDATED,
 *   HUMAN_APPROVED      → never replaced. Editing the master marks them STALE,
 *                         and only then do they become replaceable.
 *
 * Only the newest version per (question, language) is ever live, which is
 * what Question::translationIn() reads.
 */
final class TranslationStore
{
    /** Readable by students. */
    public const USABLE = ['AI_VALIDATED', 'HUMAN_APPROVED'];

    /** Usable, or waiting for a person: not worth paying to translate again. */
    public const SETTLED = ['AI_VALIDATED', 'HUMAN_APPROVED', 'NEEDS_REVIEW'];

    /** Replaced by a fresh version rather than overwritten. */
    public const REPLACEABLE = ['STALE', 'REJECTED'];

    public function latest(string $questionId, string $languageId): ?QuestionTranslation
    {
        return QuestionTranslation::query()
            ->where('question_id', $questionId)
            ->where('language_id', $languageId)
            ->where('is_master', false)
            ->orderByDesc('translation_version')
            ->first();
    }

    /**
     * Approved questions that have no usable or pending translation in a
     * language and are not mastered in it.
     */
    public function needingTranslation(string $languageId): Builder
    {
        return Question::query()
            ->approved()
            ->where('master_language_id', '!=', $languageId)
            ->whereDoesntHave('translations', fn (Builder $q) => $q
                ->where('language_id', $languageId)
                ->whereIn('translation_status', self::SETTLED));
    }

    public function needingTranslationForExam(string $examId, string $languageId): Builder
    {
        return $this->needingTranslation($languageId)
            ->whereHas('examMappings', fn (Builder $q) => $q
                ->where('exam_id', $examId)
                ->where('is_active', true));
    }

    /**
     * @param  array{question_text: string, options: array<string, string>, explanation: string}  $fields
     * @param  array<string, mixed>  $report
     * @return QuestionTranslation|null  null when the existing translation must not be replaced
     */
    public function save(
        Question $question,
        string $languageId,
        array $fields,
        string $status,
        float $qualityScore,
        array $report,
        ?string $jobId = null,
    ): ?QuestionTranslation {
        return DB::transaction(function () use ($question, $languageId, $fields, $status, $qualityScore, $report, $jobId): ?QuestionTranslation {
            // Two jobs translating the same question at once must not both
            // create version 1. A transaction-scoped advisory lock serialises
            // them without touching the question row, which row-level
            // security may not let a tenant job lock.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["translation:{$question->getKey()}:{$languageId}"]);

            $latest = $this->latest($question->getKey(), $languageId);

            if ($latest !== null && in_array($latest->translation_status, self::USABLE, true)) {
                return null;
            }

            $attributes = [
                'source_language_id' => $question->master_language_id,
                'source_question_version' => (int) $question->current_version,
                'question_text' => $fields['question_text'],
                'option_a' => $fields['options']['A'] ?? null,
                'option_b' => $fields['options']['B'] ?? null,
                'option_c' => $fields['options']['C'] ?? null,
                'option_d' => $fields['options']['D'] ?? null,
                'option_e' => null,
                'explanation' => $fields['explanation'] !== '' ? $fields['explanation'] : null,
                'translation_status' => $status,
                'quality_score' => $qualityScore,
                'validation_report' => $report,
                'review_status' => $status === 'NEEDS_REVIEW' ? 'pending' : 'not_required',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'translated_by_job_id' => $jobId,
            ];

            if ($latest !== null && ! in_array($latest->translation_status, self::REPLACEABLE, true)) {
                $latest->forceFill($attributes)->save();

                return $latest;
            }

            return QuestionTranslation::query()->create($attributes + [
                'question_id' => $question->getKey(),
                'language_id' => $languageId,
                'is_master' => false,
                'translation_version' => $latest === null ? 1 : $latest->translation_version + 1,
            ]);
        });
    }
}
