<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Models\CanonicalTopic;
use App\Models\Exam;
use App\Models\Language;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesTestData;
use Tests\TestCase;

/**
 * A student's whole loop through the API, as the student app drives it:
 * choose an exam, take a test, come back to it, see the result, see progress.
 * And the privacy rule that comes with it: none of it is visible to another
 * student.
 */
final class StudentJourneyTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

    private User $student;

    private Exam $exam;

    private CanonicalTopic $topic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $this->student = $this->makeAdmin('student@example.test', 'student', platformAdmin: false);
        $this->exam = $this->makeExam('STUDENT_EXAM');
        $this->topic = $this->makeTopic('circulation', 'Circulation');

        $source = $this->makeSource('Biology');

        foreach (range(1, 8) as $i) {
            $this->makeQuestion($i, 'APPROVED', $source, $this->exam, [
                'difficulty' => 'easy',
                'canonical_topic_id' => $this->topic->getKey(),
            ]);
        }
    }

    private function english(): string
    {
        return Language::byCode('en')->getKey();
    }

    /** @return array{0: string, 1: array<int, array<string, mixed>>} test id and the attempt's questions */
    private function startTest(): array
    {
        $testId = $this->actingAs($this->student)
            ->postJson('/api/v1/tests', [
                'exam_id' => $this->exam->getKey(),
                'language_id' => $this->english(),
                'type' => 'daily',
                'question_count' => 5,
                'difficulty_mix' => ['easy' => 1.0],
            ])
            ->assertCreated()
            ->json('data.id');

        $attempt = $this->actingAs($this->student)
            ->postJson("/api/v1/tests/{$testId}/attempt")
            ->assertCreated()
            ->json('data');

        return [$testId, $attempt];
    }

    public function test_the_target_exam_is_kept_on_the_account(): void
    {
        $this->actingAs($this->student)
            ->patchJson('/api/v1/me/profile', [
                'target_exam_id' => $this->exam->getKey(),
                'preferred_language_id' => Language::byCode('ta')->getKey(),
            ])
            ->assertOk()
            ->assertJsonPath('data.target_exam.code', 'STUDENT_EXAM')
            ->assertJsonPath('data.locale', 'ta');

        $this->actingAs($this->student)->getJson('/api/v1/me/profile')->assertJsonPath('data.target_exam.id', $this->exam->getKey());
    }

    public function test_a_timed_attempt_ends_in_the_future_not_hours_ago(): void
    {
        $testId = $this->actingAs($this->student)
            ->postJson('/api/v1/tests', [
                'exam_id' => $this->exam->getKey(),
                'language_id' => $this->english(),
                'type' => 'daily',
                'question_count' => 5,
                'duration_minutes' => 20,
                'difficulty_mix' => ['easy' => 1.0],
            ])
            ->assertCreated()
            ->json('data.id');

        $attemptId = $this->actingAs($this->student)->postJson("/api/v1/tests/{$testId}/attempt")->json('data.attempt_id');

        // Read back through the database, as a reload does. When the DB
        // session timezone differed from the app's, this came back 5½ hours
        // in the past and the runner submitted the moment it opened.
        $expires = \Carbon\CarbonImmutable::parse(
            $this->actingAs($this->student)->getJson("/api/v1/attempts/{$attemptId}")->json('data.expires_at')
        );
        $minutesLeft = now()->diffInMinutes($expires, false);

        $this->assertGreaterThan(19, $minutesLeft);
        $this->assertLessThanOrEqual(20, $minutesLeft);
    }

    public function test_asking_for_a_tamil_test_too_early_starts_translating_the_missing_questions(): void
    {
        $this->seed(\Database\Seeders\AiModelSeeder::class);
        $this->seed(\Database\Seeders\PromptSeeder::class);
        \Illuminate\Support\Facades\Queue::fake();

        $ask = fn () => $this->actingAs($this->student)->postJson('/api/v1/tests', [
            'exam_id' => $this->exam->getKey(),
            'language_id' => Language::byCode('ta')->getKey(),
            'type' => 'daily',
            'question_count' => 5,
        ]);

        // The bank holds only English questions: refused, but not a dead end.
        $first = $ask()->assertStatus(422)->json();
        $this->assertStringContainsString('Translation of the missing questions has started', $first['detail']);
        $this->assertCount(1, $first['meta']['translation_jobs']);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\Translation\TranslateQuestions::class, 1);

        // Asking again while it runs reuses the same job.
        $second = $ask()->assertStatus(422)->json();
        $this->assertSame($first['meta']['translation_jobs'][0]['job_id'], $second['meta']['translation_jobs'][0]['job_id']);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\Translation\TranslateQuestions::class, 1);
    }

    public function test_a_reload_resumes_the_attempt_instead_of_starting_again(): void
    {
        [, $attempt] = $this->startTest();

        $resumed = $this->actingAs($this->student)
            ->getJson("/api/v1/attempts/{$attempt['attempt_id']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->json('data');

        $this->assertSame(
            array_column($attempt['questions'], 'test_question_id'),
            array_column($resumed['questions'], 'test_question_id'),
        );

        // The answer key is never in the paper.
        $this->assertArrayNotHasKey('correct_option', $resumed['questions'][0]);
    }

    public function test_submitting_shows_up_in_results_history_and_progress(): void
    {
        [$testId, $attempt] = $this->startTest();

        $answers = array_map(fn ($q) => [
            'test_question_id' => $q['test_question_id'],
            'selected_option' => 'A',
            'time_spent_seconds' => 15,
        ], $attempt['questions']);

        $this->actingAs($this->student)
            ->postJson("/api/v1/attempts/{$attempt['attempt_id']}/submit", ['answers' => $answers])
            ->assertOk();

        $this->actingAs($this->student)
            ->getJson("/api/v1/attempts/{$attempt['attempt_id']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.result.total_questions', 5)
            ->assertJsonPath('data.result.topics.0.topic', 'Circulation');

        $this->actingAs($this->student)
            ->getJson('/api/v1/me/tests')
            ->assertOk()
            ->assertJsonPath('data.0.id', $testId)
            ->assertJsonPath('data.0.attempts', 1);

        $this->actingAs($this->student)
            ->getJson('/api/v1/me/attempts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'submitted');

        $progress = $this->actingAs($this->student)
            ->getJson("/api/v1/me/progress?exam_id={$this->exam->getKey()}&days=7")
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $progress['totals']['tests']);
        $this->assertSame(5, $progress['totals']['questions_answered']);
        $this->assertSame(1, $progress['streak_days'], 'A test submitted today starts a streak.');
        $this->assertCount(7, $progress['trend'], 'Every day in the window is present, quiet ones included.');
        $this->assertSame(1, end($progress['trend'])['tests']);
        $this->assertSame('Circulation', $progress['mastery'][0]['topic']);
    }

    public function test_another_student_sees_none_of_it(): void
    {
        [$testId, $attempt] = $this->startTest();

        $answers = array_map(fn ($q) => ['test_question_id' => $q['test_question_id'], 'selected_option' => 'B'], $attempt['questions']);
        $this->actingAs($this->student)->postJson("/api/v1/attempts/{$attempt['attempt_id']}/submit", ['answers' => $answers])->assertOk();

        // Same organization, so tenant scope alone would let them through.
        $other = TenantContext::withoutTenancy(function (): User {
            $user = User::query()->create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'secret-pass']);
            $orgId = \App\Models\OrganizationUser::query()->where('user_id', $this->student->getKey())->value('organization_id');
            \App\Models\OrganizationUser::query()->create([
                'organization_id' => $orgId, 'user_id' => $user->getKey(), 'status' => 'active', 'is_default' => true,
            ]);
            app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($orgId);
            $user->assignRole('student');

            return $user;
        });

        // Papers too: generating, listing or downloading another's.
        \Illuminate\Support\Facades\Storage::fake('local');
        $documentId = $this->actingAs($this->student)
            ->postJson("/api/v1/tests/{$testId}/documents", ['types' => ['answer_key']])
            ->assertCreated()
            ->json('data.0.id');
        $this->actingAs($other)->postJson("/api/v1/tests/{$testId}/documents", ['types' => ['answer_key']])->assertNotFound();
        $this->actingAs($other)->getJson("/api/v1/documents/{$documentId}/download")->assertNotFound();
        $this->actingAs($other)->getJson('/api/v1/documents')->assertJsonCount(0, 'data');
        $this->actingAs($this->student)->getJson('/api/v1/documents')->assertJsonCount(1, 'data');

        $this->actingAs($other)->getJson("/api/v1/tests/{$testId}")->assertNotFound();
        $this->actingAs($other)->postJson("/api/v1/tests/{$testId}/attempt")->assertNotFound();
        $this->actingAs($other)->getJson("/api/v1/attempts/{$attempt['attempt_id']}")->assertNotFound();
        $this->actingAs($other)->getJson("/api/v1/results/{$attempt['attempt_id']}/solutions")->assertNotFound();
        $this->actingAs($other)->getJson('/api/v1/me/attempts')->assertJsonCount(0, 'data');
    }
}
