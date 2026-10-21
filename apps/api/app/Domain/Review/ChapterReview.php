<?php

declare(strict_types=1);

namespace App\Domain\Review;

use App\Domain\Questions\Aptitude\AptitudeGenerator;
use App\Models\Language;
use App\Models\Question;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reviewing written questions a chapter at a time.
 *
 * questions:import files every question of a chapter under one breadcrumb,
 * so a chapter is (source, breadcrumb) on the primary citation. A reviewer
 * reads a random handful of a chapter's waiting questions, and if they hold
 * up, approves the rest of the chapter on the strength of that sample.
 *
 * Each approval still goes through ReviewWorkflow::decide, one question at a
 * time: every question is versioned, audited and attributed to the reviewer
 * exactly as if it had been approved at the workstation, and the reason
 * names the spot-check it rested on.
 */
final class ChapterReview
{
    public const DEFAULT_SAMPLE = 5;

    /** Questions approved per request; see approve(). */
    public const BATCH = 100;

    public const MAX_PER_CHAPTER = 50;

    public function __construct(private readonly ReviewWorkflow $workflow) {}

    /**
     * Chapters that still have questions waiting, most waiting first.
     *
     * @return list<array{key: string, source: string, breadcrumb: string, pending: int, approved: int, rejected: int}>
     */
    public function chapters(): array
    {
        return DB::table('question_sources as qs')
            ->join('questions as q', 'q.id', '=', 'qs.question_id')
            ->join('sources as s', 's.id', '=', 'qs.source_id')
            ->where('qs.is_primary', true)
            ->whereNull('q.organization_id')
            ->whereNotNull('qs.breadcrumb')
            ->groupBy('qs.source_id', 'qs.breadcrumb', 's.title')
            ->havingRaw("count(*) filter (where q.status = 'PENDING_REVIEW') > 0")
            ->orderByRaw("count(*) filter (where q.status = 'PENDING_REVIEW') desc")
            ->orderBy('qs.breadcrumb')
            ->selectRaw("md5(qs.source_id::text || '|' || qs.breadcrumb) as key")
            ->selectRaw('s.title as source, qs.breadcrumb')
            ->selectRaw("count(*) filter (where q.status = 'PENDING_REVIEW') as pending")
            ->selectRaw("count(*) filter (where q.status = 'APPROVED') as approved")
            ->selectRaw("count(*) filter (where q.status = 'REJECTED') as rejected")
            ->get()
            ->map(fn (object $row): array => [
                'key' => $row->key,
                'source' => $row->source,
                'breadcrumb' => $row->breadcrumb,
                'pending' => (int) $row->pending,
                'approved' => (int) $row->approved,
                'rejected' => (int) $row->rejected,
            ])
            ->all();
    }

    /**
     * A random handful of the chapter's waiting questions, in every language
     * they are written in, with the page and the book's own words.
     *
     * @return array{chapter: array<string, mixed>, questions: list<array<string, mixed>>}
     */
    public function sample(string $key, int $size = self::DEFAULT_SAMPLE): array
    {
        $chapter = $this->chapter($key);
        $tamil = Language::byCode('ta');

        $questions = Question::query()
            ->with(['master', 'translations', 'sources', 'canonicalTopic'])
            ->whereKey($this->pendingIds($key))
            ->inRandomOrder()
            ->limit(max(1, min(20, $size)))
            ->get();

        $reviews = Review::query()
            ->where('reviewable_type', Question::class)
            ->whereIn('reviewable_id', $questions->modelKeys())
            ->pluck('id', 'reviewable_id');

        return [
            'chapter' => $chapter,
            'questions' => $questions->map(function (Question $q) use ($tamil, $reviews): array {
                $source = $q->sources->firstWhere('is_primary', true) ?? $q->sources->first();
                $ta = $tamil ? $q->translations->first(fn ($t) => $t->language_id === $tamil->getKey() && ! $t->is_master) : null;

                return [
                    'question_id' => $q->getKey(),
                    'review_id' => $reviews[$q->getKey()] ?? null,
                    'format' => $q->format,
                    'difficulty' => $q->difficulty,
                    'topic' => $q->canonicalTopic?->name,
                    'page' => $source?->page_start,
                    'quote' => $source?->supporting_quote,
                    'correct_option' => $q->correctOptionKey(),
                    'master' => [
                        'question_text' => $q->master?->question_text,
                        'options' => $q->master?->optionMap(),
                        'explanation' => $q->master?->explanation,
                    ],
                    'tamil' => $ta === null ? null : [
                        'question_text' => $ta->question_text,
                        'options' => $ta->optionMap(),
                        'explanation' => $ta->explanation,
                    ],
                ];
            })->values()->all(),
        ];
    }

    /**
     * Approves the chapter's waiting questions, a batch at a time.
     *
     * Each batch is all-or-nothing. A book chapter (50 or fewer) is one batch;
     * the generated aptitude set runs to hundreds, and approving them in one
     * request would outlast the web server's time limit, so the caller asks
     * again while anything remains.
     *
     * Questions another reviewer currently holds are left alone and counted,
     * rather than taken from under them.
     *
     * @param  list<string>  $checkedIds  the questions the reviewer actually read
     * @return array{approved: int, skipped: int, remaining: int}
     */
    public function approve(string $key, User $reviewer, array $checkedIds, int $batch = self::BATCH): array
    {
        $chapter = $this->chapter($key);
        $pending = $this->pendingIds($key);

        // The bank keeps at most 50 questions per book chapter; the extras are
        // rejected, not approved. Generated aptitude sets are exempt.
        if ($chapter['breadcrumb'] !== AptitudeGenerator::BREADCRUMB
            && $chapter['approved'] + $chapter['pending'] > self::MAX_PER_CHAPTER) {
            throw new RuntimeException(sprintf(
                'Approving this chapter would give it %d approved questions; a book chapter keeps at most %d. Reject the extras first.',
                $chapter['approved'] + $chapter['pending'],
                self::MAX_PER_CHAPTER,
            ));
        }

        // The ids a later batch sends back were read in the first one and are
        // approved by now, so they count as read if they belong to the chapter.
        $checked = array_values(array_intersect($checkedIds, $this->chapterIds($key)));

        if (array_intersect($checked, $pending) === [] && ! $this->anyApproved($checked)) {
            throw new RuntimeException('Read at least one of this chapter\'s waiting questions before approving it.');
        }

        $reason = sprintf('Approved with its chapter after a spot-check of %d question%s.', count($checked), count($checked) === 1 ? '' : 's');

        return DB::transaction(function () use ($key, $pending, $reviewer, $reason, $batch): array {
            $approved = 0;
            $skipped = 0;

            $reviews = Review::query()
                ->where('reviewable_type', Question::class)
                ->whereIn('reviewable_id', $pending)
                ->whereIn('status', ['pending', 'in_review'])
                ->orderBy('created_at')
                ->limit(max(1, min(self::BATCH, $batch)))
                ->lockForUpdate()
                ->get();

            foreach ($reviews as $review) {
                if ($review->isClaimedBySomeoneElse($reviewer->getKey())) {
                    $skipped++;

                    continue;
                }

                $this->workflow->decide($review, $reviewer, 'approve', $reason);
                $approved++;
            }

            // What another batch could still approve. Questions another
            // reviewer holds are not counted: this never approves them, and
            // counting them would send the caller round in a loop.
            $remaining = Review::query()
                ->where('reviewable_type', Question::class)
                ->whereIn('reviewable_id', $this->pendingIds($key))
                ->whereIn('status', ['pending', 'in_review'])
                ->where(fn ($q) => $q
                    ->whereNull('assigned_to')
                    ->orWhere('assigned_to', $reviewer->getKey())
                    ->orWhere('claim_expires_at', '<', now()))
                ->count();

            return ['approved' => $approved, 'skipped' => $skipped, 'remaining' => $remaining];
        });
    }

    /** @param  list<string>  $ids */
    private function anyApproved(array $ids): bool
    {
        return $ids !== [] && Question::query()->whereKey($ids)->where('status', 'APPROVED')->exists();
    }

    /** @return list<string> every question filed under the chapter, whatever its status */
    private function chapterIds(string $key): array
    {
        return DB::table('question_sources as qs')
            ->join('questions as q', 'q.id', '=', 'qs.question_id')
            ->where('qs.is_primary', true)
            ->whereNull('q.organization_id')
            ->whereRaw("md5(qs.source_id::text || '|' || qs.breadcrumb) = ?", [$key])
            ->pluck('q.id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /** @return array{key: string, source: string, breadcrumb: string, pending: int, approved: int, rejected: int} */
    private function chapter(string $key): array
    {
        foreach ($this->chapters() as $chapter) {
            if ($chapter['key'] === $key) {
                return $chapter;
            }
        }

        throw new ChapterNotFound('No chapter with questions waiting for review matches that key.');
    }

    /** @return list<string> */
    private function pendingIds(string $key): array
    {
        return DB::table('question_sources as qs')
            ->join('questions as q', 'q.id', '=', 'qs.question_id')
            ->where('qs.is_primary', true)
            ->whereNull('q.organization_id')
            ->where('q.status', 'PENDING_REVIEW')
            ->whereRaw("md5(qs.source_id::text || '|' || qs.breadcrumb) = ?", [$key])
            ->pluck('q.id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }
}
