<?php

declare(strict_types=1);

namespace App\Actions\Plans;

use App\Domain\Plans\DTOs\PlanRequest;
use App\Domain\Plans\PlanScheduler;
use App\Domain\Pyq\PyqAnalyzer;
use App\Models\PlanBlueprint;
use App\Models\StudyPlan;
use App\Models\StudyPlanDay;
use App\Models\StudyPlanItem;
use App\Models\SyllabusVersion;
use App\Models\TopicMastery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class GenerateStudyPlanAction
{
    public function __construct(
        private readonly PlanScheduler $scheduler,
        private readonly PyqAnalyzer $pyq,
    ) {}

    /** @param  array<string, mixed>  $attributes */
    public function execute(array $attributes): StudyPlan
    {
        $blueprint = PlanBlueprint::forMode($attributes['mode']);

        $syllabusVersion = SyllabusVersion::query()
            ->where('exam_version_id', function ($q) use ($attributes): void {
                $q->select('id')->from('exam_versions')
                    ->where('exam_id', $attributes['exam_id'])
                    ->where('status', 'active')
                    ->limit(1);
            })
            ->where('status', 'active')
            ->firstOrFail();

        $start = CarbonImmutable::parse($attributes['start_date'] ?? now());
        $end = $this->endDate($start, $blueprint, $attributes);

        $request = new PlanRequest(
            syllabusVersionId: $syllabusVersion->getKey(),
            startDate: $start->toDateString(),
            endDate: $end->toDateString(),
            hoursPerDay: (float) ($attributes['hours_per_day'] ?? 4),
            offDays: $attributes['off_days'] ?? [],
            masteryByTopic: $this->mastery($attributes),
            // How often and how lately past papers asked each topic. Empty
            // until the papers are analysed, and the scheduler then treats
            // every topic as an even bet.
            predictionByTopic: $this->pyq->weightsFor($attributes['exam_id']),
            testConfig: $blueprint->test_config,
            firstPassEnd: $attributes['first_pass_end'] ?? null,
        );

        $schedule = $this->scheduler->schedule($request);

        return DB::transaction(function () use ($attributes, $blueprint, $syllabusVersion, $start, $end, $schedule): StudyPlan {
            $plan = StudyPlan::query()->create([
                'organization_id' => $attributes['organization_id'],
                'user_id' => $attributes['user_id'],
                'exam_id' => $attributes['exam_id'],
                'syllabus_version_id' => $syllabusVersion->getKey(),
                'plan_blueprint_id' => $blueprint->getKey(),
                'mode' => $blueprint->mode,
                'language_id' => $attributes['language_id'],
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'exam_date' => $attributes['exam_date'] ?? null,
                'hours_per_day' => $attributes['hours_per_day'] ?? 4,
                'off_days' => $attributes['off_days'] ?? [],
                'status' => 'active',
                'coverage_percent' => $schedule['coverage_percent'],
                // Named, never silently omitted.
                'dropped_topics' => $schedule['dropped'],
            ]);

            foreach ($schedule['days'] as $day) {
                $record = StudyPlanDay::query()->create([
                    'study_plan_id' => $plan->getKey(),
                    'day_number' => $day->dayNumber,
                    'plan_date' => $day->date->toDateString(),
                    'day_role' => $day->role,
                    'target_minutes' => $day->totalMinutes(),
                ]);

                foreach ($day->items as $sequence => $item) {
                    StudyPlanItem::query()->create([
                        'study_plan_day_id' => $record->getKey(),
                        'item_type' => $item['type'],
                        'exam_topic_id' => $item['exam_topic_id'] ?? null,
                        'canonical_topic_id' => $item['canonical_topic_id'] ?? null,
                        'target_minutes' => $item['minutes'] ?? 0,
                        'question_count' => $item['question_count'] ?? null,
                        // A test item: which kind of paper, on how many days' topics.
                        'test_kind' => $item['test_kind'] ?? null,
                        'scope_days' => $item['scope_days'] ?? null,
                        'sequence' => $sequence,
                    ]);
                }
            }

            return $plan->fresh(['days.items']);
        });
    }

    /** @param  array<string, mixed>  $attributes */
    private function endDate(CarbonImmutable $start, PlanBlueprint $blueprint, array $attributes): CarbonImmutable
    {
        // A rolling mode (daily, weekly) has a fixed horizon. A plan aimed at
        // an exam date runs to the exam.
        if ($blueprint->horizon_days > 0) {
            return $start->addDays($blueprint->horizon_days - 1);
        }

        return isset($attributes['exam_date'])
            ? CarbonImmutable::parse($attributes['exam_date'])->subDay()
            : $start->addDays(29);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, float>
     */
    private function mastery(array $attributes): array
    {
        return TopicMastery::query()
            ->where('user_id', $attributes['user_id'])
            ->where('exam_id', $attributes['exam_id'])
            ->pluck('mastery', 'canonical_topic_id')
            ->map(static fn ($v): float => (float) $v)
            ->all();
    }
}
