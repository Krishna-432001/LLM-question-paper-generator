<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\Language;
use App\Models\TestQuestion;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MakesTestData;
use Tests\TestCase;

/**
 * The error-recovery loop: a wrong answer comes back a day later, then three
 * and seven days after each right answer, and leaves after three in a row.
 */
final class ErrorRecoveryTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

    private User $student;

    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LanguageSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->student = $this->makeAdmin('student@example.test', 'student', platformAdmin: false);
        $this->exam = $this->makeExam('RECOVERY_EXAM');
        $topic = $this->makeTopic('circulation', 'Circulation');
        $source = $this->makeSource('Biology');

        // Keys run B, C, D, A, B, C, D, A: answering A to all eight is wrong six times.
        foreach (range(1, 8) as $i) {
            $this->makeQuestion($i, 'APPROVED', $source, $this->exam, [
                'difficulty' => 'easy',
                'canonical_topic_id' => $topic->getKey(),
            ]);
        }
    }

    /** @param  callable(TestQuestion): string  $choose */
    private function sit(string $testId, callable $choose): void
    {
        $attempt = $this->actingAs($this->student)->postJson("/api/v1/tests/{$testId}/attempt")->assertCreated()->json('data');
        $byId = TestQuestion::query()->with('question.options')->where('test_id', $testId)->get()->keyBy(fn ($tq) => (string) $tq->getKey());

        $answers = array_map(fn (array $q): array => [
            'test_question_id' => $q['test_question_id'],
            'selected_option' => $choose($byId[$q['test_question_id']]),
        ], $attempt['questions']);

        $this->actingAs($this->student)->postJson("/api/v1/attempts/{$attempt['attempt_id']}/submit", ['answers' => $answers])->assertOk();
    }

    private function recoveryTest(): TestResponse
    {
        return $this->actingAs($this->student)->postJson('/api/v1/tests/error-recovery', [
            'exam_id' => $this->exam->getKey(),
            'language_id' => Language::byCode('en')->getKey(),
        ]);
    }

    private function mistakes(): array
    {
        return $this->actingAs($this->student)->getJson("/api/v1/me/mistakes?exam_id={$this->exam->getKey()}")->assertOk()->json('data');
    }

    public function test_wrong_answers_come_back_on_the_ladder_and_leave_after_three_right(): void
    {
        $testId = $this->actingAs($this->student)->postJson('/api/v1/tests', [
            'exam_id' => $this->exam->getKey(),
            'language_id' => Language::byCode('en')->getKey(),
            'type' => 'daily',
            'question_count' => 8,
        ])->assertCreated()->json('data.id');
        $this->sit($testId, fn (): string => 'A');

        $this->assertSame(6, $this->mistakes()['open']);
        $this->assertSame(0, $this->mistakes()['due'], 'Not due until a day later.');
        $this->recoveryTest()->assertStatus(422)->assertJsonPath('meta.open', 6);

        $right = fn (TestQuestion $tq): string => (string) $tq->question->correctOptionKey();

        // Retest 1 after a day, then after 3 more days, then after 7 more.
        foreach ([1, 3, 7] as $step => $days) {
            $this->travel($days)->days();
            $this->assertSame(6, $this->mistakes()['due'], "Due at step {$step}.");

            $recovery = $this->recoveryTest()->assertCreated()
                ->assertJsonPath('data.type', 'error_recovery')
                ->assertJsonPath('data.question_count', 6);
            $this->sit($recovery->json('data.id'), $right);

            $this->assertSame(0, $this->mistakes()['due'], 'Answered right: not due again until the next rung.');
        }

        $this->assertSame(0, $this->mistakes()['open'], 'Three right in a row: recovered.');
        $this->assertSame(0.0, (float) $this->mistakes()['repeated_rate']);
    }

    public function test_a_second_wrong_answer_counts_as_a_repeated_error_and_restarts_the_ladder(): void
    {
        $testId = $this->actingAs($this->student)->postJson('/api/v1/tests', [
            'exam_id' => $this->exam->getKey(),
            'language_id' => Language::byCode('en')->getKey(),
            'type' => 'daily',
            'question_count' => 8,
        ])->assertCreated()->json('data.id');
        $this->sit($testId, fn (): string => 'A');

        $this->travel(1)->days();
        $this->sit($this->recoveryTest()->assertCreated()->json('data.id'), fn (): string => 'A');

        $summary = $this->mistakes();
        $this->assertSame(6, $summary['open']);
        $this->assertSame(0, $summary['due'], 'Wrong again: back to the one-day rung.');
        $this->assertSame(100.0, (float) $summary['repeated_rate']);

        $progress = $this->actingAs($this->student)->getJson("/api/v1/me/progress?exam_id={$this->exam->getKey()}")->assertOk()->json('data');
        $this->assertSame('red', $progress['mastery'][0]['band']);
        $this->assertSame(1, $progress['bands']['red']);
        $this->assertSame(6, $progress['mistakes']['open']);
        $this->assertSame([], $progress['mocks']);
    }
}
