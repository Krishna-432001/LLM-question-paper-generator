<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Models\Exam;
use App\Models\QuestionGenerationTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The blueprint is what tells the platform that Polity is worth three times
 * General Science. Without it both weights defaulted to 0.5 and every paper was
 * balanced by accident rather than by the notification.
 */
final class TnpscBlueprintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\TnpscGroup4Seeder::class);
        $this->seed(\Database\Seeders\TnpscBlueprintSeeder::class);
    }

    public function test_unit_weights_follow_the_mark_distribution(): void
    {
        $weights = TenantContext::withoutTenancy(fn () => DB::table('exam_units')
            ->pluck('weight', 'name')
            ->mapWithKeys(static fn ($w, $n): array => [$n => (float) $w]));

        $polity = $weights->first(fn ($w, $n): bool => str_contains($n, 'Indian Polity'));
        $science = $weights->first(fn ($w, $n): bool => str_contains($n, 'General Science'));
        $aptitude = $weights->first(fn ($w, $n): bool => str_contains($n, 'Aptitude'));

        // 15 marks against 5: three times the share, not an equal slice.
        $this->assertEqualsWithDelta(3.0, $polity / $science, 0.05);

        // Aptitude is the heaviest single unit at 25 marks.
        $this->assertGreaterThan($polity, $aptitude);
    }

    public function test_the_scored_units_cover_the_whole_paper(): void
    {
        $weights = TenantContext::withoutTenancy(fn () => DB::table('exam_units')
            ->pluck('weight', 'name')
            ->mapWithKeys(static fn ($w, $n): array => [$n => (float) $w]));

        // All 200 questions of Paper I: Part A 100, General Studies 75,
        // Aptitude 25. The earlier seed summed to 85 of the 100 Part B
        // questions because the Tamil Nadu heritage unit was missing.
        $this->assertEqualsWithDelta(1.0, $weights->sum(), 0.001);

        $heritage = $weights->first(fn ($w, $n): bool => str_contains($n, 'Socio-Political Movements'));
        $this->assertNotNull($heritage, 'The Tamil Nadu history and heritage unit must exist.');
        $this->assertEqualsWithDelta(20 / 200, $heritage, 0.0001);
    }

    public function test_part_a_is_half_the_paper_and_ordered_in_tamil(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $partA = DB::table('exam_units')->where('name', 'like', 'Part A %')->get();

            $this->assertCount(3, $partA);
            $this->assertEqualsWithDelta(0.5, $partA->sum('weight'), 0.001);

            $tamil = \App\Models\Language::byCode('ta')->getKey();
            $topicIds = DB::table('exam_topics')->whereIn('exam_unit_id', $partA->pluck('id'))->pluck('canonical_topic_id');
            $targets = QuestionGenerationTarget::query()->whereIn('canonical_topic_id', $topicIds)->get();

            // Part A is answered in Tamil, so its questions must be written in
            // Tamil: an English target here would order the wrong language.
            $this->assertGreaterThan(0, $targets->count());
            $this->assertTrue($targets->every(fn ($t): bool => $t->language_id === $tamil));
        });
    }

    public function test_current_events_is_kept_but_neither_weighted_nor_ordered(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $unit = DB::table('exam_units')->where('name', 'like', 'Current Events%')->first();

            $this->assertNotNull($unit, 'Kept, because questions are already filed under it.');
            $this->assertSame(0.0, (float) $unit->weight);

            $topicIds = DB::table('exam_topics')->where('exam_unit_id', $unit->id)->pluck('canonical_topic_id');

            $this->assertSame(
                0,
                QuestionGenerationTarget::query()
                    ->whereIn('canonical_topic_id', $topicIds)
                    ->where('is_active', true)
                    ->count(),
            );
        });
    }

    public function test_reseeding_an_old_syllabus_renames_units_in_place(): void
    {
        TenantContext::withoutTenancy(function (): void {
            // Put one unit back to its pre-correction name, as an existing
            // database would have it.
            $polity = DB::table('exam_units')->where('name', 'Unit IV — Indian Polity')->first();
            DB::table('exam_units')->where('id', $polity->id)->update(['name' => 'Unit V — Indian Polity']);
            $unitsBefore = DB::table('exam_units')->count();

            $this->seed(\Database\Seeders\TnpscGroup4Seeder::class);

            // Same row, new name: topics and mappings stay attached to it.
            $this->assertSame('Unit IV — Indian Polity', DB::table('exam_units')->where('id', $polity->id)->value('name'));
            $this->assertSame($unitsBefore, DB::table('exam_units')->count());
        });
    }

    public function test_it_orders_a_pool_deep_enough_for_more_than_one_paper(): void
    {
        $targets = TenantContext::withoutTenancy(
            fn () => QuestionGenerationTarget::query()->get()
        );

        $this->assertGreaterThan(0, $targets->count());

        // A bank holding exactly one paper's worth means every paper drawn is
        // the same paper, and the exposure penalty has nothing to work with.
        $this->assertGreaterThan(
            100,
            $targets->sum('target_count'),
            'The pool must hold several papers, not one.',
        );

        foreach ($targets as $target) {
            $this->assertGreaterThan(0, $target->target_count);
            $this->assertNotNull($target->canonical_topic_id);
        }
    }

    public function test_a_heavier_unit_is_worked_first(): void
    {
        $priorities = TenantContext::withoutTenancy(fn () => DB::table('question_generation_targets as g')
            ->join('exam_topics as t', 't.canonical_topic_id', '=', 'g.canonical_topic_id')
            ->join('exam_units as u', 'u.id', '=', 't.exam_unit_id')
            ->select('u.name', 'g.priority')
            ->distinct()
            ->get());

        $polity = $priorities->first(fn ($r): bool => str_contains($r->name, 'Indian Polity'));
        $science = $priorities->first(fn ($r): bool => str_contains($r->name, 'General Science'));

        // When the backlog is longer than the content team, the unit worth
        // more marks has to be the one that gets written.
        $this->assertGreaterThan($science->priority, $polity->priority);
    }

    public function test_reseeding_does_not_duplicate_targets(): void
    {
        $before = TenantContext::withoutTenancy(fn () => QuestionGenerationTarget::query()->count());

        $this->seed(\Database\Seeders\TnpscBlueprintSeeder::class);

        // Two rows for one cell would each report a deficit against the same
        // questions and double every order placed.
        $this->assertSame(
            $before,
            TenantContext::withoutTenancy(fn () => QuestionGenerationTarget::query()->count()),
        );
    }

    public function test_every_seeded_target_belongs_to_the_exam(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $exam = Exam::query()->where('code', 'TNPSC_G4')->firstOrFail();

            $this->assertSame(
                0,
                QuestionGenerationTarget::query()->where('exam_id', '!=', $exam->getKey())->count(),
            );
        });
    }
}
