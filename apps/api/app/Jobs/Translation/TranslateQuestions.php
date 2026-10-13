<?php

declare(strict_types=1);

namespace App\Jobs\Translation;

use App\Domain\Translation\QuestionTranslator;
use App\Domain\Translation\TranslationPrompts;
use App\Domain\Translation\TranslationStore;
use App\Jobs\TrackedJob;
use App\Models\AiJob;
use App\Models\Language;
use App\Models\Question;
use App\Models\TestQuestion;
use Illuminate\Support\Collection;

/**
 * Translates approved questions into one language, ten at a time.
 *
 * Input picks the questions one of three ways:
 *   question_ids       — exactly these
 *   test_id            — the questions on one paper
 *   exam_id (+ limit)  — the best approved questions mapped to an exam that
 *                        are not yet readable in the language
 *
 * Questions already usable in the language — or waiting for a reviewer —
 * are skipped, never paid for twice. A STALE or REJECTED translation is not
 * usable, so it is translated again as a new version.
 *
 * Ten per call keeps a local model's context comfortable and means one bad
 * reply costs ten questions, not a whole exam. A retry resumes rather than
 * restarts: whatever the failed attempt already stored is skipped.
 */
final class TranslateQuestions extends TrackedJob
{
    public const BATCH_SIZE = 10;

    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 1000;

    /** @return array<string, mixed> */
    protected function execute(AiJob $job): array
    {
        $input = $job->input;
        $language = Language::query()->findOrFail((string) $input['language_id']);

        // Before anything is loaded or paid for: a language with no prompt
        // cannot be translated into, and saying so plainly is the whole job.
        app(TranslationPrompts::class)->resolve($language);

        [$candidates, $requested, $skipped] = $this->candidates($input, $language);

        $output = [
            'language' => $language->code,
            'requested' => $requested,
            'translated' => 0,
            'validated' => 0,
            'needs_review' => 0,
            'failed' => 0,
            'skipped' => $skipped,
        ];

        if ($candidates->isEmpty()) {
            $job->progressTo(100, $requested === 0
                ? 'No approved questions matched the request.'
                : 'Nothing to translate: every question is already available or awaiting review.');

            return $output;
        }

        $batches = $candidates->chunk(self::BATCH_SIZE)->values();
        $errors = [];
        $translator = app(QuestionTranslator::class);

        $job->progressTo(2, sprintf(
            'Translating %d question%s into %s in %d batch%s',
            $candidates->count(), $candidates->count() === 1 ? '' : 's',
            $language->name, $batches->count(), $batches->count() === 1 ? '' : 'es',
        ));

        foreach ($batches as $index => $batch) {
            // Cancellation is cooperative: checked between batches, so a
            // cancelled job stops after the call already in flight.
            if ($job->refresh()->isCancelled()) {
                break;
            }

            $result = $translator->translate($batch->values(), $language, $job);

            foreach (['translated', 'validated', 'needs_review', 'failed', 'skipped'] as $key) {
                $output[$key] += $result[$key];
            }

            $errors = [...$errors, ...$result['errors']];

            $job->progressTo(
                2 + (int) (96 * ($index + 1) / $batches->count()),
                sprintf(
                    'Batch %d/%d · %d validated · %d need review · %d failed',
                    $index + 1, $batches->count(), $output['validated'], $output['needs_review'], $output['failed'],
                ),
            );
        }

        if ($errors !== []) {
            $output['errors'] = array_slice($errors, 0, 10);
        }

        return $output;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: Collection<int, Question>, 1: int, 2: int}  candidates, requested, skipped
     */
    private function candidates(array $input, Language $language): array
    {
        $store = app(TranslationStore::class);
        $with = ['options', 'translations', 'master'];

        if (! empty($input['question_ids']) || ! empty($input['test_id'])) {
            $ids = ! empty($input['question_ids'])
                ? array_values(array_unique(array_map('strval', (array) $input['question_ids'])))
                : TestQuestion::query()->where('test_id', $input['test_id'])->orderBy('sequence')->pluck('question_id')->unique()->values()->all();

            $wanted = $store->needingTranslation($language->getKey())
                ->whereIn('id', $ids)
                ->with($with)
                ->get()
                // Keep the caller's order: a paper is translated top to bottom.
                ->sortBy(fn (Question $q): int => (int) array_search($q->getKey(), $ids, true))
                ->values();

            return [$wanted, count($ids), count($ids) - $wanted->count()];
        }

        if (! empty($input['exam_id'])) {
            $limit = max(1, min(self::MAX_LIMIT, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)));

            // Best first: if the run is cut short, the questions most likely
            // to appear on a paper are the ones already done.
            $wanted = $store->needingTranslationForExam((string) $input['exam_id'], $language->getKey())
                ->with($with)
                ->orderByRaw('quality_score DESC NULLS LAST')
                ->orderBy('id')
                ->limit($limit)
                ->get();

            return [$wanted, $wanted->count(), 0];
        }

        return [new Collection, 0, 0];
    }
}
