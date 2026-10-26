<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Domain\Pyq\PyqAnalyzer;
use App\Domain\Tests\DTOs\SelectionSpec;
use App\Domain\Tests\Exceptions\NotEnoughQuestions;
use App\Domain\Tests\PaperText;
use App\Domain\Tests\SelectionEngine;
use App\Models\ExamBlueprint;
use App\Models\Language;
use App\Models\Question;
use App\Models\Test;
use App\Models\TestQuestion;
use App\Models\TopicMastery;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Builds a test from the bank. No model call, no cost, sub-second — because
 * every expensive thing was already paid for during ingestion and generation.
 */
final class AssembleTestAction
{
    /**
     * Per exam, read once per assembly: a mock asks for each of its parts.
     *
     * @var array<string, array{weights: array<string, float>, shares: array<string, float>}>
     */
    private array $syllabi = [];

    /** @var array<string, array<string, string>> */
    private array $fixedLanguages = [];

    public function __construct(
        private readonly SelectionEngine $engine,
        private readonly PyqAnalyzer $pyq,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes): Test
    {
        // One seed for the whole paper, recorded on it: a paper can be
        // rebuilt exactly, and the next one asked for differs.
        $attributes['seed'] = (int) ($attributes['seed'] ?? random_int(1, 2_147_483_647));
        $spec = $this->specFor($attributes);

        // A bilingual paper is refused rather than shortened. A student who
        // asks for 20 questions in Tamil and English and silently gets 6 has
        // no way to know the other 14 exist in only one language; the counts
        // in the refusal tell them what to ask for instead. A paper in the
        // exam's pattern has already been checked part by part.
        if ($spec->secondaryLanguageId !== null && ! isset($attributes['sections'])) {
            $available = $this->engine->availability($spec);

            if ($available['both'] < max(1, $spec->questionCount)) {
                throw new NotEnoughQuestions(
                    $this->bilingualShortfall($spec, $available),
                );
            }
        }

        $parts = $this->selectParts($attributes, $spec);
        $questions = collect($parts)->flatMap(static fn (array $part) => $part['questions']->all())->values();

        return DB::transaction(function () use ($attributes, $parts, $questions, $spec): Test {
            // Every translation row, so each paper language can be pinned to
            // the row it is assembled from.
            (new EloquentCollection($questions->all()))->load('translations');

            $test = Test::query()->create([
                'organization_id' => $attributes['organization_id'] ?? null,
                'created_by' => $attributes['user_id'] ?? null,
                'study_plan_id' => $attributes['study_plan_id'] ?? null,
                'study_plan_day_id' => $attributes['study_plan_day_id'] ?? null,
                'exam_id' => $attributes['exam_id'],
                'blueprint_id' => $attributes['blueprint_id'] ?? null,
                'syllabus_version_id' => $attributes['syllabus_version_id'] ?? null,
                'type' => $spec->testType,
                'title' => $attributes['title'] ?? 'Practice Test',
                'language_id' => $spec->languageId,
                'language_mode' => $spec->secondaryLanguageId !== null ? Test::MODE_BILINGUAL : Test::MODE_SINGLE,
                'secondary_language_id' => $spec->secondaryLanguageId,
                'question_count' => $questions->count(),
                'total_marks' => array_sum(array_map(
                    static fn (array $part): float => $part['questions']->count() * $part['marks'],
                    $parts,
                )),
                'duration_minutes' => $attributes['duration_minutes'] ?? null,
                'negative_marking' => $attributes['negative_marking'] ?? 0,
                'difficulty_mix' => $spec->difficultyMix,
                // Recording the weights and the seed makes an assembly
                // reproducible and explainable months later — per part, for a
                // paper in parts. What the paper could not be given is kept
                // too, so a short paper says so instead of passing as whole.
                'selection_profile' => isset($attributes['sections'])
                    ? [
                        'weights' => $parts[0]['profile'] ?? [],
                        'seed' => $attributes['seed'],
                        'asked' => (int) ($attributes['pattern_total'] ?? array_sum(array_column($parts, 'asked'))),
                        'selected' => $questions->count(),
                        'sections' => array_map(static fn (array $part): array => [
                            'name' => $part['name'],
                            'asked' => $part['asked'],
                            'selected' => $part['questions']->count(),
                            'gaps' => $part['gaps'],
                        ], $parts),
                    ]
                    // The weights stay at the top level, where papers
                    // assembled before gaps were recorded keep them.
                    : [
                        ...($parts[0]['profile'] ?? []),
                        'seed' => $attributes['seed'],
                        'asked' => $spec->questionCount,
                        'selected' => $questions->count(),
                        'gaps' => $parts[0]['gaps'] ?? [],
                    ],
                'status' => $questions->isEmpty() ? 'failed' : 'ready',
            ]);

            $sequence = 1;

            foreach ($parts as $part) {
                foreach ($part['questions'] as $question) {
                    // A topic always set in one language (Part A) is printed
                    // in that language alone, on a paper of any language.
                    $fixed = $part['fixed_language_id'] ?? ($part['fixed_languages'][(string) $question->getKey()] ?? null);

                    TestQuestion::query()->create([
                        'test_id' => $test->getKey(),
                        'question_id' => $question->getKey(),
                        // Pinned so a later edit cannot change a paper already
                        // handed out.
                        'question_version' => $question->current_version,
                        // The rows each language is printed from. The runner,
                        // the solutions and every document read these pins, so
                        // what a student answered and what was printed cannot
                        // drift apart when a translation is later revised. A
                        // part set in one language pins that language alone.
                        'question_translation_id' => PaperText::usableTranslation($question, $fixed ?? $part['language_id'])?->getKey(),
                        'secondary_question_translation_id' => $part['secondary_language_id'] === null || $fixed !== null
                            ? null
                            : PaperText::usableTranslation($question, $part['secondary_language_id'])?->getKey(),
                        'fixed_language_id' => $fixed,
                        'sequence' => $sequence++,
                        'section' => $part['name'],
                        'marks' => $part['marks'],
                    ]);
                }
            }

            // Exposure counters drive the freshness penalty on the next
            // assembly; updated in bulk, never per request.
            if ($questions->isNotEmpty()) {
                Question::query()
                    ->whereIn('id', $questions->pluck('id'))
                    ->increment('times_served');
            }

            return $test->fresh(['questions']);
        });
    }

    /**
     * The questions of each part of the paper, in paper order. A plain test
     * is one unnamed part. A paper in the exam's pattern is one part per
     * section: each drawn from its own topics, in its own language when it
     * has one, and never repeating a question an earlier part took.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<array{name: ?string, asked: int, marks: float, language_id: string, secondary_language_id: ?string, fixed_language_id: ?string, questions: Collection<int, Question>, profile: array<string, float>, gaps: list<array<string, mixed>>}>
     */
    private function selectParts(array $attributes, SelectionSpec $spec): array
    {
        if (! isset($attributes['sections'])) {
            $selection = $this->engine->select($spec);
            $fixed = $selection['fixed_languages'];

            return [[
                'name' => null,
                'asked' => $spec->questionCount,
                'marks' => (float) ($attributes['marks_per_question'] ?? 1),
                'language_id' => $spec->languageId,
                'secondary_language_id' => $spec->secondaryLanguageId,
                'fixed_language_id' => null,
                'fixed_languages' => $fixed,
                // Questions set in their own language (Part A) come first, as
                // on the real paper.
                'questions' => $selection['questions']
                    ->sortBy(static fn (Question $q, int $i): int => (isset($fixed[(string) $q->getKey()]) ? 0 : 1_000_000) + $i)
                    ->values(),
                'profile' => $selection['profile'],
                'gaps' => $selection['gaps'],
            ]];
        }

        $parts = [];
        $taken = [];

        foreach ($attributes['sections'] as $section) {
            $fixed = isset($section['language_id']) ? strtolower((string) $section['language_id']) : null;
            $partSpec = $this->specFor([
                ...$attributes,
                'question_count' => (int) $section['question_count'],
                'canonical_topic_ids' => $section['canonical_topic_ids'] ?? null,
                'difficulty_mix' => $section['difficulty_mix'] ?? ($attributes['difficulty_mix'] ?? []),
                'exclude_question_ids' => [...($attributes['exclude_question_ids'] ?? []), ...$taken],
                ...($fixed !== null
                    ? ['language_id' => $fixed, 'language_mode' => Test::MODE_SINGLE, 'secondary_language_id' => null]
                    : []),
            ]);

            // A part whose topics map to nothing in the bank gets nothing —
            // an empty topic list would otherwise mean "any topic".
            $selection = ($section['canonical_topic_ids'] ?? null) === []
                ? ['questions' => collect(), 'profile' => [], 'gaps' => []]
                : $this->engine->select($partSpec);

            $taken = [...$taken, ...$selection['questions']->pluck('id')->all()];

            $parts[] = [
                'name' => (string) $section['name'],
                'asked' => (int) $section['question_count'],
                'marks' => (float) ($section['marks_per_question'] ?? 1),
                'language_id' => $partSpec->languageId,
                'secondary_language_id' => $partSpec->secondaryLanguageId,
                'fixed_language_id' => $fixed,
                'fixed_languages' => [],
                'questions' => $selection['questions'],
                'profile' => $selection['profile'],
                'gaps' => $selection['gaps'],
            ];
        }

        return $parts;
    }

    /**
     * How many eligible questions exist in the requested language(s), before
     * any are chosen. Book-to-paper uses it to size a paper to what can
     * actually be printed in the languages asked for.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{primary: int, secondary: int|null, both: int|null}
     */
    public function availability(array $attributes): array
    {
        return $this->engine->availability($this->specFor($attributes, withWeights: false));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function specFor(array $attributes, bool $withWeights = true): SelectionSpec
    {
        $examId = isset($attributes['exam_id']) ? (string) $attributes['exam_id'] : null;
        $secondary = $this->secondaryLanguageFor($attributes);

        return new SelectionSpec(
            testType: $attributes['type'] ?? 'custom',
            // Lower-cased: PHP compares uuids as strings, Postgres does not,
            // and the two must agree on whether two ids are the same.
            languageId: strtolower((string) $attributes['language_id']),
            questionCount: (int) ($attributes['question_count'] ?? 0),
            examId: $attributes['exam_id'] ?? null,
            userId: $attributes['user_id'] ?? null,
            sourceId: $attributes['source_id'] ?? null,
            difficultyMix: $attributes['difficulty_mix'] ?? [],
            canonicalTopicIds: $attributes['canonical_topic_ids'] ?? null,
            excludeQuestionIds: $attributes['exclude_question_ids'] ?? [],
            masteryByTopic: $withWeights ? $this->masteryFor($attributes) : [],
            // Every selection profile has reserved a prediction weight since
            // the engine was written, and it has been fed an empty array the
            // whole time. This is what finally supplies it: how often, and
            // how recently, the real exam has asked about each topic.
            predictionByTopic: $withWeights && isset($attributes['exam_id'])
                ? $this->pyq->weightsFor((string) $attributes['exam_id'])
                : [],
            // The other weight that has always defaulted to 0.5. Falling back
            // to a constant says every unit is worth the same share of the
            // paper, which for TNPSC Group IV is wrong by a factor of three
            // between Polity and General Science.
            syllabusWeightByTopic: $withWeights && $examId !== null
                ? $this->syllabus($examId)['weights']
                : [],
            secondaryLanguageId: $secondary,
            // What the paper's questions are divided by, topic by topic.
            topicShareByTopic: $withWeights && $examId !== null
                ? $this->syllabus($examId)['shares']
                : [],
            // A plan, topic or custom test follows the exam's language rule
            // as a mock does: an English paper's Part A topics are set in
            // Tamil. A paper in parts carries the rule on each part already,
            // and a book's paper is printed in the language asked for.
            languageByTopic: $withWeights && $examId !== null && $secondary === null
                && ! isset($attributes['sections']) && empty($attributes['source_id'])
                ? $this->fixedLanguageTopics($examId)
                : [],
            seed: isset($attributes['seed']) ? (int) $attributes['seed'] : null,
        );
    }

    /**
     * Topics the exam always sets in one language, whatever the paper's:
     * those of a blueprint part that has its own language (TNPSC Group IV
     * Part A, Tamil). A topic also filed under a part without one is left to
     * the paper's language.
     *
     * @return array<string, string> canonical topic id => language id
     */
    private function fixedLanguageTopics(string $examId): array
    {
        if (isset($this->fixedLanguages[$examId])) {
            return $this->fixedLanguages[$examId];
        }

        // The exam's pattern in force, chosen as the full mock chooses it.
        $blueprint = ExamBlueprint::query()
            ->whereHas('examPaper.examStage.examVersion', static fn ($v) => $v
                ->where('exam_id', $examId)
                ->where('status', 'active'))
            ->with('sections')
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->first();

        $languageOf = $blueprint?->sections
            ->filter(static fn ($s): bool => $s->language_id !== null && $s->exam_subject_id !== null)
            ->mapWithKeys(static fn ($s): array => [strtolower((string) $s->exam_subject_id) => strtolower((string) $s->language_id)])
            ->all() ?? [];

        if ($languageOf === []) {
            return $this->fixedLanguages[$examId] = [];
        }

        $fixed = [];
        $free = [];

        $rows = DB::table('exam_topics as t')
            ->join('exam_units as u', 'u.id', '=', 't.exam_unit_id')
            ->where('t.syllabus_version_id', $blueprint->syllabus_version_id)
            ->whereNotNull('t.canonical_topic_id')
            ->get(['t.canonical_topic_id', 'u.exam_subject_id']);

        foreach ($rows as $row) {
            $topic = strtolower((string) $row->canonical_topic_id);
            $language = $languageOf[strtolower((string) $row->exam_subject_id)] ?? null;

            if ($language !== null) {
                $fixed[$topic] = $language;
            } else {
                $free[$topic] = true;
            }
        }

        return $this->fixedLanguages[$examId] = array_diff_key($fixed, $free);
    }

    /**
     * The second language of a bilingual paper, or null for a single-language
     * one. Requests are validated at the edge, but this action is also called
     * from book-to-paper and study plans, so the rule is enforced here too
     * rather than trusted.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function secondaryLanguageFor(array $attributes): ?string
    {
        if (($attributes['language_mode'] ?? Test::MODE_SINGLE) !== Test::MODE_BILINGUAL) {
            return null;
        }

        $secondary = strtolower((string) ($attributes['secondary_language_id'] ?? ''));

        if ($secondary === '') {
            throw new UnprocessableEntityHttpException('A bilingual paper needs a second language (secondary_language_id).');
        }

        if ($secondary === strtolower((string) $attributes['language_id'])) {
            throw new UnprocessableEntityHttpException("A bilingual paper's second language must differ from its main language.");
        }

        return $secondary;
    }

    /**
     * @param  array{primary: int, secondary: int|null, both: int|null}  $available
     */
    private function bilingualShortfall(SelectionSpec $spec, array $available): string
    {
        $names = Language::query()
            ->whereIn('id', [$spec->languageId, $spec->secondaryLanguageId])
            ->pluck('name', 'id');

        $primary = $names[$spec->languageId] ?? 'the main language';
        $secondary = $names[$spec->secondaryLanguageId] ?? 'the second language';
        $counts = "{$primary}: {$available['primary']}, {$secondary}: {$available['secondary']}, both: {$available['both']}";

        if ($available['both'] === 0) {
            return "No approved questions are available in both {$primary} and {$secondary} yet ({$counts}). "
                .'Choose a single language, or approve translations first.';
        }

        return "Only {$available['both']} approved questions are available in both {$primary} and {$secondary}, "
            ."but {$spec->questionCount} were asked for ({$counts}). "
            ."Ask for {$available['both']} or fewer, or choose a single language.";
    }

    /**
     * Each topic's weight in the paper, taken from its unit's blueprint
     * weight, in the two forms the engine reads.
     *
     * `weights` is the unit's weight normalised against the heaviest unit
     * rather than used raw: the engine multiplies it by a profile weight
     * alongside quality and prediction, and raw shares (0.05..0.25) would make
     * the whole term almost inert.
     *
     * `shares` is the topic's own share of the paper: its unit's weight split
     * evenly over the unit's topics. It is what a paper's questions are
     * divided by, so Aptitude's six topics share Aptitude's 25 of 200 between
     * them rather than taking 25 each.
     *
     * @return array{weights: array<string, float>, shares: array<string, float>}
     */
    private function syllabus(string $examId): array
    {
        return $this->syllabi[$examId] ??= $this->loadSyllabus($examId);
    }

    /** @return array{weights: array<string, float>, shares: array<string, float>} */
    private function loadSyllabus(string $examId): array
    {
        $rows = DB::table('exam_topics as t')
            ->join('exam_units as u', 'u.id', '=', 't.exam_unit_id')
            ->join('syllabus_versions as v', 'v.id', '=', 't.syllabus_version_id')
            // exam_id lives on syllabi, not on the version row.
            ->join('syllabi as s', 's.id', '=', 'v.syllabus_id')
            ->where('s.exam_id', $examId)
            // Only the syllabus in force: a superseded version's weights would
            // otherwise be averaged in with the current ones.
            ->where('v.status', 'active')
            ->whereNotNull('t.canonical_topic_id')
            ->select('t.canonical_topic_id', 't.exam_unit_id', 'u.weight')
            ->get();

        if ($rows->isEmpty()) {
            return ['weights' => [], 'shares' => []];
        }

        $heaviest = max($rows->pluck('weight')->map(static fn ($w): float => (float) $w)->all()) ?: 1.0;
        $topicsInUnit = $rows->groupBy('exam_unit_id')->map(static fn ($unit): int => $unit->pluck('canonical_topic_id')->unique()->count());

        $weights = [];
        $shares = [];
        $counted = [];

        foreach ($rows as $row) {
            $topic = strtolower((string) $row->canonical_topic_id);
            $weights[$topic] = round((float) $row->weight / $heaviest, 4);

            // A topic filed twice under one unit is counted once; a topic
            // under two units takes a share of each.
            if (! isset($counted[$row->exam_unit_id][$topic])) {
                $counted[$row->exam_unit_id][$topic] = true;
                $shares[$topic] = ($shares[$topic] ?? 0.0) + (float) $row->weight / max(1, $topicsInUnit[$row->exam_unit_id]);
            }
        }

        return ['weights' => $weights, 'shares' => $shares];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, float>
     */
    private function masteryFor(array $attributes): array
    {
        if (! isset($attributes['user_id'], $attributes['exam_id'])) {
            return [];
        }

        return TopicMastery::query()
            ->where('user_id', $attributes['user_id'])
            ->where('exam_id', $attributes['exam_id'])
            ->pluck('mastery', 'canonical_topic_id')
            ->map(static fn ($v): float => (float) $v)
            ->all();
    }
}
