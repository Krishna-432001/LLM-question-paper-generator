<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Tenancy\TenantContext;
use App\Models\CanonicalTopic;
use App\Models\Exam;
use App\Models\Language;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Question;
use App\Models\QuestionExamMapping;
use App\Models\QuestionOption;
use App\Models\QuestionSource;
use App\Models\QuestionTranslation;
use App\Models\Source;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fixtures that satisfy the database's own rules.
 *
 * Written after the same two invariants broke tests three times. Both are
 * correct and worth keeping, and both are easy to forget:
 *
 *   - A question cannot be APPROVED without a source citation. A trigger
 *     enforces it, so a fixture that omits one fails deep inside an unrelated
 *     assertion with a Postgres RAISE.
 *   - Permissions are team-scoped. Assigning a role before setting the team id
 *     grants it against no organisation, and every authorize() then fails
 *     closed — silently, as a 403 that looks like a routing mistake.
 *
 * Encoding them once here means a new test starts from a state the database
 * already considers valid.
 */
trait MakesTestData
{
    /**
     * Distinguishes sources made within one test.
     *
     * Sources are deduplicated on content hash — a real and useful rule — so
     * two helper calls with the same title collide on a unique index. The
     * counter makes each fixture source genuinely distinct rather than
     * weakening the constraint to accommodate the tests.
     */
    private int $fixtureSequence = 0;

    /**
     * An admin who can actually use the API: a user, an organisation, the
     * membership that lets the tenant resolve, and a role assigned within it.
     */
    protected function makeAdmin(
        string $email = 'admin@example.test',
        string $role = 'super_admin',
        bool $platformAdmin = true,
    ): User {
        return TenantContext::withoutTenancy(function () use ($email, $role, $platformAdmin): User {
            $user = User::query()->create([
                'name' => 'Test Admin',
                'email' => $email,
                'password' => 'secret-password',
                'is_platform_admin' => $platformAdmin,
            ]);

            $organization = Organization::query()->create([
                'name' => 'Test Org',
                'slug' => 'test-org-'.substr(md5($email), 0, 8),
                'type' => 'individual',
                'owner_user_id' => $user->getKey(),
            ]);

            // Without this row the tenant never resolves and every request is
            // refused by middleware before it reaches a controller.
            OrganizationUser::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
                'status' => 'active',
                'is_default' => true,
            ]);

            // Team first, then the role. The other order grants nothing.
            app(PermissionRegistrar::class)->setPermissionsTeamId($organization->getKey());
            $user->assignRole($role);

            return $user;
        });
    }

    protected function makeExam(string $code = 'TEST_EXAM'): Exam
    {
        return TenantContext::withoutTenancy(fn (): Exam => Exam::query()->create([
            'organization_id' => null,
            'code' => $code,
            'name' => 'Test Exam',
            'is_active' => true,
        ]));
    }

    protected function makeTopic(string $slug = 'test-topic', string $name = 'Test Topic'): CanonicalTopic
    {
        return TenantContext::withoutTenancy(fn (): CanonicalTopic => CanonicalTopic::query()->create([
            'slug' => $slug,
            'name' => $name,
            'domain' => 'general_studies',
            'path' => $slug,
            'depth' => 0,
        ]));
    }

    /** A stored source with every NOT NULL column the table actually requires. */
    protected function makeSource(string $title = 'Test Source'): Source
    {
        $title .= ' #'.(++$this->fixtureSequence);

        return TenantContext::withoutTenancy(fn (): Source => Source::query()->create([
            'organization_id' => null,
            'kind' => 'textbook',
            'title' => $title,
            'language_id' => Language::byCode('en')->getKey(),
            'rights_basis' => 'govt_open',
            'status' => 'ready',
            'storage_disk' => 'local',
            'storage_path' => 'sources/'.md5($title).'.pdf',
            'original_filename' => 'test.pdf',
            'file_size_bytes' => 1024,
            'content_sha256' => hash('sha256', $title),
            'mime_type' => 'application/pdf',
        ]));
    }

    /**
     * A question the database will accept in any status, citation included.
     *
     * Wording is varied per index on purpose: the selection engine drops
     * near-duplicates, so a fixture of identical stems silently collapses to
     * one question and the test measures nothing.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeQuestion(
        int $index = 0,
        string $status = 'PENDING_REVIEW',
        ?Source $source = null,
        ?Exam $exam = null,
        array $attributes = [],
    ): Question {
        // Resolved BEFORE entering the block, never inside it. withoutTenancy
        // restores the previous bypass state on exit, so a nested call clears
        // it while the outer one is still running and the next insert is
        // refused by row-level security — which reads as a permissions bug
        // rather than a nesting one.
        $source ??= $this->makeSource("Source for question {$index}");

        return TenantContext::withoutTenancy(function () use ($index, $status, $source, $exam, $attributes): Question {
            $english = Language::byCode('en');

            $question = Question::query()->create([
                'organization_id' => null,
                'master_language_id' => $english->getKey(),
                'difficulty' => ['easy', 'medium', 'hard'][$index % 3],
                'status' => $status,
                'origin' => 'ai_generated',
                'quality_score' => 70 + ($index % 25),
                'current_version' => 1,
            ] + $attributes);

            foreach (['A', 'B', 'C', 'D'] as $position => $key) {
                QuestionOption::query()->create([
                    'question_id' => $question->getKey(),
                    'option_key' => $key,
                    'position' => $position + 1,
                    'is_correct' => $key === ['A', 'B', 'C', 'D'][$index % 4],
                ]);
            }

            QuestionTranslation::query()->create([
                'question_id' => $question->getKey(),
                'language_id' => $english->getKey(),
                'is_master' => true,
                // The index is fused into a word ("marker7"), never left as a
                // bare digit: the diversity filter discards tokens of three
                // characters or fewer, so a lone number leaves every stem
                // looking identical.
                'question_text' => sprintf(
                    'Which %s process governs %s within specimen marker%d?',
                    ['thermal', 'chemical', 'optical', 'kinetic', 'magnetic'][$index % 5],
                    ['diffusion', 'refraction', 'oxidation', 'conduction'][$index % 4],
                    $index,
                ),
                'option_a' => "Alpha {$index}", 'option_b' => "Beta {$index}",
                'option_c' => "Gamma {$index}", 'option_d' => "Delta {$index}",
                'translation_status' => 'HUMAN_APPROVED',
            ]);

            // The citation the approval trigger insists on.
            QuestionSource::query()->create([
                'question_id' => $question->getKey(),
                'source_id' => $source->getKey(),
                'is_primary' => true,
                'supporting_quote' => "A quoted line supporting question {$index}.",
            ]);

            if ($exam !== null) {
                // Without a mapping a question is invisible to selection, so a
                // test that builds a paper would silently get an empty one.
                QuestionExamMapping::query()->create([
                    'question_id' => $question->getKey(),
                    'exam_id' => $exam->getKey(),
                    'is_active' => true,
                ]);
            }

            return $question;
        });
    }

    /**
     * @return list<Question>
     */
    protected function makeQuestions(
        int $count,
        string $status = 'PENDING_REVIEW',
        ?Source $source = null,
        ?Exam $exam = null,
    ): array {
        $source ??= $this->makeSource('Shared source');

        return array_map(
            fn (int $i): Question => $this->makeQuestion($i, $status, $source, $exam),
            range(0, $count - 1),
        );
    }
}
