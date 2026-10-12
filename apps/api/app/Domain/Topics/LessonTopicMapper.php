<?php

declare(strict_types=1);

namespace App\Domain\Topics;

use App\Domain\Ai\AiGateway;
use App\Domain\Audit\AuditRecorder;
use App\Models\Book;
use App\Models\BookLesson;
use App\Models\CanonicalTopic;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Files each book lesson under a canonical topic — the link between "page 42
 * of the Std 9 science book" and "Physics: Force, Motion and Energy".
 *
 * Without it every generated question had no topic, so the backlog never
 * counted them, the blueprint weights never applied to them, and prediction
 * scores could not reach them. The topic travels from lesson to question at
 * generation time, and existing questions are back-filled when a lesson is
 * mapped.
 *
 * Same discipline as past-paper mapping: lexical first (free), then a
 * constrained model that cannot introduce a topic, and a human decision is
 * never overwritten by a re-run.
 */
final class LessonTopicMapper
{
    /** Below this a mapping is kept but shown as needing a look. */
    public const REVIEW_THRESHOLD = 0.70;

    /** Below this an answer is discarded. A wrong topic is worse than none. */
    private const FLOOR = 0.45;

    private const BATCH = 15;

    private const EXCERPT_CHARS = 700;

    public function __construct(
        private readonly AiGateway $ai,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array{lessons: int, mapped: int, lexical: int, model: int, human: int, unmapped: int, questions_tagged: int}
     */
    public function mapBook(Book $book): array
    {
        $stats = ['lessons' => 0, 'mapped' => 0, 'lexical' => 0, 'model' => 0, 'human' => 0, 'unmapped' => 0, 'questions_tagged' => 0];
        $topics = CanonicalTopic::query()->get(['id', 'slug', 'name', 'domain', 'aliases']);
        $lessons = $this->lessonsOf($book);
        $stats['lessons'] = $lessons->count();

        if ($topics->isEmpty() || $lessons->isEmpty()) {
            $stats['unmapped'] = $stats['lessons'];

            return $stats;
        }

        $human = DB::table('book_topic_mappings')
            ->whereIn('book_lesson_id', $lessons->pluck('id'))
            ->where('mapped_by', 'human')
            ->pluck('book_lesson_id')
            ->flip();

        // What each lesson was filed under before this run, so questions that
        // inherited a now-corrected topic can follow the correction.
        $previous = $lessons->mapWithKeys(fn ($l) => [$l->id => $this->topicForLesson($l->id)]);
        $remaining = [];

        foreach ($lessons as $lesson) {
            if ($human->has($lesson->id)) {
                $stats['human']++;
                $stats['mapped']++;

                continue;
            }

            $match = $this->lexicalMatch($lesson, $topics);

            if ($match !== null) {
                $this->replaceMapping($lesson->id, $match['topic_id'], $match['confidence'], 'lexical');
                $stats['lexical']++;
                $stats['mapped']++;

                continue;
            }

            // A lexical match from an earlier run that no longer holds is
            // withdrawn; the model gets its chance, and otherwise the lesson
            // is honestly unmapped rather than stuck on a stale guess.
            DB::table('book_topic_mappings')
                ->where('book_lesson_id', $lesson->id)
                ->where('mapped_by', 'lexical')
                ->delete();

            $remaining[] = $lesson;
        }

        foreach (array_chunk($remaining, self::BATCH) as $chunk) {
            $stats['model'] += $this->mapWithModel($chunk, $topics);
        }

        $stats['mapped'] += $stats['model'];
        $stats['unmapped'] = $stats['lessons'] - $stats['mapped'];

        foreach ($lessons as $lesson) {
            $stats['questions_tagged'] += $this->tagQuestions($lesson->id, $previous[$lesson->id] ?? null);
        }

        return $stats;
    }

    /**
     * The topic questions generated from this lesson are filed under: a human
     * choice first, otherwise the most confident mapping above the floor.
     */
    public function topicForLesson(string $lessonId): ?string
    {
        $row = DB::table('book_topic_mappings')
            ->where('book_lesson_id', $lessonId)
            ->whereNotNull('canonical_topic_id')
            ->orderByRaw("CASE WHEN mapped_by = 'human' THEN 0 ELSE 1 END")
            ->orderByDesc('confidence')
            ->first();

        return $row?->canonical_topic_id;
    }

    /**
     * A person's decision. Pass null to record that the lesson belongs to no
     * topic, which also stops re-runs from guessing one.
     */
    public function assignByHand(BookLesson $lesson, ?string $canonicalTopicId, ?string $userId): int
    {
        DB::transaction(function () use ($lesson, $canonicalTopicId, $userId): void {
            $before = $this->topicForLesson($lesson->getKey());

            DB::table('book_topic_mappings')->where('book_lesson_id', $lesson->getKey())->delete();

            DB::table('book_topic_mappings')->insert([
                'book_lesson_id' => $lesson->getKey(),
                'canonical_topic_id' => $canonicalTopicId,
                'confidence' => 1.0,
                'mapped_by' => 'human',
                'verified_by' => $userId,
                'created_at' => now(),
            ]);

            $this->audit->record('lesson.topic_assigned', $lesson, [
                'from' => $before,
                'to' => $canonicalTopicId,
            ]);
        });

        return $this->tagQuestions($lesson->getKey(), previous: null, overwrite: true);
    }

    /**
     * Files the lesson's questions under its current topic.
     *
     * Touched: questions with no topic, and questions still carrying the
     * topic the lesson had before ($previous) — they inherited it from the
     * mapping now being corrected. A question filed elsewhere by other means
     * is left alone. A human re-assignment overwrites all of the lesson's.
     */
    private function tagQuestions(string $lessonId, ?string $previous, bool $overwrite = false): int
    {
        $topicId = $this->topicForLesson($lessonId);

        if (! $overwrite && $topicId === $previous && $topicId === null) {
            return 0;
        }

        return DB::table('questions')
            ->whereIn('id', DB::table('question_sources')
                ->where('book_lesson_id', $lessonId)
                ->where('is_primary', true)
                ->select('question_id'))
            ->when(! $overwrite, fn ($q) => $q->where(function ($w) use ($previous, $topicId): void {
                $w->whereNull('canonical_topic_id');

                if ($previous !== null && $previous !== $topicId) {
                    $w->orWhere('canonical_topic_id', $previous);
                }
            }))
            ->update(['canonical_topic_id' => $topicId]);
    }

    /** @return Collection<int, object{id: string, title: string, chapter: string, excerpt: string}> */
    private function lessonsOf(Book $book): Collection
    {
        $lessons = DB::table('book_lessons as l')
            ->join('book_chapters as c', 'c.id', '=', 'l.book_chapter_id')
            ->where('c.book_id', $book->getKey())
            ->orderBy('c.sequence')->orderBy('l.sequence')
            ->select('l.id', 'l.title', 'c.title as chapter')
            ->get();

        $excerpts = DB::table('source_chunks')
            ->whereIn('book_lesson_id', $lessons->pluck('id'))
            ->where('is_exercise', false)
            ->orderBy('chunk_index')
            ->get(['book_lesson_id', 'text'])
            ->groupBy('book_lesson_id')
            ->map(fn (Collection $chunks): string => mb_substr($chunks->pluck('text')->implode(' '), 0, self::EXCERPT_CHARS * 3));

        return $lessons->map(function ($lesson) use ($excerpts) {
            $lesson->excerpt = (string) ($excerpts[$lesson->id] ?? '');

            return $lesson;
        });
    }

    /**
     * A topic phrase in the lesson or chapter title is strong evidence; in the
     * body it counts only when it recurs. The best-scoring topic wins only if
     * it clearly beats the runner-up — a tie is a question for a person or a
     * model, not a coin toss.
     *
     * @param  Collection<int, CanonicalTopic>  $topics
     * @return array{topic_id: string, confidence: float}|null
     */
    private function lexicalMatch(object $lesson, Collection $topics): ?array
    {
        $title = TopicPhrases::normalise($lesson->title.' '.$lesson->chapter);
        $lessonTitle = TopicPhrases::normalise($lesson->title);
        $titleWords = array_flip(TopicPhrases::stems($lessonTitle));
        $body = TopicPhrases::normalise($lesson->excerpt);
        $scores = [];

        foreach ($topics as $topic) {
            $score = 0.0;

            foreach (TopicPhrases::for($topic) as $phrase) {
                $weight = TopicPhrases::words($phrase) >= 2 ? 1.0 : 0.7;
                $score += 3 * $weight * min(1, TopicPhrases::occurrences($title, $phrase));
                $score += $weight * min(3, TopicPhrases::occurrences($body, $phrase));
            }

            // Keywords of the topic's name in the lesson's own title: one is
            // a hint, two or more is a match on its own.
            $score += count(array_filter(
                TopicPhrases::keywords($topic),
                static fn (string $k): bool => isset($titleWords[$k]),
            ));

            // A curated alias naming the lesson ("Light and Sound", "Rocks")
            // is the strongest lexical evidence there is.
            $aliasHits = count(array_filter(
                TopicPhrases::aliases($topic),
                static fn (string $a): bool => TopicPhrases::occurrences($lessonTitle, $a) > 0,
            ));
            $score += min(6, 3 * $aliasHits);

            if ($score > 0) {
                $scores[$topic->getKey()] = $score;
            }
        }

        if ($scores === []) {
            return null;
        }

        arsort($scores);
        $ranked = array_values($scores);
        $best = $ranked[0];
        $runnerUp = $ranked[1] ?? 0.0;

        // Needs either a title hit or repeated body evidence, and a margin.
        if ($best < 2.0 || $best < $runnerUp * 1.5) {
            return null;
        }

        return [
            'topic_id' => (string) array_key_first($scores),
            'confidence' => round(min(0.95, 0.55 + 0.1 * $best), 3),
        ];
    }

    /**
     * @param  list<object>  $lessons
     * @param  Collection<int, CanonicalTopic>  $topics
     */
    private function mapWithModel(array $lessons, Collection $topics): int
    {
        $allowed = $topics->keyBy('slug');

        try {
            $result = $this->ai->structured('lesson_topic_classification', [
                'topics' => $topics
                    ->map(fn (CanonicalTopic $t): string => "{$t->slug} — {$t->name} ({$t->domain})")
                    ->implode("\n"),
                'items' => collect($lessons)
                    ->map(fn ($l): string => "{$l->id} :: {$l->chapter} › {$l->title} :: "
                        .mb_substr((string) preg_replace('/\s+/u', ' ', $l->excerpt), 0, self::EXCERPT_CHARS))
                    ->implode("\n"),
            ]);
        } catch (Throwable $e) {
            // An outage leaves lessons unmapped and retryable, never guessed.
            Log::error('lesson.mapping_failed', ['error' => $e->getMessage()]);

            return 0;
        }

        $ids = collect($lessons)->pluck('id')->flip();
        $mapped = 0;

        foreach ($result->get('mappings', []) as $row) {
            $lessonId = (string) ($row['lesson_id'] ?? '');
            $topic = $allowed->get((string) ($row['topic_slug'] ?? ''));
            $confidence = (float) ($row['confidence'] ?? 0);

            // The model may not introduce a topic, nor answer for a lesson it
            // was not asked about.
            if (! $ids->has($lessonId) || $topic === null || $confidence < self::FLOOR) {
                continue;
            }

            $this->replaceMapping($lessonId, $topic->getKey(), $confidence, 'ai');
            $mapped++;
        }

        return $mapped;
    }

    private function replaceMapping(string $lessonId, string $topicId, float $confidence, string $by): void
    {
        DB::transaction(function () use ($lessonId, $topicId, $confidence, $by): void {
            DB::table('book_topic_mappings')
                ->where('book_lesson_id', $lessonId)
                ->where('mapped_by', '!=', 'human')
                ->delete();

            DB::table('book_topic_mappings')->insert([
                'book_lesson_id' => $lessonId,
                'canonical_topic_id' => $topicId,
                'confidence' => round($confidence, 4),
                'mapped_by' => $by,
                'created_at' => now(),
            ]);
        });
    }
}
