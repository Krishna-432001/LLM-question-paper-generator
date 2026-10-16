<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Models\Exam;
use App\Models\Language;
use App\Models\Source;
use App\Models\StudyPlanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesTestData;
use Tests\TestCase;

/**
 * The plan from the student's own start date to the exam: daily tests on
 * the day's topics, weekly and monthly tests on what those weeks covered,
 * and full mocks in the exam's pattern through the final revision stretch.
 */
final class ExamPrepPlanTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

    private User $student;

    private Exam $exam;

    private Source $source;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PlanBlueprintSeeder::class);
        $this->seed(\Database\Seeders\TnpscGroup4Seeder::class);
        $this->seed(\Database\Seeders\TnpscBlueprintSeeder::class);
        $this->seed(\Database\Seeders\TnpscGroup4PatternSeeder::class);

        $this->student = $this->makeAdmin('prep-student@example.test', 'student', platformAdmin: false);
        $this->exam = TenantContext::withoutTenancy(fn (): Exam => Exam::query()->where('code', 'TNPSC_G4')->firstOrFail());
        $this->source = $this->makeSource('Std 6-10 books');
    }

    /** @return array<string, mixed> the created plan, days and items included */
    private function plan(int $days = 70, float $hours = 3): array
    {
        $start = now()->addDay();

        $id = $this->actingAs($this->student)->postJson('/api/v1/study-plans/exam-prep', [
            'exam_id' => $this->exam->getKey(),
            'language_id' => Language::byCode('en')->getKey(),
            'start_date' => $start->toDateString(),
            'exam_date' => $start->copy()->addDays($days)->toDateString(),
            'hours_per_day' => $hours,
        ])->assertCreated()->json('data.id');

        return $this->actingAs($this->student)->getJson("/api/v1/study-plans/{$id}")->assertOk()->json('data');
    }

    /** @return list<array{day: int, kind: ?string, scope: ?int}> */
    private function tests(array $plan): array
    {
        return TenantContext::withoutTenancy(fn (): array => StudyPlanItem::query()
            ->join('study_plan_days as d', 'd.id', '=', 'study_plan_items.study_plan_day_id')
            ->where('d.study_plan_id', $plan['id'])
            ->where('item_type', 'test')
            ->orderBy('d.day_number')
            ->get(['d.day_number', 'test_kind', 'scope_days'])
            ->map(static fn ($r): array => ['day' => (int) $r->day_number, 'kind' => $r->test_kind, 'scope' => $r->scope_days === null ? null : (int) $r->scope_days])
            ->all());
    }

    /** Approved questions on one canonical topic. */
    private function questionsOn(string $canonicalTopicId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->makeQuestion($this->sequence++, 'APPROVED', $this->source, $this->exam, ['canonical_topic_id' => $canonicalTopicId]);
        }
    }

    public function test_the_plan_runs_from_the_chosen_start_to_the_day_before_the_exam(): void
    {
        $plan = $this->plan(days: 70);

        $this->assertSame('exam_prep', $plan['mode']);
        $this->assertSame(now()->addDay()->toDateString(), $plan['start_date']);
        $this->assertSame(now()->addDays(70)->toDateString(), $plan['end_date'], 'It stops the day before the exam.');
        $this->assertCount(70, $plan['days']);
    }

    public function test_every_kind_of_test_falls_where_it_should_and_covers_what_it_should(): void
    {
        $plan = $this->plan(days: 70);
        $tests = $this->tests($plan);
        $kinds = array_count_values(array_map(static fn (array $t): string => (string) $t['kind'], $tests));

        foreach (['daily', 'weekly', 'monthly', 'mock'] as $kind) {
            $this->assertArrayHasKey($kind, $kinds, "The plan has a {$kind} test.");
        }

        $lastStudyDay = max(array_map(
            static fn (array $day): int => collect($day['items'])->contains('type', 'study') ? $day['day_number'] : 0,
            $plan['days'],
        ));

        foreach ($tests as $test) {
            match ($test['kind']) {
                'daily' => $this->assertSame(1, $test['scope'], 'A daily test is on that day\'s topics.'),
                'weekly' => $this->assertTrue($test['day'] % 7 === 0 && $test['scope'] === 7),
                'monthly' => $this->assertTrue($test['day'] % 30 === 0 && $test['scope'] === 30),
                'mock' => $this->assertTrue($test['day'] > $lastStudyDay && $test['scope'] === null, 'Mocks are in the revision stretch.'),
            };
        }

        // One test a day at most: a cycle or mock day carries nothing else.
        $perDay = array_count_values(array_column($tests, 'day'));
        $this->assertSame(1, max($perDay));
    }

    public function test_an_exam_preparation_plan_needs_the_exam_date(): void
    {
        $this->actingAs($this->student)->postJson('/api/v1/study-plans/exam-prep', [
            'exam_id' => $this->exam->getKey(),
            'language_id' => Language::byCode('en')->getKey(),
        ])->assertStatus(422);
    }

    public function test_a_daily_test_is_drawn_from_the_topics_studied_that_day(): void
    {
        $plan = $this->plan(days: 70);
        $day = collect($plan['days'])->first(fn (array $d): bool => collect($d['items'])->contains('test_kind', 'daily')
            && collect($d['items'])->contains('type', 'study'));

        $studied = TenantContext::withoutTenancy(fn () => StudyPlanItem::query()
            ->where('study_plan_day_id', $day['id'] ?? DB::table('study_plan_days')->where('study_plan_id', $plan['id'])->where('day_number', $day['day_number'])->value('id'))
            ->whereIn('item_type', ['study', 'revise'])
            ->pluck('canonical_topic_id')->filter()->unique()->values()->all());
        $elsewhere = TenantContext::withoutTenancy(fn () => DB::table('exam_topics')
            ->whereNotNull('canonical_topic_id')
            ->whereNotIn('canonical_topic_id', $studied)
            ->value('canonical_topic_id'));

        $this->questionsOn($studied[0], 6);
        $this->questionsOn((string) $elsewhere, 20);

        $dayId = $day['id'] ?? TenantContext::withoutTenancy(fn () => DB::table('study_plan_days')->where('study_plan_id', $plan['id'])->where('day_number', $day['day_number'])->value('id'));
        $testId = $this->actingAs($this->student)
            ->postJson("/api/v1/study-plans/{$plan['id']}/days/{$dayId}/test")
            ->assertCreated()
            ->json('data.test_id');

        $topics = TenantContext::withoutTenancy(fn () => DB::table('test_questions as tq')
            ->join('questions as q', 'q.id', '=', 'tq.question_id')
            ->where('tq.test_id', $testId)
            ->pluck('q.canonical_topic_id')->unique()->values()->all());

        $this->assertSame([$studied[0]], $topics, 'Only the day\'s topics, though another topic has more questions.');
        $this->assertSame('daily', TenantContext::withoutTenancy(fn () => DB::table('tests')->where('id', $testId)->value('type')));
    }

    public function test_a_test_on_topics_with_no_questions_yet_falls_back_to_the_whole_bank(): void
    {
        $plan = $this->plan(days: 70);
        $day = collect($plan['days'])->first(fn (array $d): bool => collect($d['items'])->contains('test_kind', 'daily'));
        $dayId = TenantContext::withoutTenancy(fn () => DB::table('study_plan_days')->where('study_plan_id', $plan['id'])->where('day_number', $day['day_number'])->value('id'));

        // Questions only on a topic the plan never schedules that day.
        $studied = TenantContext::withoutTenancy(fn () => StudyPlanItem::query()->where('study_plan_day_id', $dayId)->pluck('canonical_topic_id')->filter()->all());
        $elsewhere = TenantContext::withoutTenancy(fn () => DB::table('exam_topics')->whereNotNull('canonical_topic_id')->whereNotIn('canonical_topic_id', $studied)->value('canonical_topic_id'));
        $this->questionsOn((string) $elsewhere, 10);

        $this->actingAs($this->student)
            ->postJson("/api/v1/study-plans/{$plan['id']}/days/{$dayId}/test")
            ->assertCreated();

        $this->assertSame(1, TenantContext::withoutTenancy(fn () => DB::table('tests')->count()), 'The empty scoped attempt is not left behind.');
    }

    public function test_a_mock_day_builds_the_paper_in_the_exams_pattern(): void
    {
        $plan = $this->plan(days: 70);
        $day = collect($plan['days'])->first(fn (array $d): bool => collect($d['items'])->contains('test_kind', 'mock'));
        $dayId = TenantContext::withoutTenancy(fn () => DB::table('study_plan_days')->where('study_plan_id', $plan['id'])->where('day_number', $day['day_number'])->value('id'));

        $polity = TenantContext::withoutTenancy(fn () => DB::table('exam_topics as t')
            ->join('exam_units as u', 'u.id', '=', 't.exam_unit_id')
            ->where('u.name', 'like', '%Indian Polity%')
            ->whereNotNull('t.canonical_topic_id')
            ->value('t.canonical_topic_id'));
        $this->questionsOn((string) $polity, 5);

        $testId = $this->actingAs($this->student)
            ->postJson("/api/v1/study-plans/{$plan['id']}/days/{$dayId}/test")
            ->assertCreated()
            ->json('data.test_id');

        $test = $this->actingAs($this->student)->getJson("/api/v1/tests/{$testId}")->assertOk()->json('data');

        $this->assertSame('Part B — General Studies', $test['sections'][0]['name']);
        $this->assertStringContainsString('Full mock test (5 of 200 questions)', $test['title']);
    }
}
