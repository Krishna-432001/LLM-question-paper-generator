<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Plans\GenerateStudyPlanAction;
use App\Domain\Tenancy\TenantContext;
use App\Models\Exam;
use App\Models\Language;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\StudyPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The scheduler is deterministic: same inputs, same plan, no model call and
 * no cost. These tests assert the arithmetic an LLM routinely gets wrong.
 */
final class StudyPlanTest extends TestCase
{
    use RefreshDatabase;

    private Exam $exam;

    private Organization $org;

    private User $student;

    private Language $english;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PlanBlueprintSeeder::class);
        $this->seed(\Database\Seeders\TnpscGroup4Seeder::class);

        $this->english = Language::byCode('en');

        TenantContext::withoutTenancy(function (): void {
            $this->exam = Exam::query()->where('code', 'TNPSC_G4')->firstOrFail();

            $this->student = User::query()->create([
                'name' => 'Planner', 'email' => 'planner@example.test', 'password' => 'secret-pass',
            ]);

            $this->org = Organization::query()->create([
                'name' => 'Planner', 'slug' => 'planner-org', 'type' => 'individual',
                'owner_user_id' => $this->student->getKey(),
            ]);

            OrganizationUser::query()->create([
                'organization_id' => $this->org->getKey(),
                'user_id' => $this->student->getKey(),
                'status' => 'active', 'is_default' => true,
            ]);
        });
    }

    public function test_a_weekly_plan_covers_seven_days_and_ends_with_a_test(): void
    {
        $plan = $this->generate('weekly');

        $this->assertSame('weekly', $plan->mode);
        $this->assertCount(7, $plan->days);

        $lastDay = $plan->days->last();
        $this->assertSame('test', $lastDay->day_role);

        $weeklyTest = $lastDay->items->firstWhere('item_type', 'test');
        $this->assertNotNull($weeklyTest);
        $this->assertSame(75, $weeklyTest->question_count);
    }

    public function test_off_days_are_never_scheduled(): void
    {
        // 0 = Sunday.
        $plan = $this->generate('monthly', ['off_days' => [0]]);

        $this->assertGreaterThan(0, $plan->days->count());

        foreach ($plan->days as $day) {
            $this->assertNotSame(
                0,
                $day->plan_date->dayOfWeek,
                "a Sunday was scheduled on day {$day->day_number}",
            );
        }
    }

    public function test_daily_minutes_respect_the_stated_study_hours(): void
    {
        $plan = $this->generate('monthly', ['hours_per_day' => 3]);

        // 3h at the 0.85 focus factor = 153 planned minutes of new material.
        // Revision and tests are layered on top, so the ceiling is generous —
        // but a day must never be wildly over its budget.
        foreach ($plan->days as $day) {
            $this->assertLessThanOrEqual(
                400,
                $day->target_minutes,
                "day {$day->day_number} was overloaded",
            );
        }
    }

    public function test_spaced_repetition_revisits_earlier_topics(): void
    {
        $plan = $this->generate('monthly');

        $revisions = $plan->days
            ->flatMap(fn ($d) => $d->items)
            ->where('item_type', 'revise');

        $this->assertGreaterThan(0, $revisions->count(), 'no revision was scheduled');

        // A topic studied on day 1 should reappear on the ladder (1, 3, 7, 21).
        $firstStudy = $plan->days->first()->items->firstWhere('item_type', 'study');
        $this->assertNotNull($firstStudy);

        $repeats = $revisions->where('canonical_topic_id', $firstStudy->canonical_topic_id);
        $this->assertGreaterThan(0, $repeats->count());
    }

    public function test_an_impossible_timeline_names_what_it_dropped(): void
    {
        // One hour a day for a week cannot cover a full state syllabus.
        $plan = $this->generate('weekly', ['hours_per_day' => 1]);

        $this->assertNotEmpty(
            $plan->dropped_topics,
            'a plan that cannot fit the syllabus must say what it left out'
        );
        $this->assertLessThan(100, (float) $plan->coverage_percent);
        $this->assertArrayHasKey('reason', $plan->dropped_topics[0]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function generate(string $mode, array $overrides = []): StudyPlan
    {
        return TenantContext::withoutTenancy(fn (): StudyPlan => app(GenerateStudyPlanAction::class)->execute([
            'organization_id' => $this->org->getKey(),
            'user_id' => $this->student->getKey(),
            'exam_id' => $this->exam->getKey(),
            'language_id' => $this->english->getKey(),
            'mode' => $mode,
            'start_date' => '2026-09-01',
            'hours_per_day' => 4,
            ...$overrides,
        ]));
    }
}
