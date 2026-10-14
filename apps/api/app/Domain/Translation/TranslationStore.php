<?php

declare(strict_types=1);

namespace App\Domain\Translation;

use App\Domain\Translation\Exceptions\TranslationDiscarded;
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
 * Two more refusals happen at write time, under the same lock a reviewer's
 * decision takes: a translation of a master that was edited while the model
 * was working is discarded, and so is one that repeats, word for word, a
 * translation a reviewer already rejected.
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
     * Serialises every write to one question's translation in one language,
     * machine saves and human decisions alike. Transaction-scoped, so it is
     * released with the caller's commit or rollback.
     */
    public function lock(string $questionId, string $languageId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["translation:{$questionId}:{$languageId}"]);
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
     * Identity of the master text a translation is made from. Compared again
     * at save time, so an edit made while the model was working is caught
     * even if nothing bumped the version counter.
     */
    public static function masterFingerprint(?QuestionTranslation $master): string
    {
        if ($master === null) {
            return '';
        }

        return self::textFingerprint([
            'question_text' => (string) $master->question_text,
            'options' => [
                'A' => (string) $master->option_a, 'B' => (string) $master->option_b,
                'C' => (string) $master->option_c, 'D' => (string) $master->option_d,
            ],
            'explanation' => (string) $master->explanation,
        ]);
    }

    /**
     * What a reader sees, with whitespace normalised.
     *
     * @param  array{question_text: string, options: array<string, string>, explanation?: string}  $fields
     */
    public static function textFingerprint(array $fields, bool $withExplanation = true): string
    {
        $norm = static fn (?string $s): string => trim((string) preg_replace('/\s+/u', ' ', (string) $s));

        $parts = [$norm($fields['question_text'])];

        foreach (['A', 'B', 'C', 'D'] as $key) {
            $parts[] = $norm($fields['options'][$key] ?? '');
        }

        if ($withExplanation) {
            $parts[] = $norm($fields['explanation'] ?? '');
        }

        return hash('sha256', implode("\u{1F}", $parts));
    }

    /**
     * @param  array{question_text: string, options: array<string, string>, explanation: string}  $fields
     * @param  array<string, mixed>  $report
     * @param  int|null  $sourceVersion  question version the text was translated from
     * @param  string|null  $sourceFingerprint  masterFingerprint() of the text translated
     * @return QuestionTranslation|null  null when the existing translation must not be replaced
     *
     * @throws TranslationDiscarded when the master moved on, or the text repeats a rejected one
     */
    public function save(
        Question $question,
        string $languageId,
        array $fields,
        string $status,
        float $qualityScore,
        array $report,
        ?string $jobId = null,
        ?int $sourceVersion = null,
        ?string $sourceFingerprint = null,
    ): ?QuestionTranslation {
        return DB::transaction(function () use (
            $question, $languageId, $fields, $status, $qualityScore, $report, $jobId, $sourceVersion, $sourceFingerprint
        ): ?QuestionTranslation {
            // Two jobs translating the same question at once must not both
            // create version 1, and a reviewer's decision must not land
            // between the reads below and the write.
            $this->lock($question->getKey(), $languageId);

            // The translation is of the text as it was when the batch was
            // prepared. If the master has been edited since — a new version,
            // or different words — it describes a question that no longer
            // exists, and storing it would serve the old question (and its
            // old numbers) under the new one. Read from the database, never
            // from the model the caller has been holding.
            if ($sourceVersion !== null || $sourceFingerprint !== null) {
                $currentVersion = (int) DB::table('questions')->where('id', $question->getKey())->value('current_version');
                $currentMaster = QuestionTranslation::query()
                    ->where('question_id', $question->getKey())
                    ->where('is_master', true)
                    ->first();

                if (($sourceVersion !== null && $currentVersion !== $sourceVersion)
                    || ($sourceFingerprint !== null && self::masterFingerprint($currentMaster) !== $sourceFingerprint)) {
                    throw new TranslationDiscarded(
                        TranslationDiscarded::MASTER_CHANGED,
                        "Question {$question->getKey()} was edited while it was being translated; the translation was discarded and will be redone on the next request.",
                    );
                }
            }

            $latest = $this->latest($question->getKey(), $languageId);

            if ($latest !== null && in_array($latest->translation_status, self::USABLE, true)) {
                return null;
            }

            // A reviewer has already said no to exactly this text. Storing it
            // again would put it back in front of them — or, if the checks
            // happen to pass, in front of students.
            $fingerprint = self::textFingerprint($fields, false);

            $repeatsRejected = QuestionTranslation::query()
                ->where('question_id', $question->getKey())
                ->where('language_id', $languageId)
                ->where('is_master', false)
                ->where('translation_status', 'REJECTED')
                ->get()
                ->contains(static fn (QuestionTranslation $t): bool => self::textFingerprint([
                    'question_text' => (string) $t->question_text,
                    'options' => [
                        'A' => (string) $t->option_a, 'B' => (string) $t->option_b,
                        'C' => (string) $t->option_c, 'D' => (string) $t->option_d,
                    ],
                ], false) === $fingerprint);

            if ($repeatsRejected) {
                throw new TranslationDiscarded(
                    TranslationDiscarded::REPEATS_REJECTED,
                    "The translation of question {$question->getKey()} repeated one a reviewer already rejected; it was not stored.",
                );
            }

            $attributes = [
                'source_language_id' => $question->master_language_id,
                // Pinned to the version actually translated.
                'source_question_version' => $sourceVersion ?? (int) $question->current_version,
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
