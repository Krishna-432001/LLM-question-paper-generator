<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Plans\GenerateStudyPlanAction;
use App\Actions\Tests\AssembleExamPatternTestAction;
use App\Actions\Tests\AssembleTestAction;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tests\Exceptions\ExamPatternShortfall;
use App\Http\Controllers\Controller;
use App\Http\Requests\Plans\CreateStudyPlanRequest;
use App\Models\StudyPlan;
use App\Models\StudyPlanDay;
use App\Models\StudyPlanItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The five plan modes, one endpoint each.
 *
 * Separate routes rather than a `?mode=` parameter because they are separate
 * pages in the product and separate entitlements in the plan matrix — a Basic
 * subscriber gets Daily and Weekly but not Monthly.
 *
 * Generation is synchronous: the scheduler is deterministic arithmetic over
 * data already in the database, so there is no model call to wait on.
 */
final class StudyPlanController extends Controller
{
    public function daily(CreateStudyPlanRequest $r, GenerateStudyPlanAction $a): JsonResponse
    {
        return $this->generate($r, $a, 'daily');
    }

    public function threeDay(CreateStudyPlanRequest $r, GenerateStudyPlanAction $a): JsonResponse
    {
        return $this->generate($r, $a, 'three_day');
    }

    public function weekly(CreateStudyPlanRequest $r, GenerateStudyPlanAction $a): JsonResponse
    {
        return $this->generate($r, $a, 'weekly');
    }

    public function monthly(CreateStudyPlanRequest $r, GenerateStudyPlanAction $a): JsonResponse
    {
        return $this->generate($r, $a, 'monthly');
    }

    public function modelTest(CreateStudyPlanRequest $r, GenerateStudyPlanAction $a): JsonResponse
    {
        return $this->generate($r, $a, 'model_test');
    }

    /** From the student's start date to the exam, with every kind of test on it. */
    public function examPrep(CreateStudyPlanRequest $r, GenerateStudyPlanAction $a): JsonResponse
    {
        abort_unless($r->filled('exam_date'), 422, 'An exam preparation plan needs the exam date (exam_date).');

        return $this->generate($r, $a, 'exam_prep');
    }

    public function index(Request $request): JsonResponse
    {
        $plans = StudyPlan::query()
            ->where('user_id', app(TenantContext::class)->userId)
            ->when($request->filled('mode'), fn ($q) => $q->where('mode', $request->string('mode')))
            ->latest('created_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $plans->map($this->summary(...))->all()]);
    }

    public function show(string $planId): JsonResponse
    {
        $plan = StudyPlan::query()->with('days.items')->findOrFail($planId);

        return response()->json([
            'data' => $this->summary($plan) + [
                'days' => $plan->days->map(fn (StudyPlanDay $day) => [
                    'id' => $day->getKey(),
                    'test_id' => $day->test_id,
                    'day_number' => $day->day_number,
                    'date' => $day->plan_date->toDateString(),
                    'role' => $day->day_role,
                    'target_minutes' => $day->target_minutes,
                    'completion_pct' => $day->completion_pct,
                    'items' => $day->items->map($this->item(...))->all(),
                ])->all(),
            ],
        ]);
    }

    /** Today's plan — the screen a student actually opens each morning. */
    public function today(string $planId): JsonResponse
    {
        $plan = StudyPlan::query()->findOrFail($planId);

        $day = $plan->days()
            ->with('items')
            ->whereDate('plan_date', now(config('examforge.student_timezone'))->toDateString())
            ->first();

        if ($day === null) {
            return response()->json([
                'data' => null,
                'meta' => ['message' => 'No study day scheduled for today.'],
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $day->getKey(),
                'test_id' => $day->test_id,
                'day_number' => $day->day_number,
                'date' => $day->plan_date->toDateString(),
                'role' => $day->day_role,
                'target_minutes' => $day->target_minutes,
                'items' => $day->items->map($this->item(...))->all(),
            ],
        ]);
    }

    /** Marks one item done and rolls the day's completion forward. */
    public function completeItem(string $planId, string $itemId): JsonResponse
    {
        $plan = StudyPlan::query()->findOrFail($planId);

        $item = StudyPlanItem::query()
            ->whereIn('study_plan_day_id', $plan->days()->select('id'))
            ->findOrFail($itemId);

        $item->forceFill(['completed_at' => now()])->save();

        $day = $item->day;
        $total = $day->items()->count();
        $done = $day->items()->whereNotNull('completed_at')->count();

        $day->forceFill([
            'completion_pct' => $total > 0 ? round($done / $total * 100, 2) : 0,
            'completed_at' => $done === $total ? now() : null,
        ])->save();

        return response()->json([
            'data' => [
                'item_id' => $item->getKey(),
                'day_completion_pct' => $day->completion_pct,
            ],
        ]);
    }

    /**
     * Materialises the test for a plan day. Kept separate from plan creation
     * so a 30-day plan does not assemble 30 papers up front — most of which
     * the student would never open.
     */
    public function dayTest(
        string $planId,
        string $dayId,
        AssembleTestAction $assemble,
        AssembleExamPatternTestAction $mock,
    ): JsonResponse {
        $plan = StudyPlan::query()->findOrFail($planId);
        $day = $plan->days()->with('items')->findOrFail($dayId);

        if ($day->test_id !== null) {
            return response()->json(['data' => ['test_id' => $day->test_id, 'created' => false]]);
        }

        $testItem = $day->items->firstWhere('item_type', 'test');

        abort_if($testItem === null, 404, 'No test is scheduled on this day.');

        $context = app(TenantContext::class);
        $base = [
            'organization_id' => $context->organizationId,
            'user_id' => $context->userId,
            'exam_id' => $plan->exam_id,
            'language_id' => $plan->language_id,
            'study_plan_id' => $plan->getKey(),
            'study_plan_day_id' => $day->getKey(),
            // A question already set on one of this plan's papers is not set
            // again: over a 70-day plan, freshness alone let a third of the
            // mocks' questions repeat earlier daily and weekly tests. Wrong
            // answers come back through the error-recovery test instead.
            'exclude_question_ids' => DB::table('test_questions as tq')
                ->join('tests as t', 't.id', '=', 'tq.test_id')
                ->where('t.study_plan_id', $plan->getKey())
                ->distinct()
                ->pluck('tq.question_id')
                ->map(static fn ($id): string => (string) $id)
                ->all(),
        ];
        $kind = $testItem->test_kind;
        $name = match ($kind) {
            'daily' => 'Daily test',
            'weekly' => 'Weekly test',
            'monthly' => 'Monthly test',
            'three_day' => '3-day test',
            'model' => 'Model examination',
            'mock' => 'Full mock test',
            default => 'test',
        };

        if ($kind === 'mock') {
            // The real paper's pattern, built from whatever the bank holds by
            // then: a plan's mock is never refused for being short.
            try {
                $test = $mock->execute($base + [
                    'type' => 'model',
                    'allow_short' => true,
                    'title' => "Day {$day->day_number} — {$name}",
                ]);
            } catch (ExamPatternShortfall) {
                abort(422, 'The question bank has no approved questions for this plan yet.');
            }
        } else {
            $attributes = $base + [
                'type' => match (true) {
                    in_array($kind, ['daily', 'three_day', 'weekly', 'monthly', 'model'], true) => $kind,
                    $plan->mode === 'model_test' => 'model',
                    in_array($plan->mode, ['daily', 'three_day', 'weekly', 'monthly'], true) => $plan->mode,
                    default => 'daily',
                },
                'question_count' => $testItem->question_count ?? 20,
                'title' => "Day {$day->day_number} — {$name}",
                'duration_minutes' => $testItem->target_minutes,
            ];

            // On the topics this stretch of the plan studied; the whole bank
            // when none of them has questions yet, rather than no test at all.
            $topics = $this->topicsStudied($plan, $day->day_number, $testItem->scope_days);
            $test = $topics === []
                ? $assemble->execute($attributes)
                : $assemble->execute($attributes + ['canonical_topic_ids' => $topics]);

            if ($test->status === 'failed' && $topics !== []) {
                $test->delete();
                $test = $assemble->execute($attributes);
            }

            // Every unused question already set: a repeat is better than no test.
            if ($test->status === 'failed' && $attributes['exclude_question_ids'] !== []) {
                $test->delete();
                $test = $assemble->execute(['exclude_question_ids' => []] + $attributes);
            }
        }

        abort_if(
            $test->status === 'failed',
            422,
            'The question bank has no approved questions for this plan yet.',
        );

        $day->forceFill(['test_id' => $test->getKey()])->save();

        return response()->json([
            'data' => [
                'test_id' => $test->getKey(),
                'created' => true,
                // Fewer questions than the plan asked for: the topics studied
                // hold no more yet. The test is still worth sitting.
                'short_paper' => (int) $test->question_count < (int) ($test->selection_profile['asked'] ?? 0),
            ],
        ], 201);
    }

    private function generate(
        CreateStudyPlanRequest $request,
        GenerateStudyPlanAction $action,
        string $mode,
    ): JsonResponse {
        $context = app(TenantContext::class);

        $plan = $action->execute([
            'organization_id' => $context->organizationId,
            'user_id' => $context->userId,
            'exam_id' => $request->string('exam_id')->toString(),
            'language_id' => $request->string('language_id')->toString(),
            'mode' => $mode,
            'start_date' => $request->input('start_date', now(config('examforge.student_timezone'))->toDateString()),
            'exam_date' => $request->input('exam_date'),
            'hours_per_day' => $request->input('hours_per_day', 4),
            'off_days' => $request->input('off_days', []),
            'first_pass_end' => $request->input('first_pass_end'),
        ]);

        return response()->json(['data' => $this->summary($plan)], 201);
    }

    /** @return array<string, mixed> */
    private function summary(StudyPlan $plan): array
    {
        return [
            'id' => $plan->getKey(),
            'mode' => $plan->mode,
            'status' => $plan->status,
            'start_date' => $plan->start_date->toDateString(),
            'end_date' => $plan->end_date->toDateString(),
            'exam_date' => $plan->exam_date?->toDateString(),
            'hours_per_day' => $plan->hours_per_day,
            'day_count' => $plan->days()->count(),
            'coverage_percent' => $plan->coverage_percent,
            // Surfaced, never hidden: a plan that cannot fit the syllabus says
            // exactly what it left out and why.
            'dropped_topics' => $plan->dropped_topics,
        ];
    }

    /**
     * The topics studied or revised over the $scopeDays days ending on day
     * $dayNumber — what a daily, weekly or monthly test is drawn from. Empty
     * for a test on the whole syllabus.
     *
     * @return list<string>
     */
    private function topicsStudied(StudyPlan $plan, int $dayNumber, ?int $scopeDays): array
    {
        if ($scopeDays === null || $scopeDays <= 0) {
            return [];
        }

        return StudyPlanItem::query()
            ->whereIn('item_type', ['study', 'revise'])
            ->whereNotNull('canonical_topic_id')
            ->whereHas('day', fn ($d) => $d
                ->where('study_plan_id', $plan->getKey())
                ->whereBetween('day_number', [$dayNumber - $scopeDays + 1, $dayNumber]))
            ->distinct()
            ->pluck('canonical_topic_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    /** @return array<string, mixed> */
    private function item(StudyPlanItem $item): array
    {
        return [
            'id' => $item->getKey(),
            'type' => $item->item_type,
            // A test item: daily | weekly | monthly | model | mock.
            'test_kind' => $item->test_kind,
            'topic' => $item->canonicalTopic?->name,
            'exam_topic_id' => $item->exam_topic_id,
            'minutes' => $item->target_minutes,
            'question_count' => $item->question_count,
            'is_revision' => $item->is_repeat_of_item_id !== null,
            'completed_at' => $item->completed_at?->toIso8601String(),
        ];
    }
}
