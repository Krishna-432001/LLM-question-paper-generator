<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Models\Exam;
use App\Models\Language;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTestData;
use Tests\TestCase;

final class ExamManagementTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'code' => 'tnpsc_g2',
            'name' => 'TNPSC Group II',
            'conducting_body' => 'Tamil Nadu Public Service Commission',
            'state' => 'TN',
            'language_ids' => [Language::byCode('ta')->getKey(), Language::byCode('en')->getKey()],
            'paper' => ['duration_minutes' => 180, 'total_questions' => 200, 'total_marks' => 300],
            'subjects' => [
                ['name' => 'General Studies', 'question_count' => 175],
                ['name' => 'Aptitude', 'question_count' => 25],
            ],
        ], $overrides);
    }

    public function test_a_platform_admin_creates_a_usable_platform_exam(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/exams', $this->payload(['platform' => true]))
            ->assertCreated()
            ->assertJsonPath('data.code', 'TNPSC_G2')
            ->assertJsonPath('data.is_platform', true)
            ->assertJsonPath('data.active_version.papers.0.total_questions', 200);

        // Subject weights default to their share of the paper's questions.
        $this->assertEqualsWithDelta(0.875, $response->json('data.active_version.papers.0.subjects.0.weight'), 0.0001);

        // Tamil was listed first, so it is the default language.
        $default = collect($response->json('data.active_version.languages'))->firstWhere('is_default', true);
        $this->assertSame('ta', $default['code']);

        TenantContext::withoutTenancy(function () use ($response): void {
            $exam = Exam::query()->findOrFail($response->json('data.id'));
            $this->assertNull($exam->organization_id, 'A platform exam must not be filed under the admin\'s org.');

            // An empty syllabus is created as a draft, never as active.
            $status = DB::table('syllabus_versions as v')
                ->join('syllabi as s', 's.id', '=', 'v.syllabus_id')
                ->where('s.exam_id', $exam->getKey())
                ->value('v.status');
            $this->assertSame('draft', $status);

            $this->assertTrue(DB::table('audit_logs')->where('action', 'exam.created')->exists());
        });
    }

    public function test_an_organization_admin_creates_only_their_own_exams(): void
    {
        $orgAdmin = $this->makeAdmin('org-admin@example.test', 'organization_admin', platformAdmin: false);

        $this->actingAs($orgAdmin)
            ->postJson('/api/v1/exams', $this->payload(['platform' => true]))
            ->assertForbidden();

        $id = $this->actingAs($orgAdmin)
            ->postJson('/api/v1/exams', $this->payload(['code' => 'INSTITUTE_MOCK']))
            ->assertCreated()
            ->assertJsonPath('data.is_platform', false)
            ->json('data.id');

        $exam = TenantContext::withoutTenancy(fn () => Exam::query()->findOrFail($id));
        $this->assertNotNull($exam->organization_id);
    }

    public function test_an_organization_admin_cannot_change_a_platform_exam(): void
    {
        $exam = $this->makeExam('PLATFORM_EXAM');
        $orgAdmin = $this->makeAdmin('org-admin@example.test', 'organization_admin', platformAdmin: false);

        $this->actingAs($orgAdmin)
            ->patchJson("/api/v1/exams/{$exam->getKey()}", ['name' => 'Renamed'])
            ->assertForbidden();

        $this->assertSame('Test Exam', TenantContext::withoutTenancy(fn () => $exam->fresh()->name));
    }

    public function test_a_duplicate_code_is_a_validation_error_not_a_crash(): void
    {
        $admin = $this->makeAdmin();
        $this->makeExam('TNPSC_G2');

        $this->actingAs($admin)
            ->postJson('/api/v1/exams', $this->payload(['platform' => true]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'An exam with code TNPSC_G2 already exists.');
    }

    public function test_editing_details_and_dates_is_recorded(): void
    {
        $admin = $this->makeAdmin();
        $id = $this->actingAs($admin)->postJson('/api/v1/exams', $this->payload(['platform' => true]))->json('data.id');

        $this->actingAs($admin)
            ->patchJson("/api/v1/exams/{$id}", ['name' => 'TNPSC Group II & IIA', 'exam_date' => '2027-02-14', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'TNPSC Group II & IIA')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.active_version.exam_date', '2027-02-14');

        TenantContext::withoutTenancy(function (): void {
            $this->assertTrue(DB::table('audit_logs')->where('action', 'exam.updated')->exists());
            $this->assertTrue(DB::table('audit_logs')->where('action', 'exam_version.updated')->exists());
        });

        // Switched off, it leaves the public catalogue but managers still see it.
        $this->actingAs($admin)->getJson('/api/v1/exams')->assertJsonMissing(['id' => $id]);
        $this->actingAs($admin)->getJson('/api/v1/exams?include_inactive=1')->assertJsonFragment(['id' => $id]);

        // The detail endpoint serves the same shape the edits return.
        $this->actingAs($admin)->getJson("/api/v1/exams/{$id}")->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_a_paper_can_only_be_edited_through_its_own_exam(): void
    {
        $admin = $this->makeAdmin();
        $first = $this->actingAs($admin)->postJson('/api/v1/exams', $this->payload(['platform' => true]))->json('data');
        $second = $this->actingAs($admin)->postJson('/api/v1/exams', $this->payload(['platform' => true, 'code' => 'OTHER']))->json('data');
        $paperId = $first['active_version']['papers'][0]['id'];

        $this->actingAs($admin)
            ->patchJson("/api/v1/exams/{$second['id']}/papers/{$paperId}", ['negative_marking' => 0.33])
            ->assertNotFound();

        $this->actingAs($admin)
            ->patchJson("/api/v1/exams/{$first['id']}/papers/{$paperId}", ['negative_marking' => 0.33, 'duration_minutes' => 150])
            ->assertOk()
            ->assertJsonPath('data.active_version.papers.0.negative_marking', 0.33)
            ->assertJsonPath('data.active_version.papers.0.duration_minutes', 150);
    }

    public function test_the_syllabus_tree_is_readable(): void
    {
        $this->seed(\Database\Seeders\TnpscGroup4Seeder::class);
        $this->seed(\Database\Seeders\TnpscBlueprintSeeder::class);
        $admin = $this->makeAdmin();
        $exam = TenantContext::withoutTenancy(fn () => Exam::query()->where('code', 'TNPSC_G4')->firstOrFail());

        $units = $this->actingAs($admin)
            ->getJson("/api/v1/exams/{$exam->getKey()}/syllabus")
            ->assertOk()
            ->assertJsonPath('data.versions.0.status', 'active')
            ->json('data.units');

        $polity = collect($units)->firstWhere('name', 'Unit IV — Indian Polity');
        $this->assertEqualsWithDelta(15 / 200, $polity['weight'], 0.0001);
        $this->assertCount(4, $polity['topics']);
        $this->assertNotNull($polity['topics'][0]['canonical_slug']);
    }

    public function test_platform_content_uploaded_through_the_api_is_really_shared(): void
    {
        Storage::fake('local');
        $admin = $this->makeAdmin();

        $id = $this->actingAs($admin)
            ->post('/api/v1/sources', [
                'file' => UploadedFile::fake()->create('shared.pdf', 40, 'application/pdf'),
                'kind' => 'textbook',
                'title' => 'Shared Reader',
                'language_id' => Language::byCode('en')->getKey(),
                'rights_basis' => 'govt_open',
                'platform_content' => true,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');

        // Before the fix the creating hook overwrote the null with the
        // admin's own organization, so "platform" uploads were private.
        $source = TenantContext::withoutTenancy(fn () => Source::query()->with('book')->findOrFail($id));
        $this->assertNull($source->organization_id);
        $this->assertNull($source->book->organization_id);
    }

    public function test_only_a_platform_admin_may_publish_platform_content(): void
    {
        Storage::fake('local');
        /** @var User $orgAdmin */
        $orgAdmin = $this->makeAdmin('org-admin@example.test', 'organization_admin', platformAdmin: false);

        $this->actingAs($orgAdmin)
            ->post('/api/v1/sources', [
                'file' => UploadedFile::fake()->create('shared.pdf', 40, 'application/pdf'),
                'kind' => 'textbook',
                'title' => 'Not Mine To Share',
                'language_id' => Language::byCode('en')->getKey(),
                'rights_basis' => 'govt_open',
                'platform_content' => true,
            ], ['Accept' => 'application/json'])
            ->assertForbidden();
    }
}
