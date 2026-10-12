<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Domain\Pyq\PyqAnalyzer;
use App\Domain\Tests\DTOs\SelectionSpec;
use App\Domain\Tests\SelectionEngine;
use App\Models\Question;
use App\Models\Test;
use App\Models\TestQuestion;
use App\Models\TopicMastery;
use Illuminate\Support\Facades\DB;

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
        $spec = new SelectionSpec(
            testType: $attributes['type'],
            languageId: $attributes['language_id'],
            questionCount: (int) $attributes['question_count'],
            examId: $attributes['exam_id'] ?? null,
            userId: $attributes['user_id'] ?? null,
            sourceId: $attributes['source_id'] ?? null,
            difficultyMix: $attributes['difficulty_mix'] ?? [],
            canonicalTopicIds: $attributes['canonical_topic_ids'] ?? null,
            excludeQuestionIds: $attributes['exclude_question_ids'] ?? [],
            masteryByTopic: $this->masteryFor($attributes),
            // Every selection profile has reserved a prediction weight since
            // the engine was written, and it has been fed an empty array the
            // whole time. This is what finally supplies it: how often, and
            // how recently, the real exam has asked about each topic.
            predictionByTopic: isset($attributes['exam_id'])
                ? $this->pyq->weightsFor((string) $attributes['exam_id'])
                : [],
            // The other weight that has always defaulted to 0.5. Falling back
            // to a constant says every unit is worth the same share of the
            // paper, which for TNPSC Group IV is wrong by a factor of three
            // between Polity and General Science.
            syllabusWeightByTopic: isset($attributes['exam_id'])
                ? $this->syllabusWeights((string) $attributes['exam_id'])
                : [],
        );

        $selection = $this->engine->select($spec);

        return DB::transaction(function () use ($attributes, $selection, $spec): Test {
            $questions = $selection['questions'];

            $test = Test::query()->create([
                'organization_id' => $attributes['organization_id'] ?? null,
                'created_by' => $attributes['user_id'] ?? null,
                'study_plan_id' => $attributes['study_plan_id'] ?? null,
                'study_plan_day_id' => $attributes['study_plan_day_id'] ?? null,
                'exam_id' => $attributes['exam_id'],
                'type' => $spec->testType,
                'title' => $attributes['title'] ?? 'Practice Test',
                'language_id' => $spec->languageId,
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
                    'question_translation_id' => $question->translationIn($spec->languageId)?->getKey(),
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
