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
 * The paper is divided over its topics first, by each topic's share of the
 * exam, and over difficulty within each topic; only then are questions
 * ranked. Ranking the whole pool and taking the top N put all 75 questions of
 * a weekly test in one Aptitude topic: whichever topic held the most
 * well-scored questions took the paper.
 *
 * Selection is deterministic given the same inputs (the seed among them) and
 * records the exact weights and seed it used on the test, so any paper can be
 * explained after the fact.
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
     * Candidates sampled per topic and difficulty, as a multiple of the
     * topic's quota: enough to rank by quality and to survive the diversity
     * filter, without loading a 9,000-question topic to choose four.
     */
    private const OVERSAMPLE = 4;

    /** The fewest candidates sampled per topic and difficulty. */
    private const MIN_CANDIDATES = 10;

    /** The order difficulties are printed in, within a topic. */
    private const DIFFICULTY_ORDER = ['easy' => 0, 'medium' => 1, 'hard' => 2];

    /**
     * @return array{
     *   questions: Collection<int, Question>,
     *   profile: array<string, float>,
     *   gaps: list<array<string, mixed>>,
     *   seed: int,
     *   fixed_languages: array<string, string>
     * }
     */
    public function select(SelectionSpec $spec): array
    {
        $profile = self::PROFILES[$spec->testType] ?? self::PROFILES['daily'];
        $seed = $spec->seed ?? $this->seedFor($spec);
        $result = ['questions' => collect(), 'profile' => $profile, 'gaps' => [], 'seed' => $seed, 'fixed_languages' => []];

        if ($spec->questionCount <= 0) {
            return $result;
        }

        $groups = $this->languageGroups($spec);
        $members = [];
        $groupOf = [];

        foreach ($groups as $g => $group) {
            foreach ($this->inventory($spec, $group) as $topic => $byDifficulty) {
                $members[$topic] = $byDifficulty;
                $groupOf[$topic] = $g;
            }
        }

        $stock = array_map(static fn (array $byDifficulty): array => array_map('count', $byDifficulty), $members);

        if ($stock === []) {
            return ['gaps' => [$this->paperGap($spec->questionCount, 0)]] + $result;
        }

        $weights = $this->topicWeights(array_keys($stock), $spec, $profile);
        $available = array_map('array_sum', $stock);
        $quotas = $this->apportion($spec->questionCount, $weights, $available, $seed);
        $difficultyTargets = $this->difficultyTargets($spec, array_sum($quotas));
        $cells = $this->splitByDifficulty($quotas, $stock, $difficultyTargets, $seed);

        $recent = $this->recentlySeen($spec);

        /** @var array<string, array<string, list<array{question: Question, score: float}>>> $byCell */
        $byCell = [];

        foreach ($this->candidatePool($members, $quotas, $seed) as $question) {
            $byCell[$this->topicKey($question)][(string) $question->difficulty][] = [
                'question' => $question,
                'score' => $this->score($question, $profile, $spec, $recent),
            ];
        }

        foreach ($byCell as $topic => $byDifficulty) {
            foreach ($byDifficulty as $difficulty => $rows) {
                $byCell[$topic][$difficulty] = $this->ranked($rows);
            }
        }

        $selected = collect();
        $seenTokens = [];
        $taken = array_fill_keys(array_keys($stock), 0);

        // Diversity is enforced DURING the fill, not after it. Filtering
        // afterwards drops questions the quota already counted and leaves the
        // paper short with nothing to replace them.
        foreach ($cells as $topic => $byDifficulty) {
            foreach ($byDifficulty as $difficulty => $needed) {
                $taken[$topic] += $this->fill(collect($byCell[$topic][$difficulty] ?? []), $needed, $selected, $seenTokens);
            }

            // A difficulty the topic could not fill is made up from the same
            // topic before any other topic is asked.
            if ($taken[$topic] < $quotas[$topic]) {
                $taken[$topic] += $this->fill($this->rowsOf($byCell, $topic), $quotas[$topic] - $taken[$topic], $selected, $seenTokens);
            }
        }

        // A topic that ran dry passes its share on to the others, in
        // proportion to their weights, rather than leaving the paper short.
        $open = array_keys(array_filter($byCell, static fn (array $rows): bool => $rows !== []));

        while ($selected->count() < $spec->questionCount && $open !== []) {
            $best = null;

            foreach ($open as $i => $topic) {
                $priority = max($weights[$topic] ?? 0.0, 1e-9) / (($taken[$topic] ?? 0) + 0.5);

                if ($best === null || $priority > $best[0]) {
                    $best = [$priority, $i, $topic];
                }
            }

            if ($this->fill($this->rowsOf($byCell, $best[2]), 1, $selected, $seenTokens) === 1) {
                $taken[$best[2]] = ($taken[$best[2]] ?? 0) + 1;
            } else {
                unset($open[$best[1]]);
            }
        }

        $questions = $this->inPaperOrder($selected, $quotas, $weights);
        $fixed = [];

        foreach ($questions as $question) {
            $language = $groups[$groupOf[$this->topicKey($question)] ?? 0]['fixed'];

            if ($language !== null) {
                $fixed[(string) $question->getKey()] = $language;
            }
        }

        return [
            'questions' => $questions,
            'gaps' => $this->gaps($spec, $questions, $weights, $available, $seed),
            'fixed_languages' => $fixed,
        ] + $result;
    }

    /**
     * The paper's language(s), split off for topics that are always set in
     * one language whatever the paper's. Each group is drawn in its own
     * language(s), and a topic sits in exactly one group.
     *
     * @return list<array{languages: list<string>, topics: list<string>|null, exclude: list<string>, fixed: string|null}>
     */
    private function languageGroups(SelectionSpec $spec): array
    {
        $paper = strtolower($spec->languageId);
        $scope = $spec->canonicalTopicIds === null ? null : array_map('strtolower', $spec->canonicalTopicIds);
        $byLanguage = [];

        foreach ($spec->languageByTopic as $topic => $language) {
            $topic = strtolower((string) $topic);
            $language = strtolower($language);

            if ($language !== $paper && ($scope === null || in_array($topic, $scope, true))) {
                $byLanguage[$language][] = $topic;
            }
        }

        $groups = [[
            'languages' => array_values(array_filter([$paper, $spec->secondaryLanguageId])),
            // The paper's own topic scope is applied by eligible().
            'topics' => null,
            'exclude' => array_merge([], ...array_values($byLanguage)),
            'fixed' => null,
        ]];

        foreach ($byLanguage as $language => $topics) {
            $groups[] = ['languages' => [$language], 'topics' => $topics, 'exclude' => [], 'fixed' => $language];
        }

        return $groups;
    }

    /**
     * Eligible questions of one language group.
     *
     * @param  array{languages: list<string>, topics: list<string>|null, exclude: list<string>, fixed: string|null}  $group
     * @return Builder<Question>
     */
    private function groupQuery(SelectionSpec $spec, array $group): Builder
    {
        $query = $this->eligible($spec)
            ->when($group['topics'] !== null, fn ($q) => $q->whereIn('canonical_topic_id', $group['topics']))
            // NOT IN alone would also drop questions filed under no topic.
            ->when($group['exclude'] !== [], fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('canonical_topic_id')
                ->orWhereNotIn('canonical_topic_id', $group['exclude'])));

        foreach ($group['languages'] as $language) {
            $query->availableIn($language);
        }

        return $query;
    }

    /**
     * Every eligible question of one language group, as just its topic,
     * difficulty and exposure: one pass over the bank, from which both the
     * per-topic counts and the per-topic sample are taken. The eligibility
     * test is the expensive part of the query, so it is paid once.
     *
     * @param  array{languages: list<string>, topics: list<string>|null, exclude: list<string>, fixed: string|null}  $group
     * @return array<string, array<string, list<array{0: int, 1: string}>>> topic => difficulty => [times_served, id]
     */
    private function inventory(SelectionSpec $spec, array $group): array
    {
        $rows = $this->groupQuery($spec, $group)
            ->toBase()
            ->select(['id', 'canonical_topic_id', 'difficulty', 'times_served'])
            ->get();

        $members = [];

        foreach ($rows as $row) {
            $members[strtolower((string) ($row->canonical_topic_id ?? ''))][(string) $row->difficulty][] = [(int) $row->times_served, (string) $row->id];
        }

        return $members;
    }

    /**
     * The candidates the paper is ranked from: a sample of every topic and
     * difficulty, sized to the topic's quota, least served first and then in
     * a seeded order.
     *
     * Each topic is sampled on its own. An unordered LIMIT 2000 over the
     * whole bank held 11 Polity questions beside thousands of Aptitude ones,
     * and missed two Aptitude topics altogether. Least served first is what
     * makes repeated papers rotate through the bank instead of re-serving its
     * best-scored corner.
     *
     * @param  array<string, array<string, list<array{0: int, 1: string}>>>  $members
     * @param  array<string, int>  $quotas
     * @return Collection<int, Question>
     */
    private function candidatePool(array $members, array $quotas, int $seed): Collection
    {
        $ids = [];

        foreach ($members as $topic => $byDifficulty) {
            // A topic with no quota still sends a few, as spares for a topic
            // that runs dry.
            $cap = max(self::MIN_CANDIDATES, self::OVERSAMPLE * ($quotas[$topic] ?? 0));

            foreach ($byDifficulty as $rows) {
                if (count($rows) > $cap) {
                    $keyed = [];

                    foreach ($rows as [$served, $id]) {
                        $keyed[] = [$served, md5($seed.'|'.$id), $id];
                    }

                    sort($keyed);
                    $rows = array_map(static fn (array $row): array => [$row[0], $row[2]], array_slice($keyed, 0, $cap));
                }

                foreach ($rows as [, $id]) {
                    $ids[] = $id;
                }
            }
        }

        if ($ids === []) {
            return collect();
        }

        return Question::query()
            ->whereIn('id', $ids)
            ->with(['canonicalTopic', 'master'])
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
     * How much of the paper each topic deserves: its share of the exam,
     * tilted toward topics the real exam asks often and, for the profiles
     * that care, toward the student's weak topics.
     *
     * A topic the exam does not score (weight 0, Current Events in Group IV)
     * still gets a question when the paper has room for every topic, but no
     * more. With no shares known at all, every topic weighs the same.
     *
     * @param  list<string>  $topics
     * @param  array<string, float>  $profile
     * @return array<string, float>
     */
    private function topicWeights(array $topics, SelectionSpec $spec, array $profile): array
    {
        $shares = array_change_key_case($spec->topicShareByTopic);
        $known = array_filter(
            array_intersect_key($shares, array_flip($topics)),
            static fn ($share): bool => (float) $share > 0,
        );
        // A topic missing from the syllabus weighs what a typical topic does.
        $typical = $known === [] ? 1.0 : array_sum($known) / count($known);
        $weights = [];

        foreach ($topics as $topic) {
            $weight = $known === [] ? 1.0 : (float) ($shares[$topic] ?? $typical);
            $mastery = (float) ($spec->masteryByTopic[$topic] ?? 0.5);
            $prediction = (float) ($spec->predictionByTopic[$topic] ?? 0.0);

            $weights[$topic] = $weight
                * (1 + $profile['personal_gap'] * (1 - 2 * $mastery))
                * (1 + $profile['prediction'] * $prediction);
        }

        return $weights;
    }

    /**
     * Divides $count questions among the topics, never giving a topic more
     * than it holds.
     *
     * Every topic first gets one, when the paper has room for all of them;
     * the rest go by weight (Sainte-Laguë, so a heavy topic does not take
     * more than its share of the rounding). With fewer questions than topics,
     * which topics get one is a seeded draw weighted by share, so successive
     * papers cover different topics instead of always dropping the same ones.
     *
     * @param  array<string, float>  $weights
     * @param  array<string, int>  $caps
     * @return array<string, int>
     */
    private function apportion(int $count, array $weights, array $caps, int $seed): array
    {
        $topics = array_keys(array_filter($caps, static fn (int $cap): bool => $cap > 0));
        $quotas = array_fill_keys($topics, 0);

        if ($topics === [] || $count <= 0) {
            return $quotas;
        }

        $positive = array_filter(array_intersect_key($weights, $quotas), static fn (float $w): bool => $w > 0);
        $floor = ($positive === [] ? 1.0 : min($positive)) / 10;

        if ($count < count($topics)) {
            // Efraimidis-Spirakis: a weighted draw without replacement.
            $keys = [];

            foreach ($topics as $topic) {
                $keys[$topic] = log($this->uniform($seed, (string) $topic)) / max($weights[$topic] ?? 0.0, $floor);
            }

            arsort($keys);

            foreach (array_slice(array_keys($keys), 0, $count) as $topic) {
                $quotas[$topic] = 1;
            }

            return $quotas;
        }

        foreach ($topics as $topic) {
            $quotas[$topic] = 1;
        }

        for ($left = $count - count($topics); $left > 0; $left--) {
            $best = null;
            $bestPriority = -1.0;

            foreach ($topics as $topic) {
                if ($quotas[$topic] >= $caps[$topic]) {
                    continue;
                }

                $priority = max($weights[$topic] ?? 0.0, 1e-9) / ($quotas[$topic] + 0.5);

                // Ties (equal shares) are broken by the seed, not by id order,
                // so the same topic does not always get the odd question.
                if ($priority > $bestPriority * (1 + 1e-9)
                    || (abs($priority - $bestPriority) <= $bestPriority * 1e-9
                        && $this->uniform($seed, (string) $topic) > $this->uniform($seed, (string) $best))) {
                    $best = $topic;
                    $bestPriority = $priority;
                }
            }

            if ($best === null) {
                break;
            }

            $quotas[$best]++;
        }

        return $quotas;
    }

    /**
     * The paper's questions per difficulty, from the blueprint mix. Largest
     * remainder, so the cells add up to the paper exactly.
     *
     * @return array<string, int>
     */
    private function difficultyTargets(SelectionSpec $spec, int $count): array
    {
        $mix = array_filter(
            array_map('floatval', $spec->difficultyMix ?: ['easy' => 0.4, 'medium' => 0.4, 'hard' => 0.2]),
            static fn (float $share): bool => $share > 0,
        );
        $mix = $mix ?: ['easy' => 0.4, 'medium' => 0.4, 'hard' => 0.2];
        $sum = array_sum($mix);
        $targets = [];
        $remainders = [];

        foreach ($mix as $difficulty => $share) {
            $exact = $count * $share / $sum;
            $targets[$difficulty] = (int) floor($exact);
            $remainders[$difficulty] = $exact - floor($exact);
        }

        arsort($remainders);

        foreach (array_slice(array_keys($remainders), 0, $count - array_sum($targets)) as $difficulty) {
            $targets[$difficulty]++;
        }

        return $targets;
    }

    /**
     * Splits each topic's quota over difficulty so that the paper as a whole
     * keeps the blueprint mix: each question goes to the difficulty the paper
     * is still shortest of, among those the topic holds.
     *
     * @param  array<string, int>  $quotas
     * @param  array<string, array<string, int>>  $stock
     * @param  array<string, int>  $targets
     * @return array<string, array<string, int>>
     */
    private function splitByDifficulty(array $quotas, array $stock, array $targets, int $seed): array
    {
        $order = array_keys(array_filter($quotas, static fn (int $q): bool => $q > 0));
        usort($order, fn (string $a, string $b): int => $quotas[$b] <=> $quotas[$a]
            ?: $this->uniform($seed, $a) <=> $this->uniform($seed, $b));

        $wanted = $targets;
        $cells = [];

        foreach ($order as $topic) {
            $left = $stock[$topic];

            for ($i = 0; $i < $quotas[$topic]; $i++) {
                $best = null;
                $bestNeed = 0.0;

                foreach ($left as $difficulty => $n) {
                    if ($n <= 0) {
                        continue;
                    }

                    // The share of its target a difficulty still lacks, not
                    // the raw count: otherwise every hard question waits
                    // until easy and medium are down to its size, and lands
                    // on the last few topics.
                    $target = $targets[$difficulty] ?? 0;
                    $need = $target > 0 ? ($wanted[$difficulty] ?? 0) / $target : -1.0;

                    if ($best === null
                        || $need > $bestNeed + 1e-9
                        || (abs($need - $bestNeed) <= 1e-9
                            && (self::DIFFICULTY_ORDER[$difficulty] ?? 9) < (self::DIFFICULTY_ORDER[$best] ?? 9))) {
                        $best = (string) $difficulty;
                        $bestNeed = $need;
                    }
                }

                if ($best === null) {
                    break;
                }

                $cells[$topic][$best] = ($cells[$topic][$best] ?? 0) + 1;
                $left[$best]--;
                $wanted[$best] = ($wanted[$best] ?? 0) - 1;
            }
        }

        return $cells;
    }

    /**
     * @param  list<array{question: Question, score: float}>  $rows
     * @return list<array{question: Question, score: float}>
     */
    private function ranked(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => $b['score'] <=> $a['score']
            ?: strcmp((string) $a['question']->getKey(), (string) $b['question']->getKey()));

        return $rows;
    }

    /**
     * Every candidate of one topic, best first, whatever its difficulty.
     *
     * @param  array<string, array<string, list<array{question: Question, score: float}>>>  $byCell
     * @return Collection<int, array{question: Question, score: float}>
     */
    private function rowsOf(array $byCell, string $topic): Collection
    {
        return collect($this->ranked(array_merge([], ...array_values($byCell[$topic] ?? []))));
    }

    /**
     * Topic by topic, the larger shares first, and easy to hard within each
     * topic: the way a paper in the exam's pattern reads.
     *
     * @param  Collection<string, Question>  $selected
     * @param  array<string, int>  $quotas
     * @param  array<string, float>  $weights
     * @return Collection<int, Question>
     */
    private function inPaperOrder(Collection $selected, array $quotas, array $weights): Collection
    {
        $topics = array_keys($weights);
        usort($topics, static fn (string $a, string $b): int => ($quotas[$b] ?? 0) <=> ($quotas[$a] ?? 0)
            ?: $weights[$b] <=> $weights[$a]
            ?: strcmp($a, $b));
        $rank = array_flip($topics);

        return $selected->values()
            ->map(fn (Question $q, int $i): array => [$rank[$this->topicKey($q)] ?? PHP_INT_MAX, self::DIFFICULTY_ORDER[$q->difficulty] ?? 9, $i, $q])
            ->sort(static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]])
            ->map(static fn (array $row): Question => $row[3])
            ->values();
    }

    /**
     * Everything the paper could not be given, so it becomes a content work
     * item rather than a silently short or lopsided paper: the paper itself
     * when it is short, each difficulty below the blueprint mix, and each
     * topic holding fewer questions than its fair share of this paper.
     *
     * @param  Collection<int, Question>  $questions
     * @param  array<string, float>  $weights
     * @param  array<string, int>  $available
     * @return list<array<string, mixed>>
     */
    private function gaps(SelectionSpec $spec, Collection $questions, array $weights, array $available, int $seed): array
    {
        $gaps = [];

        if ($questions->count() < $spec->questionCount) {
            $gaps[] = $this->paperGap($spec->questionCount, $questions->count());
        }

        $byDifficulty = $questions->countBy(static fn (Question $q): string => (string) $q->difficulty);
        // Measured against the mix of the paper that was asked for.
        $wanted = $this->difficultyTargets($spec, $spec->questionCount);

        foreach ($wanted as $difficulty => $needed) {
            $got = (int) ($byDifficulty[$difficulty] ?? 0);

            if ($got < $needed) {
                $gaps[] = ['scope' => 'difficulty', 'difficulty' => $difficulty, 'needed' => $needed, 'available' => $got, 'deficit' => $needed - $got];
            }
        }

        $fair = $this->apportion($spec->questionCount, $weights, array_map(static fn (): int => PHP_INT_MAX, $available), $seed);

        foreach ($fair as $topic => $needed) {
            if ($available[$topic] < $needed) {
                $gaps[] = [
                    'scope' => 'topic',
                    'canonical_topic_id' => $topic === '' ? null : $topic,
                    'needed' => $needed,
                    'available' => $available[$topic],
                    'deficit' => $needed - $available[$topic],
                ];
            }
        }

        return $gaps;
    }

    /** @return array{scope: string, needed: int, available: int, deficit: int} */
    private function paperGap(int $needed, int $got): array
    {
        return ['scope' => 'paper', 'needed' => $needed, 'available' => $got, 'deficit' => $needed - $got];
    }

    private function topicKey(Question $question): string
    {
        return strtolower((string) ($question->canonical_topic_id ?? ''));
    }

    /** A seed for a spec that brought none: the same inputs, the same paper. */
    private function seedFor(SelectionSpec $spec): int
    {
        return crc32(json_encode([
            $spec->testType, $spec->languageId, $spec->secondaryLanguageId, $spec->questionCount, $spec->examId,
            $spec->userId, $spec->sourceId, $spec->canonicalTopicIds, $spec->excludeQuestionIds,
        ]) ?: '');
    }

    /** A number in (0, 1), fixed for a seed and a key. */
    private function uniform(int $seed, string $key): float
    {
        return (hexdec(substr(md5($seed.'|'.$key), 0, 12)) + 1) / (16 ** 12 + 1);
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
     * @return int how many were actually taken
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
