<?php

declare(strict_types=1);

namespace App\Domain\Pyq;

use App\Models\TopicFrequency;
use Illuminate\Support\Facades\DB;

/**
 * Turns previous-year papers into a per-topic weight.
 *
 * The question this answers is not "what has been asked" but "what is likely
 * to be asked next", and those differ in one important way: recency. A topic
 * that supplied eight questions a decade ago and none since is not a good bet,
 * while one that has appeared in each of the last three years is. So the score
 * blends how often a topic appears with how recently, rather than ranking on
 * a raw count.
 *
 * The output is deliberately bounded to 0..1 because SelectionEngine multiplies
 * it by a profile weight alongside quality and syllabus coverage. An unbounded
 * count would silently dominate every other consideration.
 *
 * This is prediction, not prophecy. Nothing here is presented to a student as
 * a guarantee, and the analysis is only ever one input to selection.
 */
final class PyqAnalyzer
{
    /**
     * Weight of the frequency half against the recency half. Frequency leads
     * because it is the more stable signal; a single recent appearance should
     * lift a topic but never outrank a decade of consistent ones.
     */
    private const FREQUENCY_WEIGHT = 0.65;

    private const RECENCY_WEIGHT = 0.35;

    /** Beyond this many years since it was last asked, recency contributes nothing. */
    private const RECENCY_HORIZON_YEARS = 10;

    /** Fewer papers than this and any "trend" is noise, so we report stable. */
    private const MIN_YEARS_FOR_TREND = 4;

    /**
     * Rebuilds the frequency table for one exam.
     *
     * @return array{topics: int, questions: int, years: int}
     */
    public function rebuild(string $examId): array
    {
        $rows = DB::table('previous_year_questions as q')
            ->join('previous_year_papers as p', 'p.id', '=', 'q.paper_id')
            ->where('p.exam_id', $examId)
            ->whereNotNull('q.canonical_topic_id')
            ->selectRaw('q.canonical_topic_id, p.year, q.difficulty, q.question_type, count(*) AS asked')
            ->groupBy('q.canonical_topic_id', 'p.year', 'q.difficulty', 'q.question_type')
            ->get();

        $years = DB::table('previous_year_papers')
            ->where('exam_id', $examId)
            ->distinct()
            ->pluck('year')
            ->map(static fn ($y): int => (int) $y)
            ->sort()
            ->values();

        if ($rows->isEmpty() || $years->isEmpty()) {
            return ['topics' => 0, 'questions' => 0, 'years' => $years->count()];
        }

        $byTopic = $this->collate($rows);
        $latestYear = (int) $years->last();
        $maxAsked = max(array_map(static fn (array $t): int => $t['total'], $byTopic)) ?: 1;

        $now = now();
        $written = 0;
        $questions = 0;

        foreach ($byTopic as $topicId => $topic) {
            $questions += $topic['total'];

            TopicFrequency::query()->updateOrCreate(
                ['exam_id' => $examId, 'canonical_topic_id' => $topicId],
                [
                    'total_asked' => $topic['total'],
                    'years_asked' => count($topic['years']),
                    'years_covered' => $years->count(),
                    'first_asked_year' => min(array_keys($topic['years'])),
                    'last_asked_year' => max(array_keys($topic['years'])),
                    'frequency_score' => $this->score($topic, $maxAsked, $latestYear),
                    'trend' => $this->trend($topic, $years->all()),
                    'difficulty_mix' => $topic['difficulty'],
                    'type_mix' => $topic['type'],
                    'computed_at' => $now,
                ],
            );

            $written++;
        }

        return ['topics' => $written, 'questions' => $questions, 'years' => $years->count()];
    }

    /**
     * The prediction weights SelectionEngine consumes, keyed by topic.
     *
     * @return array<string, float>
     */
    public function weightsFor(string $examId): array
    {
        return TopicFrequency::query()
            ->where('exam_id', $examId)
            ->pluck('frequency_score', 'canonical_topic_id')
            ->map(static fn ($v): float => (float) $v)
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<string, array{total: int, years: array<int, int>, difficulty: array<string, int>, type: array<string, int>}>
     */
    private function collate($rows): array
    {
        $byTopic = [];

        foreach ($rows as $row) {
            $id = (string) $row->canonical_topic_id;
            $asked = (int) $row->asked;

            $byTopic[$id] ??= ['total' => 0, 'years' => [], 'difficulty' => [], 'type' => []];
            $byTopic[$id]['total'] += $asked;
            $byTopic[$id]['years'][(int) $row->year] = ($byTopic[$id]['years'][(int) $row->year] ?? 0) + $asked;

            if ($row->difficulty !== null) {
                $byTopic[$id]['difficulty'][$row->difficulty] = ($byTopic[$id]['difficulty'][$row->difficulty] ?? 0) + $asked;
            }

            $type = $row->question_type ?? 'mcq4';
            $byTopic[$id]['type'][$type] = ($byTopic[$id]['type'][$type] ?? 0) + $asked;
        }

        return $byTopic;
    }

    /**
     * @param  array{total: int, years: array<int, int>}  $topic
     */
    private function score(array $topic, int $maxAsked, int $latestYear): float
    {
        // Normalised against the busiest topic rather than an absolute count,
        // so the scale holds whether the corpus is three papers or thirty.
        $frequency = $topic['total'] / $maxAsked;

        $sinceLastAsked = $latestYear - max(array_keys($topic['years']));
        $recency = max(0.0, 1 - ($sinceLastAsked / self::RECENCY_HORIZON_YEARS));

        return round(
            min(1.0, self::FREQUENCY_WEIGHT * $frequency + self::RECENCY_WEIGHT * $recency),
            4,
        );
    }

    /**
     * Compares the recent half of the window against the earlier half.
     *
     * @param  array{years: array<int, int>}  $topic
     * @param  list<int>  $years
     */
    private function trend(array $topic, array $years): string
    {
        if (count($years) < self::MIN_YEARS_FOR_TREND) {
            return 'stable';
        }

        $midpoint = (int) floor(count($years) / 2);
        $earlier = array_slice($years, 0, $midpoint);
        $recent = array_slice($years, $midpoint);

        $earlierCount = $this->askedIn($topic['years'], $earlier);
        $recentCount = $this->askedIn($topic['years'], $recent);

        if ($recentCount === 0 && $earlierCount > 0) {
            return 'dormant';
        }

        // A 25% swing, so ordinary year-to-year variation is not read as a
        // trend a content team would then chase.
        if ($recentCount > $earlierCount * 1.25) {
            return 'rising';
        }

        if ($recentCount * 1.25 < $earlierCount) {
            return 'falling';
        }

        return 'stable';
    }

    /**
     * @param  array<int, int>  $askedByYear
     * @param  list<int>  $years
     */
    private function askedIn(array $askedByYear, array $years): int
    {
        return array_sum(array_map(
            static fn (int $year): int => $askedByYear[$year] ?? 0,
            $years,
        ));
    }
}
