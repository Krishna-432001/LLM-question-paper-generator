<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Models\AuditLog;
use App\Models\CanonicalTopic;
use App\Models\Exam;
use App\Models\Language;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\PreviousYearPaper;
use App\Models\PreviousYearQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Coverage, not mechanism: AuditLogTest proves the recorder works, this proves
 * the actions that matter actually call it.
 *
 * The failure mode being guarded against is a new write path shipped without a
 * record — which nobody notices, because the log still has entries in it from
 * everything else.
 */
final class AuditCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Exam $exam;

    private Language $english;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->english = Language::byCode('en');

        TenantContext::withoutTenancy(function (): void {
            $this->admin = User::query()->create([
                'name' => 'Admin', 'email' => 'coverage-admin@example.test',
                'password' => 'secret-pass', 'is_platform_admin' => true,
            ]);

            $org = Organization::query()->create([
                'name' => 'Coverage Org', 'slug' => 'coverage-org',
                'type' => 'individual', 'owner_user_id' => $this->admin->getKey(),
            ]);

            OrganizationUser::query()->create([
                'organization_id' => $org->getKey(),
                'user_id' => $this->admin->getKey(),
                'status' => 'active', 'is_default' => true,
            ]);

            // Permissions are team-scoped, so the team has to be set before a
            // role is assigned or the grant lands against no organisation and
            // every authorize() silently fails closed.
            app(\Spatie\Permission\PermissionRegistrar::class)
                ->setPermissionsTeamId($org->getKey());

            $this->admin->assignRole('super_admin');

            $this->exam = Exam::query()->create([
                'organization_id' => null, 'code' => 'COVERAGE_EXAM',
                'name' => 'Coverage Exam', 'is_active' => true,
            ]);
        });
    }

    private function entry(string $action): ?AuditLog
    {
        return TenantContext::withoutTenancy(
            fn () => AuditLog::query()->where('action', $action)->first()
        );
    }

    public function test_uploading_a_book_records_the_rights_it_was_taken_under(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin)
            ->post('/api/v1/sources', [
                'file' => UploadedFile::fake()->create('reader.pdf', 40, 'application/pdf'),
                'kind' => 'textbook',
                'title' => 'A Reader',
                'language_id' => $this->english->getKey(),
                'rights_basis' => 'govt_open',
            ])
            ->assertCreated();

        $log = $this->entry('source.uploaded');

        $this->assertNotNull($log, 'Uploading a book must be recorded.');
        // The claim about why this material was legal to ingest is asserted by
        // a person at upload time, so it is the field worth keeping.
        $this->assertSame('govt_open', $log->changes['rights_basis']);
        $this->assertSame($this->admin->getKey(), $log->actor_user_id);
    }

    public function test_a_bulk_decision_is_recorded_as_one_human_act(): void
    {
        $source = TenantContext::withoutTenancy(fn () => \App\Models\Source::query()->create([
            'organization_id' => null, 'kind' => 'textbook', 'title' => 'Bulk Source',
            'language_id' => $this->english->getKey(), 'rights_basis' => 'govt_open',
            'status' => 'ready', 'storage_disk' => 'local', 'storage_path' => 'sources/bulk.pdf',
            'original_filename' => 'bulk.pdf', 'file_size_bytes' => 1024,
            'content_sha256' => str_repeat('e', 64), 'mime_type' => 'application/pdf',
        ]));

        $this->actingAs($this->admin)
            ->postJson("/api/v1/sources/{$source->getKey()}/questions/decide", [
                'decision' => 'approve',
            ])
            ->assertOk();

        $log = $this->entry('questions.bulk_decided');

        // Thirty individual transitions and "one person approved the batch"
        // are different facts, and the second is the one asked about later.
        $this->assertNotNull($log, 'A bulk decision must be recorded in its own right.');
        $this->assertSame('approve', $log->changes['decision']);
    }

    public function test_mapping_a_paper_records_what_the_model_decided(): void
    {
        $paper = TenantContext::withoutTenancy(function (): PreviousYearPaper {
            $paper = PreviousYearPaper::query()->create([
                'organization_id' => null, 'exam_id' => $this->exam->getKey(),
                'year' => 2023, 'paper_name' => 'Coverage Paper', 'status' => 'parsed',
            ]);

            CanonicalTopic::query()->create([
                'slug' => 'coverage-topic', 'name' => 'Coverage Topic',
                'domain' => 'general_studies', 'path' => 'coverage-topic', 'depth' => 0,
            ]);

            PreviousYearQuestion::query()->create([
                'paper_id' => $paper->getKey(),
                'question_number' => 1,
                'question_text' => 'A question about the Coverage Topic itself.',
                'question_hash' => PreviousYearQuestion::hashFor('A question about the Coverage Topic itself.'),
            ]);

            return $paper;
        });

        $this->actingAs($this->admin)
            ->postJson("/api/v1/pyqs/{$paper->getKey()}/map")
            ->assertOk();

        $log = $this->entry('pyq.mapped');

        $this->assertNotNull($log, 'Mapping must be recorded.');
        // The model's share is the part a human may want to re-check.
        $this->assertArrayHasKey('lexical', $log->changes);
        $this->assertArrayHasKey('model', $log->changes);
    }

    public function test_changing_a_generation_target_is_attributable(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/generation-targets', [
                'exam_id' => $this->exam->getKey(),
                'language_id' => $this->english->getKey(),
                'target_count' => 250,
            ])
            ->assertCreated();

        $log = $this->entry('generation_target.set');

        // A target decides how much gets generated and therefore how much is
        // spent. Changing one quietly is exactly what must not be possible.
        $this->assertNotNull($log, 'Setting a target must be recorded.');
        $this->assertSame(250, $log->changes['target_count']);
        $this->assertSame($this->admin->getKey(), $log->actor_user_id);
    }

    public function test_entries_from_one_request_share_a_request_id(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin)
            ->withHeader('X-Request-Id', 'req-coverage-1')
            ->post('/api/v1/sources', [
                'file' => UploadedFile::fake()->create('traced.pdf', 40, 'application/pdf'),
                'kind' => 'textbook',
                'title' => 'Traced Reader',
                'language_id' => $this->english->getKey(),
                'rights_basis' => 'owned',
            ])
            ->assertCreated();

        // One admin action can touch many rows. Without a shared id the log is
        // a list of events that cannot be reassembled into what someone did.
        $this->assertSame('req-coverage-1', $this->entry('source.uploaded')?->request_id);
    }
}
