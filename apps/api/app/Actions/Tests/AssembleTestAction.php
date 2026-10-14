<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Domain\Pyq\PyqAnalyzer;
use App\Domain\Tests\DTOs\SelectionSpec;
use App\Domain\Tests\PaperText;
use App\Domain\Tests\SelectionEngine;
use App\Models\Language;
use App\Models\Question;
use App\Models\Test;
use App\Models\TestQuestion;
use App\Models\TopicMastery;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use App\Domain\Tests\Exceptions\NotEnoughQuestions;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Builds a test from the bank. No model call, no cost, sub-second — because
 * every expensive thing was already paid for during ingestion and generation.
 */
final class AssembleTestAction
{
    public function __construct(
        private readonly SelectionEngine $engine,
        private readonly PyqAnalyzer $pyq,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes): Test
    {
        $spec = $this->specFor($attributes);

        // A bilingual paper is refused rather than shortened. A student who
        // asks for 20 questions in Tamil and English and silently gets 6 has
        // no way to know the other 14 exist in only one language; the counts
        // in the refusal tell them what to ask for instead.
        if ($spec->secondaryLanguageId !== null) {
            $available = $this->engine->availability($spec);

            if ($available['both'] < max(1, $spec->questionCount)) {
                throw new NotEnoughQuestions(
                    $this->bilingualShortfall($spec, $available),
                );
            }
        }

        $selection = $this->engine->select($spec);

        return DB::transaction(function () use ($attributes, $selection, $spec): Test {
            $questions = $selection['questions'];

            // Every translation row, so each paper language can be pinned to
            // the row it is assembled from.
            (new EloquentCollection($questions->all()))->load('translations');

            $test = Test::query()->create([
                'organization_id' => $attributes['organization_id'] ?? null,
                'created_by' => $attributes['user_id'] ?? null,
                'study_plan_id' => $attributes['study_plan_id'] ?? null,
                'study_plan_day_id' => $attributes['study_plan_day_id'] ?? null,
                'exam_id' => $attributes['exam_id'],
                'type' => $spec->testType,
                'title' => $attributes['title'] ?? 'Practice Test',
                'language_id' => $spec->languageId,
                'language_mode' => $spec->secondaryLanguageId !== null ? Test::MODE_BILINGUAL : Test::MODE_SINGLE,
                'secondary_language_id' => $spec->secondaryLanguageId,
                'question_count' => $questions->count(),
                'total_marks' => $questions->count() * (float) ($attributes['marks_per_question'] ?? 1),
                'duration_minutes' => $attributes['duration_minutes'] ?? null,
                'negative_marking' => $attributes['negative_marking'] ?? 0,
                'difficulty_mix' => $spec->difficultyMix,
                // Recording the weights makes an assembly reproducible and
                // explainable months later.
                'selection_profile' => $selection['profile'],
                'status' => $questions->isEmpty() ? 'failed' : 'ready',
            ]);

            $sequence = 1;

            foreach ($questions as $question) {
                TestQuestion::query()->create([
                    'test_id' => $test->getKey(),
                    'question_id' => $question->getKey(),
                    // Pinned so a later edit cannot change a paper already
                    // handed out.
                    'question_version' => $question->current_version,
                    // The rows each language is printed from. The runner, the
                    // solutions and every document read these pins, so what
                    // a student answered and what was printed cannot drift
                    // apart when a translation is later revised.
                    'question_translation_id' => PaperText::usableTranslation($question, $spec->languageId)?->getKey(),
                    'secondary_question_translation_id' => $spec->secondaryLanguageId === null
                        ? null
                        : PaperText::usableTranslation($question, $spec->secondaryLanguageId)?->getKey(),
                    'sequence' => $sequence++,
                    'marks' => (float) ($attributes['marks_per_question'] ?? 1),
                ]);
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
            syllabusWeightByTopic: $withWeights && isset($attributes['exam_id'])
                ? $this->syllabusWeights((string) $attributes['exam_id'])
                : [],
            secondaryLanguageId: $this->secondaryLanguageFor($attributes),
        );
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
     * Each topic's share of the paper, taken from its unit's blueprint weight.
     *
     * Normalised against the heaviest unit rather than used raw: the engine
     * multiplies this by a profile weight alongside quality and prediction, and
     * raw shares (0.05..0.25) would make the whole term almost inert.
     *
     * @return array<string, float>
     */
    private function syllabusWeights(string $examId): array
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
            ->select('t.canonical_topic_id', 'u.weight')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $heaviest = max($rows->pluck('weight')->map(static fn ($w): float => (float) $w)->all()) ?: 1.0;

        $weights = [];

        foreach ($rows as $row) {
            $weights[(string) $row->canonical_topic_id] = round((float) $row->weight / $heaviest, 4);
        }

        return $weights;
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
