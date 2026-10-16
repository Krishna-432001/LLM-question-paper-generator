<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Syllabus\SyllabusTextParser;
use App\Domain\Tenancy\TenantContext;
use App\Models\Exam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesTestData;
use Tests\TestCase;

/**
 * A published syllabus is what plans and papers are built against, so the
 * rules that matter are the refusals: nothing live is edited in place, and
 * nothing is published that would leave the selector without sound weights.
 */
final class SyllabusEditorTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

    private User $admin;

    private Exam $exam;

    private const NOTIFICATION = <<<'TEXT'
        SCHEME OF EXAMINATION
        Candidates must answer all questions.

        UNIT I : GENERAL SCIENCE (10 Questions)
        (i) Nature of Universe – Measurement of Physical Quantities (ii) Elements and Compounds, Acids, Bases (iii) Main concepts of Life Science

        UNIT II : INDIAN POLITY (30 Questions)
        Constitution of India; Preamble; Fundamental Rights; Union Executive

        Unit - III Environment and Ecology (10 Questions)
        1. Biodiversity and its
           conservation
        2. Pollution
        TEXT;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\TnpscGroup4Seeder::class);
        $this->seed(\Database\Seeders\TnpscBlueprintSeeder::class);

        $this->admin = $this->makeAdmin();
        $this->exam = TenantContext::withoutTenancy(fn () => Exam::query()->where('code', 'TNPSC_G4')->firstOrFail());
    }

    private function draft(): array
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/v1/exams/{$this->exam->getKey()}/syllabus/drafts")
            ->assertCreated()
            ->json('data');
    }

    private function subjectId(string $name): string
    {
        return TenantContext::withoutTenancy(fn () => DB::table('exam_subjects')->where('name', $name)->value('id'));
    }

    public function test_the_parser_reads_units_counts_and_topics(): void
    {
        $units = (new SyllabusTextParser)->parse(self::NOTIFICATION);

        $this->assertCount(3, $units);
        $this->assertSame('General Science', $units[0]['name']);
        $this->assertSame(10, $units[0]['questions']);
        $this->assertCount(3, $units[0]['topics']);
        $this->assertSame(['Constitution of India', 'Preamble', 'Fundamental Rights', 'Union Executive'], $units[1]['topics']);
        // Numbered lines, one of them wrapped onto a second line.
        $this->assertSame(['Biodiversity and its conservation', 'Pollution'], $units[2]['topics']);
    }

    public function test_a_draft_copies_the_live_tree_and_keeps_its_bank_links(): void
    {
        $draft = $this->draft();

        TenantContext::withoutTenancy(function () use ($draft): void {
            $active = DB::table('syllabus_versions')->where('status', 'active')->value('id');
            $live = DB::table('exam_topics')->where('syllabus_version_id', $active)->pluck('canonical_topic_id')->sort()->values();
            $copy = DB::table('exam_topics')->where('syllabus_version_id', $draft['id'])->pluck('canonical_topic_id')->sort()->values();

            // Same canonical topics: the bank's questions stay reachable.
            $this->assertEquals($live, $copy);
        });

        // One draft at a time.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/exams/{$this->exam->getKey()}/syllabus/drafts")
            ->assertStatus(409);
    }

    public function test_a_published_version_cannot_be_edited_in_place(): void
    {
        $active = TenantContext::withoutTenancy(fn () => DB::table('syllabus_versions')->where('status', 'active')->first());
        $unit = TenantContext::withoutTenancy(fn () => DB::table('exam_units')->where('syllabus_version_id', $active->id)->first());

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/syllabus-versions/{$active->id}/units/{$unit->id}", ['weight' => 0.9])
            ->assertStatus(409);
    }

    public function test_import_compare_and_publish_a_new_syllabus(): void
    {
        $draft = $this->draft();
        $gs = $this->subjectId('General Studies');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/syllabus-versions/{$draft['id']}/import", [
                'exam_subject_id' => $gs,
                'text' => self::NOTIFICATION,
                'replace' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.units', 3)
            ->assertJsonPath('data.weighted_by_counts', true);

        $diff = $this->actingAs($this->admin)
            ->getJson("/api/v1/syllabus-versions/{$draft['id']}/diff")
            ->assertOk()
            ->json('data');

        $this->assertFalse($diff['unchanged']);
        $this->assertNotEmpty($diff['units_removed'], 'Replacing General Studies removes its old units.');
        $this->assertNotEmpty($diff['units_added']);

        // Imported units are scaled to General Studies' share of the paper
        // (37.5%, split 10:30:10), so the whole syllabus still sums to 100%.
        TenantContext::withoutTenancy(function () use ($draft, $gs): void {
            $weights = DB::table('exam_units')->where('syllabus_version_id', $draft['id'])->where('exam_subject_id', $gs)->pluck('weight', 'name');
            $this->assertEqualsWithDelta(0.375 * 30 / 50, (float) $weights['Indian Polity'], 0.0001);
            $this->assertEqualsWithDelta(1.0, DB::table('exam_units')->where('syllabus_version_id', $draft['id'])->sum('weight'), 0.001);
        });

        $this->actingAs($this->admin)
            ->postJson("/api/v1/syllabus-versions/{$draft['id']}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        TenantContext::withoutTenancy(function () use ($draft): void {
            $this->assertSame(1, DB::table('syllabus_versions')->where('status', 'active')->count());
            $this->assertSame('active', DB::table('syllabus_versions')->where('id', $draft['id'])->value('status'));
            $this->assertSame(1, DB::table('syllabus_versions')->where('status', 'superseded')->count());
            $this->assertTrue(DB::table('audit_logs')->where('action', 'syllabus.published')->exists());
        });
    }

    public function test_weights_that_do_not_add_up_are_refused_with_the_reason(): void
    {
        $draft = $this->draft();
        $unit = TenantContext::withoutTenancy(fn () => DB::table('exam_units')->where('syllabus_version_id', $draft['id'])->where('name', 'like', '%Polity%')->first());

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/syllabus-versions/{$draft['id']}/units/{$unit->id}", ['weight' => 0.5])
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/syllabus-versions/{$draft['id']}/publish")
            ->assertStatus(422)
            ->assertJsonPath('detail', fn (string $d): bool => str_contains($d, 'not 100%'));

        // Refused means nothing changed: the old version is still in force.
        $this->assertSame('draft', TenantContext::withoutTenancy(fn () => DB::table('syllabus_versions')->where('id', $draft['id'])->value('status')));
    }

    public function test_a_draft_with_an_empty_unit_is_refused(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/syllabus-versions/{$draft['id']}/units", [
                'exam_subject_id' => $this->subjectId('General Studies'),
                'name' => 'Unit X — Empty',
                'weight' => 0,
            ])
            ->assertCreated();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/syllabus-versions/{$draft['id']}/publish")
            ->assertStatus(422)
            ->assertJsonFragment(['title' => 'Request cannot be processed']);
    }

    public function test_a_sub_topic_sits_under_its_topic_and_survives_the_next_draft(): void
    {
        $draft = $this->draft();
        $parent = TenantContext::withoutTenancy(fn () => DB::table('exam_topics as t')
            ->join('exam_units as u', 'u.id', '=', 't.exam_unit_id')
            ->where('t.syllabus_version_id', $draft['id'])
            ->where('u.name', 'like', '%Polity%')
            ->select('t.id', 't.path', 't.exam_unit_id')
            ->first());

        $sub = $this->actingAs($this->admin)
            ->postJson("/api/v1/syllabus-versions/{$draft['id']}/units/{$parent->exam_unit_id}/topics", [
                'name' => 'Fundamental Duties',
                'parent_id' => $parent->id,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame($parent->id, $sub['parent_id']);
        $this->assertSame(2, $sub['depth']);
        $this->assertStringStartsWith($parent->path.'.', $sub['path']);
        $this->assertFalse(TenantContext::withoutTenancy(fn () => (bool) DB::table('exam_topics')->where('id', $parent->id)->value('is_leaf')),
            'A topic with sub-topics is studied through them.');

        // Published and drafted again: the sub-topic comes along, under the copy of its parent.
        $this->actingAs($this->admin)->postJson("/api/v1/syllabus-versions/{$draft['id']}/publish")->assertOk();
        $next = $this->draft();

        $copied = TenantContext::withoutTenancy(fn () => DB::table('exam_topics as s')
            ->join('exam_topics as p', 'p.id', '=', 's.parent_id')
            ->where('s.syllabus_version_id', $next['id'])
            ->where('s.name', 'Fundamental Duties')
            ->select('s.id', 'p.syllabus_version_id as parent_version', 'p.is_leaf as parent_leaf')
            ->first());

        $this->assertNotNull($copied);
        $this->assertSame($next['id'], $copied->parent_version, 'Pointed at the parent in the new draft, not the old one.');
        $this->assertFalse((bool) $copied->parent_leaf);

        // Removing the only sub-topic makes its parent a leaf again.
        $this->actingAs($this->admin)->deleteJson("/api/v1/syllabus-versions/{$next['id']}/topics/{$copied->id}")->assertSuccessful();
        $this->assertTrue(TenantContext::withoutTenancy(fn () => (bool) DB::table('exam_topics')
            ->where('syllabus_version_id', $next['id'])->where('canonical_topic_id', DB::table('exam_topics')->where('id', $parent->id)->value('canonical_topic_id'))
            ->value('is_leaf')));
    }

    public function test_a_typed_topic_joins_the_existing_canonical_topic(): void
    {
        $draft = $this->draft();
        $unit = TenantContext::withoutTenancy(fn () => DB::table('exam_units')->where('syllabus_version_id', $draft['id'])->where('name', 'like', '%Polity%')->first());
        $before = TenantContext::withoutTenancy(fn () => DB::table('canonical_topics')->count());

        $topic = $this->actingAs($this->admin)
            ->postJson("/api/v1/syllabus-versions/{$draft['id']}/units/{$unit->id}/topics", ['name' => 'Constitution of India'])
            ->assertCreated()
            ->json('data');

        // Re-typing an existing topic must not fork the bank.
        $this->assertSame($before, TenantContext::withoutTenancy(fn () => DB::table('canonical_topics')->count()));
        $this->assertSame(
            TenantContext::withoutTenancy(fn () => DB::table('canonical_topics')->where('slug', 'constitution-of-india')->value('id')),
            $topic['canonical_topic_id'],
        );
    }

    public function test_discarding_a_draft_archives_it(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/syllabus-versions/{$draft['id']}/discard")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        // And a new draft can then be started.
        $this->draft();
    }

    public function test_an_organization_admin_cannot_edit_a_platform_syllabus(): void
    {
        $orgAdmin = $this->makeAdmin('org-admin@example.test', 'organization_admin', platformAdmin: false);

        $this->actingAs($orgAdmin)
            ->postJson("/api/v1/exams/{$this->exam->getKey()}/syllabus/drafts")
            ->assertForbidden();
    }
}
