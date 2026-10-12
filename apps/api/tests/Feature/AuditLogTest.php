<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Review\ReviewWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The audit table was partitioned, append-only and empty for the whole life of
 * the project — which is worse than having none, because it looks like a
 * record exists. These tests pin that it is now actually written, and that the
 * append-only guarantee is real rather than a comment.
 */
final class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        TenantContext::withoutTenancy(function (): void {
            $this->reviewer = User::query()->create([
                'name' => 'Reviewer', 'email' => 'audit-reviewer@example.test',
                'password' => 'secret-pass', 'is_platform_admin' => true,
            ]);

            $org = Organization::query()->create([
                'name' => 'Audit Org', 'slug' => 'audit-org',
                'type' => 'individual', 'owner_user_id' => $this->reviewer->getKey(),
            ]);

            OrganizationUser::query()->create([
                'organization_id' => $org->getKey(),
                'user_id' => $this->reviewer->getKey(),
                'status' => 'active', 'is_default' => true,
            ]);
        });
    }

    private function question(string $status = 'PENDING_REVIEW'): Question
    {
        return TenantContext::withoutTenancy(function () use ($status): Question {
            $question = Question::query()->create([
                'organization_id' => null,
                'master_language_id' => \App\Models\Language::byCode('en')->getKey(),
                'difficulty' => 'medium',
                'status' => $status,
                'origin' => 'ai_generated',
            ]);

            // A database trigger refuses to approve a question that cannot be
            // traced to a source. The fixture has to satisfy the same rule a
            // real question does.
            $source = \App\Models\Source::query()->create([
                'organization_id' => null,
                'kind' => 'textbook',
                'title' => 'Audit Source',
                'language_id' => \App\Models\Language::byCode('en')->getKey(),
                'rights_basis' => 'govt_open',
                'status' => 'ready',
                'storage_disk' => 'local',
                'storage_path' => 'sources/audit.pdf',
                'original_filename' => 'audit.pdf',
                'file_size_bytes' => 1024,
                'content_sha256' => str_repeat('d', 64),
                'mime_type' => 'application/pdf',
            ]);

            \App\Models\QuestionSource::query()->create([
                'question_id' => $question->getKey(),
                'source_id' => $source->getKey(),
                'is_primary' => true,
                'supporting_quote' => 'A quoted line from the source.',
            ]);

            return $question;
        });
    }

    public function test_approving_a_question_is_recorded_with_who_and_from_what(): void
    {
        $question = $this->question();

        TenantContext::withoutTenancy(fn () => app(ReviewWorkflow::class)
            ->transition($question, 'APPROVED', $this->reviewer));

        $log = TenantContext::withoutTenancy(
            fn () => AuditLog::query()->where('action', 'question.approved')->firstOrFail()
        );

        // "How did this question come to be printable?" is the question an
        // audit trail actually gets asked.
        $this->assertSame($this->reviewer->getKey(), $log->actor_user_id);
        $this->assertSame($question->getKey(), $log->auditable_id);
        $this->assertSame('PENDING_REVIEW', $log->changes['from']);
        $this->assertSame('APPROVED', $log->changes['to']);
    }

    public function test_the_log_cannot_be_updated(): void
    {
        $this->approveOne();

        // Enforced by a trigger, not by a privilege: the app role owns these
        // tables and an owner can grant privileges back to itself, so checking
        // has_table_privilege would pass while the guarantee did not hold.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');

        DB::statement("UPDATE audit_logs SET action = 'tampered'");
    }

    public function test_the_log_cannot_be_deleted(): void
    {
        // A separate test because the first refusal aborts the surrounding
        // transaction, so a second attempt in the same one reports only that.
        $this->approveOne();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');

        DB::statement('DELETE FROM audit_logs');
    }

    private function approveOne(): void
    {
        $question = $this->question();

        TenantContext::withoutTenancy(fn () => app(ReviewWorkflow::class)
            ->transition($question, 'APPROVED', $this->reviewer));
    }

    public function test_it_records_only_the_fields_that_actually_changed(): void
    {
        $question = $this->question();

        $before = $question->getAttributes();
        $question->forceFill(['difficulty' => 'hard'])->save();

        $log = TenantContext::withoutTenancy(fn () => app(AuditRecorder::class)
            ->recordChange('question.edited', $question, $before));

        $this->assertNotNull($log);
        // Snapshotting the whole row buries the one field that moved among
        // forty that did not.
        $this->assertSame(['difficulty'], array_keys($log->changes));
        $this->assertSame('medium', $log->changes['difficulty']['old']);
        $this->assertSame('hard', $log->changes['difficulty']['new']);
    }

    public function test_an_unchanged_model_writes_no_entry(): void
    {
        $question = $this->question();

        $log = TenantContext::withoutTenancy(fn () => app(AuditRecorder::class)
            ->recordChange('question.edited', $question, $question->getAttributes()));

        // An entry saying nothing happened only makes the real ones harder to
        // find.
        $this->assertNull($log);
    }

    public function test_secrets_are_never_written_to_the_log(): void
    {
        $log = TenantContext::withoutTenancy(fn () => app(AuditRecorder::class)->record(
            'user.updated',
            null,
            ['email' => 'a@b.test', 'password' => 'hunter2', 'nested' => ['api_key' => 'sk-live-123']],
        ));

        $this->assertSame('a@b.test', $log->changes['email']);
        $this->assertSame('[redacted]', $log->changes['password']);
        $this->assertSame('[redacted]', $log->changes['nested']['api_key']);
    }

    public function test_the_history_of_one_record_can_be_read_back_in_order(): void
    {
        $question = $this->question();

        TenantContext::withoutTenancy(function () use ($question): void {
            $workflow = app(ReviewWorkflow::class);
            $workflow->transition($question, 'APPROVED', $this->reviewer);
            $workflow->transition($question->fresh(), 'REVISION_REQUIRED', $this->reviewer);
        });

        $response = $this->actingAs($this->reviewer)
            ->getJson("/api/v1/audit-logs/subject/{$question->getKey()}")
            ->assertOk();

        $actions = array_column($response->json('data'), 'action');

        $this->assertSame(
            ['question.approved', 'question.revision_required'],
            $actions,
        );
    }
}
