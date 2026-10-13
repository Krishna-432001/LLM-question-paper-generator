<?php

declare(strict_types=1);

namespace App\Domain\Tests;

use App\Domain\Tests\DTOs\SelectionSpec;
use App\Models\Question;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Assembling a test is a constrained selection problem, never ORDER BY RANDOM().
 *
 * A random draw from an approved bank produces papers that are lopsided by
 * topic, wrong on difficulty, repeat what the student saw yesterday, and
 * over-serve the same few hundred questions until they leak. Each of those is
 * a constraint here.
 *
 * Selection is deterministic given the same inputs and records the exact
 * weights it used on the test, so any paper can be explained after the fact.
 */
final class SelectionEngine
{
    /**
     * Per test type: what matters when choosing between two eligible questions.
     * A daily test leans on the student's weak topics; a model paper leans on
     * faithful syllabus coverage and ignores personalisation entirely.
     *
     * @var array<string, array<string, float>>
     */
    private const PROFILES = [
        'daily' => ['quality' => 0.40, 'prediction' => 0.05, 'syllabus' => 0.20, 'freshness' => 0.15, 'personal_gap' => 0.20],
        'three_day' => ['quality' => 0.38, 'prediction' => 0.07, 'syllabus' => 0.22, 'freshness' => 0.15, 'personal_gap' => 0.18],
        'weekly' => ['quality' => 0.35, 'prediction' => 0.10, 'syllabus' => 0.25, 'freshness' => 0.15, 'personal_gap' => 0.15],
        'monthly' => ['quality' => 0.35, 'prediction' => 0.10, 'syllabus' => 0.30, 'freshness' => 0.15, 'personal_gap' => 0.10],
        'model' => ['quality' => 0.45, 'prediction' => 0.15, 'syllabus' => 0.30, 'freshness' => 0.10, 'personal_gap' => 0.00],
        'prediction' => ['quality' => 0.30, 'prediction' => 0.50, 'syllabus' => 0.10, 'freshness' => 0.10, 'personal_gap' => 0.00],
        'topic_practice' => ['quality' => 0.45, 'prediction' => 0.00, 'syllabus' => 0.10, 'freshness' => 0.15, 'personal_gap' => 0.30],
        'diagnostic' => ['quality' => 0.40, 'prediction' => 0.00, 'syllabus' => 0.40, 'freshness' => 0.20, 'personal_gap' => 0.00],
    ];

    /** Questions this student saw within this window are deprioritised hard. */
    private const RECENCY_DAYS = 14;

    /** Jaccard similarity at or above which two stems are treated as the same question. */
    private const DIVERSITY_THRESHOLD = 0.85;

    /** Stems shorter than this are not compared: too little signal to judge. */
    private const MIN_TOKENS_FOR_DIVERSITY = 5;

    /**
     * @return array{
     *   questions: Collection<int, Question>,
     *   profile: array<string, float>,
     *   gaps: list<array<string, mixed>>
     * }
     */
    public function select(SelectionSpec $spec): array
    {
        $profile = self::PROFILES[$spec->testType] ?? self::PROFILES['daily'];

        $pool = $this->candidatePool($spec);
        $quotas = $this->decomposeQuotas($spec);
        $recent = $this->recentlySeen($spec);

        $scored = $pool
            ->map(fn (Question $q) => [
                'question' => $q,
                'score' => $this->score($q, $profile, $spec, $recent),
            ])
            ->sortByDesc('score')
            ->values();

        $selected = collect();
        $seenTokens = [];
        $gaps = [];

        // Diversity is enforced DURING the fill, not after it. Filtering
        // afterwards drops questions the quota already counted and leaves the
        // paper short with nothing to replace them.
        foreach ($quotas as $cell => $needed) {
            [$difficulty] = explode('|', $cell);

            $taken = $this->fill(
                $scored->filter(fn (array $r): bool => $r['question']->difficulty === $difficulty),
                $needed,
                $selected,
                $seenTokens,
            );

            if ($taken < $needed) {
                // A shortfall is never papered over silently: it is recorded
                // so it becomes a content work item.
                $gaps[] = [
                    'difficulty' => $difficulty,
                    'needed' => $needed,
                    'available' => $taken,
                    'deficit' => $needed - $taken,
                ];
            }
        }

        // Backfill from any difficulty rather than shipping a short paper.
        if ($selected->count() < $spec->questionCount) {
            $this->fill($scored, $spec->questionCount - $selected->count(), $selected, $seenTokens);
        }

        return [
            'questions' => $selected->values(),
            'profile' => $profile,
            'gaps' => $gaps,
        ];
    }

    /**
     * Eligible questions only: approved, mapped to this exam, and actually
     * available in the requested language. A paper that cannot be rendered in
     * the language the student asked for is not a paper — and a bilingual
     * paper needs every question in both of its languages, or it is a
     * single-language paper with gaps.
     *
     * @return Collection<int, Question>
     */
    private function candidatePool(SelectionSpec $spec): Collection
    {
        return $this->eligible($spec)
            ->availableIn($spec->languageId)
            ->when($spec->secondaryLanguageId, fn ($q, string $secondary) => $q->availableIn($secondary))
            ->with('canonicalTopic')
            ->limit(2000)
            ->get();
    }

    /**
     * How many eligible questions exist in each of the paper's languages and
     * in both together, before any are chosen.
     *
     * This is what a refusal quotes. "Not enough questions" leaves a student
     * guessing; "40 in English, 12 in Tamil, 12 in both" tells them to ask
     * for 12 or to drop to one language.
     *
     * @return array{primary: int, secondary: int|null, both: int|null}
     */
    public function availability(SelectionSpec $spec): array
    {
        $primary = $this->eligible($spec)->availableIn($spec->languageId)->count();

        if ($spec->secondaryLanguageId === null) {
            return ['primary' => $primary, 'secondary' => null, 'both' => null];
        }

        return [
            'primary' => $primary,
            'secondary' => $this->eligible($spec)->availableIn($spec->secondaryLanguageId)->count(),
            'both' => $this->eligible($spec)
                ->availableIn($spec->languageId)
                ->availableIn($spec->secondaryLanguageId)
                ->count(),
        ];
    }

    /**
     * Everything that makes a question eligible except its language, so the
     * pool and the availability counts can never disagree about the rest.
     *
     * @return Builder<Question>
     */
    private function eligible(SelectionSpec $spec): Builder
    {
        return Question::query()
            ->approved()
            ->when($spec->examId, fn ($q) => $q->whereHas(
                'examMappings',
                fn ($m) => $m->where('exam_id', $spec->examId)->where('is_active', true),
            ))
            ->when($spec->canonicalTopicIds, fn ($q) => $q->whereIn('canonical_topic_id', $spec->canonicalTopicIds))
            // Scoping to a book is what lets an admin produce a paper for the
            // chapter they just ingested, rather than the whole bank.
            ->when($spec->sourceId, fn ($q) => $q->whereHas(
                'sources',
                fn ($s) => $s->where('source_id', $spec->sourceId),
            ))
            ->when($spec->excludeQuestionIds, fn ($q) => $q->whereNotIn('id', $spec->excludeQuestionIds));
    }

    /**
     * Splits the requested count into difficulty cells using the blueprint
     * mix, with the rounding remainder going to the largest cell.
     *
     * @return array<string, int>
     */
    private function decomposeQuotas(SelectionSpec $spec): array
    {
        $mix = $spec->difficultyMix ?: ['easy' => 0.4, 'medium' => 0.4, 'hard' => 0.2];
        $quotas = [];
        $assigned = 0;

        foreach ($mix as $difficulty => $share) {
            $count = (int) floor($spec->questionCount * (float) $share);
            $quotas[$difficulty.'|any'] = $count;
            $assigned += $count;
        }

        if ($assigned < $spec->questionCount && $quotas !== []) {
            $largest = array_search(max($quotas), $quotas, true);
            $quotas[$largest] += $spec->questionCount - $assigned;
        }

        arsort($quotas);

        return $quotas;
    }

    /**
     * @param  array<string, float>  $profile
     * @param  array<string, int>  $recent  question id => days since seen
     */
    private function score(Question $question, array $profile, SelectionSpec $spec, array $recent): float
    {
        $quality = ((float) ($question->quality_score ?? 50)) / 100;

        // Freshness penalises platform-wide over-exposure: a question served
        // 20,000 times has leaked into coaching-centre notes.
        $served = (int) $question->times_served;
        $freshness = 1 / (1 + log10(1 + $served));

        // A topic the student is weak at is worth more to them than one they
        // have mastered.
        $mastery = $spec->masteryByTopic[$question->canonical_topic_id] ?? 0.5;
        $personalGap = 1 - $mastery;

        $prediction = $spec->predictionByTopic[$question->canonical_topic_id] ?? 0.0;
        $syllabus = $spec->syllabusWeightByTopic[$question->canonical_topic_id] ?? 0.5;

        $score = $profile['quality'] * $quality
            + $profile['prediction'] * $prediction
            + $profile['syllabus'] * $syllabus
            + $profile['freshness'] * $freshness
            + $profile['personal_gap'] * $personalGap;

        // Seeing the same question twice in a fortnight teaches recall of the
        // paper, not of the subject.
        if (isset($recent[$question->getKey()])) {
            $score -= 0.5 * (1 - $recent[$question->getKey()] / self::RECENCY_DAYS);
        }

        return round($score, 6);
    }

    /**
     * Takes up to $needed diverse questions from $candidates into $selected.
     *
     * @param  Collection<int, array{question: Question, score: float}>  $candidates
     * @param  Collection<string, Question>  $selected
     * @param  list<list<string>>  $seenTokens
     * @return int  how many were actually taken
     */
    private function fill(
        Collection $candidates,
        int $needed,
        Collection $selected,
        array &$seenTokens,
    ): int {
        $taken = 0;

        foreach ($candidates as $row) {
            if ($taken >= $needed) {
                break;
            }

            $question = $row['question'];

            if ($selected->has($question->getKey())) {
                continue;
            }

            $tokens = $this->significantTokens($question);

            if ($this->isNearDuplicate($tokens, $seenTokens)) {
                continue;
            }

            $selected->put($question->getKey(), $question);
            $seenTokens[] = $tokens;
            $taken++;
        }

        return $taken;
    }

    /**
     * Two questions testing the same fact make one of them wasted paper space.
     *
     * Jaccard, not overlap/|tokens|: the latter rejects any two questions that
     * share a topic vocabulary, which on a single-topic paper is all of them.
     * A short stem is not compared at all — a handful of shared words proves
     * nothing.
     *
     * @param  list<string>  $tokens
     * @param  list<list<string>>  $seenTokens
     */
    private function isNearDuplicate(array $tokens, array $seenTokens): bool
    {
        if (count($tokens) < self::MIN_TOKENS_FOR_DIVERSITY) {
            return false;
        }

        foreach ($seenTokens as $existing) {
            $union = count(array_unique([...$tokens, ...$existing]));
            $intersection = count(array_intersect($tokens, $existing));

            if ($union > 0 && $intersection / $union >= self::DIVERSITY_THRESHOLD) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stem plus options: two questions on the same topic share topic words but
     * differ in what they actually ask and offer.
     *
     * @return list<string>
     */
    private function significantTokens(Question $question): array
    {
        $master = $question->master;
        $text = mb_strtolower(
            (string) $master?->question_text.' '.implode(' ', $master?->optionMap() ?? [])
        );
        $tokens = preg_split('/\W+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $tokens,
            static fn (string $t): bool => mb_strlen($t) > 3,
        )));
    }

    /** @return array<string, int> question id => days since the student last saw it */
    private function recentlySeen(SelectionSpec $spec): array
    {
        if ($spec->userId === null) {
            return [];
        }

        $rows = DB::table('test_answers as a')
            ->join('test_attempts as t', 't.id', '=', 'a.test_attempt_id')
            ->where('t.user_id', $spec->userId)
            ->where('a.answered_at', '>=', now()->subDays(self::RECENCY_DAYS))
            ->selectRaw('a.question_id, max(a.answered_at) AS seen_at')
            ->groupBy('a.question_id')
            ->get();

        $seen = [];

        foreach ($rows as $row) {
            $seen[$row->question_id] = (int) max(
                0,
                now()->diffInDays($row->seen_at, absolute: true),
            );
        }

        return $seen;
    }
}
