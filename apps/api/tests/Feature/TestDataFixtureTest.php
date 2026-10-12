<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Review\ReviewWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesTestData;
use Tests\TestCase;

/**
 * The fixtures test the fixtures.
 *
 * Every rule encoded in MakesTestData is there because forgetting it broke a
 * test somewhere else, in a way that pointed at the wrong thing: a Postgres
 * RAISE inside an unrelated assertion, or a 403 that reads like a routing
 * mistake. If a helper silently stops satisfying one of those rules, the
 * failure should surface here rather than in whichever suite happens to use it
 * next.
 */
final class TestDataFixtureTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    public function test_a_made_question_can_actually_be_approved(): void
    {
        $admin = $this->makeAdmin();
        $question = $this->makeQuestion();

        // The trigger refuses any question with no citation. This is the exact
        // failure that broke three separate suites before the helper existed.
        TenantContext::withoutTenancy(fn () => app(ReviewWorkflow::class)
            ->transition($question, 'APPROVED', $admin));

        $this->assertSame('APPROVED', $question->fresh()->status);
    }

    public function test_a_made_admin_can_reach_an_authorised_endpoint(): void
    {
        $admin = $this->makeAdmin();

        // Exercises the whole chain at once: user, organisation, membership so
        // the tenant resolves, and a role assigned inside that team so
        // authorize() passes. Any missing link returns 403.
        $this->actingAs($admin)
            ->getJson('/api/v1/dashboard')
            ->assertOk();
    }

    public function test_made_questions_are_distinct_enough_to_survive_selection(): void
    {
        $this->makeQuestions(12, 'APPROVED');

        $stems = TenantContext::withoutTenancy(
            fn () => Question::query()->with('master')->get()
                ->map(fn (Question $q): string => (string) $q->master?->question_text)
                ->all()
        );

        // Identical stems collapse to one under the diversity filter, and a
        // test built on them measures nothing while appearing to pass.
        $this->assertCount(12, $stems);
        $this->assertCount(12, array_unique($stems));
    }

    public function test_questions_can_be_mapped_to_an_exam_for_selection(): void
    {
        $exam = $this->makeExam();
        $this->makeQuestions(5, 'APPROVED', null, $exam);

        $mapped = TenantContext::withoutTenancy(
            fn () => Question::query()->whereHas(
                'examMappings',
                fn ($q) => $q->where('exam_id', $exam->getKey())
            )->count()
        );

        // An unmapped question is invisible to the selection engine, so a
        // paper built from one would come back empty for no obvious reason.
        $this->assertSame(5, $mapped);
    }

    public function test_a_made_source_satisfies_every_required_column(): void
    {
        $source = $this->makeSource('A Reader');

        // The table has nine NOT NULL columns with no default. Missing one
        // fails at insert with a constraint name rather than a useful message.
        $this->assertNotNull($source->content_sha256);
        $this->assertNotNull($source->original_filename);
        $this->assertSame('ready', $source->status);
    }
}
