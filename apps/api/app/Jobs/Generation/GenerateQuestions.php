<?php

declare(strict_types=1);

namespace App\Jobs\Generation;

use App\Domain\Topics\LessonTopicMapper;
use App\Domain\Ai\AiGateway;
use App\Domain\Questions\DTOs\QuestionCandidate;
use App\Domain\Questions\DuplicateDetector;
use App\Domain\Questions\QualityScorer;
use App\Domain\Questions\QuestionWriter;
use App\Domain\Questions\ValidationGauntlet;
use App\Domain\Rag\ContextPacker;
use App\Domain\Rag\Retriever;
use App\Domain\Review\ReviewWorkflow;
use App\Jobs\TrackedJob;
use App\Models\AiJob;
use App\Models\Book;
use App\Models\BookLesson;
use App\Models\Exam;
use App\Models\GenerationPolicy;
use App\Models\Language;
use App\Models\Source;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Engine 1's generation half.
 *
 *   plan batches → retrieve → generate → validate → dedupe → score → select
 *
 * The system never asks for exactly N questions. Asking a model for "exactly
 * 200" produces padding, repetition and unsupported claims in the tail, so we
 * ask for ~1.7× and let the gauntlet decide what survives.
 */
final class GenerateQuestions extends TrackedJob
{
    public int $tries = 2;

    /** @return array<string, mixed> */
    protected function execute(AiJob $job): array
    {
        $input = $job->input;
        $target = (int) ($input['target_count'] ?? 50);
        $languageId = (string) $input['language_id'];

        $source = Source::query()->findOrFail($input['source_id']);
        $book = $source->book ?? throw new RuntimeException('Source has no book to generate from.');

        $policy = GenerationPolicy::resolve($input['exam_id'] ?? null);
        $rawTarget = $policy->rawTargetFor($target);

        $lessons = $this->lessonsFor($book, $input['book_lesson_ids'] ?? null);

        if ($lessons === []) {
            throw new RuntimeException('No indexed lessons found; run ingestion first.');
        }

        // The prompt must ask for the language the questions are filed under:
        // told "English", a Tamil request came back in English and was stored
        // as Tamil.
        $context = [
            'language' => (string) (Language::query()->whereKey($languageId)->value('name') ?? 'English'),
            'exam_name' => (string) (isset($input['exam_id']) ? Exam::query()->whereKey($input['exam_id'])->value('name') : null)
                ?: 'General',
        ];

        $plan = array_map(
            static fn (array $batch): array => $batch + $context,
            $this->planBatches($lessons, $rawTarget, (int) $policy->batch_size),
        );

        $job->progressTo(5, sprintf(
            'Planned %d batches for %d raw candidates (target %d approved)',
            count($plan), $rawTarget, $target,
        ));

        $stats = [
            'raw_generated' => 0,
            'rejected' => [],
            'accepted' => 0,
            'pending_review' => 0,
            'coverage_skipped' => [],
        ];

        $accepted = [];
        $step = 0;

        foreach ($plan as $batch) {
            $step++;
            $progress = 5 + (int) (80 * $step / max(1, count($plan)));

            $result = $this->runBatch($job, $batch, $policy, $languageId, $source);

            $stats['raw_generated'] += $result['raw'];
            $stats['coverage_skipped'] = [...$stats['coverage_skipped'], ...$result['skipped']];

            foreach ($result['rejected'] as $code => $count) {
                $stats['rejected'][$code] = ($stats['rejected'][$code] ?? 0) + $count;
            }

            $accepted = [...$accepted, ...$result['accepted']];

            $job->progressTo($progress, sprintf(
                'Batch %d/%d · %d candidates · %d kept',
                $step, count($plan), $result['raw'], count($result['accepted']),
            ));

            // Stop early once we have comfortably enough: the overgeneration
            // factor is an estimate, not a quota to burn through.
            if (count($accepted) >= $target) {
                break;
            }
        }

        $job->markValidating('Selecting final set');

        $selected = $this->selectBest($accepted, $target);

        $stats['accepted'] = count($selected);
        $stats['pending_review'] = count($selected);
        $stats['shortfall'] = max(0, $target - count($selected));

        if ($stats['shortfall'] > 0) {
            // A shortfall is reported, never hidden by silently substituting.
            $this->recordCoverageGap($input, $stats['shortfall']);
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $batch
     * @return array{raw: int, accepted: list<string>, rejected: array<string,int>, skipped: list<string>}
     */
    private function runBatch(
        AiJob $job,
        array $batch,
        GenerationPolicy $policy,
        string $languageId,
        Source $source,
    ): array {
        /** @var BookLesson $lesson */
        $lesson = $batch['lesson'];
        $count = (int) $batch['count'];

        $retriever = app(Retriever::class);
        $chunks = $retriever->retrieve(
            $lesson->title,
            ['book_lesson_id' => $lesson->getKey()],
        );

        // The guard that stops the platform inventing questions on topics the
        // uploaded books do not actually cover.
        if (! $retriever->hasSufficientCoverage($chunks)) {
            return [
                'raw' => 0, 'accepted' => [], 'rejected' => [],
                'skipped' => [$lesson->title.' (insufficient source coverage)'],
            ];
        }

        $packed = app(ContextPacker::class)->pack($chunks);

        $result = app(AiGateway::class)->structured(
            'question_generation_mcq',
            [
                'count' => $count,
                'exam_name' => $batch['exam_name'] ?? 'General',
                'language' => $batch['language'] ?? 'English',
                'difficulty_mix' => json_encode($policy->difficulty_mix),
                'formats' => implode(', ', $job->input['formats'] ?? ['mcq4']),
                'topic' => $lesson->title,
                'subtopic' => '',
                'source_material' => $packed['block'],
                'existing_stems' => implode("\n", $batch['existing_stems'] ?? []),
            ],
            [
                'ai_job_id' => $job->getKey(),
                'organization_id' => $job->organization_id,
                'user_id' => $job->user_id,
            ],
        );

        $candidates = [];

        foreach ($result->get('questions', []) as $index => $raw) {
            $candidates[] = QuestionCandidate::fromModelOutput($raw, $index);
        }

        if ($candidates === []) {
            return ['raw' => 0, 'accepted' => [], 'rejected' => [], 'skipped' => []];
        }

        app(ValidationGauntlet::class)->run($candidates, $packed['map'], [
            'ai_job_id' => $job->getKey(),
            'organization_id' => $job->organization_id,
        ]);

        $detector = app(DuplicateDetector::class);
        $detector->deduplicateBatch($candidates, $policy);
        $detector->deduplicateAgainstBank(
            $candidates,
            $policy,
            app(LessonTopicMapper::class)->topicForLesson($lesson->getKey()),
            $job->organization_id,
        );

        return $this->persist($candidates, $policy, $languageId, $source, $job, $lesson);
    }

    /**
     * @param  list<QuestionCandidate>  $candidates
     * @return array{raw: int, accepted: list<string>, rejected: array<string,int>, skipped: list<string>}
     */
    private function persist(
        array $candidates,
        GenerationPolicy $policy,
        string $languageId,
        Source $source,
        AiJob $job,
        BookLesson $lesson,
    ): array {
        $scorer = app(QualityScorer::class);
        $writer = app(QuestionWriter::class);

        $accepted = [];
        $rejected = [];

        $meta = [
            'organization_id' => $source->organization_id,
            'language_id' => $languageId,
            // The lesson's syllabus topic, so the backlog counts these and
            // the blueprint weights reach them.
            'canonical_topic_id' => app(LessonTopicMapper::class)->topicForLesson($lesson->getKey()),
            'ai_job_id' => $job->getKey(),
            'user_id' => $job->user_id,
            // Without an exam mapping an approved question is unreachable by
            // the selection engine, so the target exam is carried through.
            'exam_ids' => array_filter([$job->input['exam_id'] ?? null]),
        ];

        foreach ($candidates as $candidate) {
            $candidate->qualityScore = $scorer->score($candidate);

            $status = $candidate->isRejected()
                ? 'REJECTED'
                : $scorer->routeFor(
                    $candidate->qualityScore,
                    (float) $policy->auto_approve_threshold,
                    (float) $policy->review_threshold,
                    (bool) $policy->allow_auto_approve,
                );

            $question = $writer->write($candidate, $status, $meta);

            if ($status === 'REJECTED') {
                $code = $candidate->rejections[0]['code'] ?? 'LOW_QUALITY';
                $rejected[$code] = ($rejected[$code] ?? 0) + 1;

                continue;
            }

            // Queue for human review, best candidates first: a reviewer's
            // first hour should go to the questions most likely to be keepers.
            if ($status === 'PENDING_REVIEW') {
                app(ReviewWorkflow::class)->enqueue(
                    $question,
                    priority: (int) round(($candidate->qualityScore ?? 0) / 10),
                );
            }

            $accepted[] = $question->getKey();
        }

        return [
            'raw' => count($candidates),
            'accepted' => $accepted,
            'rejected' => $rejected,
            'skipped' => [],
        ];
    }

    /**
     * Distributes the raw target across lessons by available material, then
     * splits each lesson's share into model-sized batches.
     *
     * Batch size 25 is where per-call overhead amortises but the model still
     * keeps every item distinct; larger batches measurably raise intra-batch
     * duplication.
     *
     * @param  list<BookLesson>  $lessons
     * @return list<array<string, mixed>>
     */
    private function planBatches(array $lessons, int $rawTarget, int $batchSize): array
    {
        $weights = [];

        foreach ($lessons as $lesson) {
            $weights[$lesson->getKey()] = max(1, DB::table('source_chunks')
                ->where('book_lesson_id', $lesson->getKey())
                ->where('is_exercise', false)
                ->count());
        }

        $totalWeight = array_sum($weights) ?: 1;
        $plan = [];

        foreach ($lessons as $lesson) {
            $share = (int) round($rawTarget * $weights[$lesson->getKey()] / $totalWeight);

            for ($remaining = $share; $remaining > 0; $remaining -= $batchSize) {
                $plan[] = [
                    'lesson' => $lesson,
                    'count' => min($batchSize, $remaining),
                ];
            }
        }

        return $plan;
    }

    /**
     * @param  list<string>  $accepted  question ids
     * @return list<string>
     */
    private function selectBest(array $accepted, int $target): array
    {
        if (count($accepted) <= $target) {
            return $accepted;
        }

        return DB::table('questions')
            ->whereIn('id', $accepted)
            ->orderByDesc('quality_score')
            ->limit($target)
            ->pluck('id')
            ->all();
    }

    /** @param  array<string, mixed>  $input */
    private function recordCoverageGap(array $input, int $deficit): void
    {
        if (! isset($input['exam_id'])) {
            return;
        }

        $key = [
            'exam_id' => $input['exam_id'],
            'canonical_topic_id' => $input['canonical_topic_id'] ?? null,
            'language_id' => $input['language_id'],
            'difficulty' => null,
        ];

        // Deliberately not updateOrInsert(): on the insert path it would put
        // the `demand_count + 1` expression into the VALUES clause, where the
        // table it references is not in scope, and Postgres rejects the whole
        // statement. Read first, then take the branch that fits.
        $existing = DB::table('coverage_gaps')->where($key)->first();

        if ($existing === null) {
            DB::table('coverage_gaps')->insert($key + [
                'deficit' => $deficit,
                'demand_count' => 1,
                'last_seen_at' => now(),
            ]);

            return;
        }

        DB::table('coverage_gaps')
            ->where('id', $existing->id)
            ->update([
                'deficit' => $deficit,
                'demand_count' => $existing->demand_count + 1,
                'last_seen_at' => now(),
            ]);
    }

    /**
     * @param  list<string>|null  $lessonIds
     * @return list<BookLesson>
     */
    private function lessonsFor(Book $book, ?array $lessonIds): array
    {
        return BookLesson::query()
            ->whereIn('book_chapter_id', $book->chapters()->pluck('id'))
            ->when($lessonIds, fn ($q) => $q->whereIn('id', $lessonIds))
            ->orderBy('sequence')
            ->get()
            ->all();
    }
}
