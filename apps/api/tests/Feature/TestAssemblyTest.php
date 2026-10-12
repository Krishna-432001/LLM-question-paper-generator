<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Tests\AssembleTestAction;
use App\Actions\Tests\EvaluateAttemptAction;
use App\Domain\Tenancy\TenantContext;
use App\Models\CanonicalTopic;
use App\Models\Exam;
use App\Models\Language;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Question;
use App\Models\QuestionExamMapping;
use App\Models\QuestionOption;
use App\Models\QuestionTranslation;
use App\Models\TestAttempt;
use App\Models\TopicMastery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TestAssemblyTest extends TestCase
{
    use RefreshDatabase;

    private Exam $exam;

    private Language $english;

    private Organization $org;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->english = Language::byCode('en');

        TenantContext::withoutTenancy(function (): void {
            $this->exam = Exam::query()->create([
                'organization_id' => null, 'code' => 'TEST_EXAM',
                'name' => 'Test Exam', 'is_active' => true,
            ]);

            $this->student = User::query()->create([
                'name' => 'Student', 'email' => 'student@example.test', 'password' => 'secret-pass',
            ]);

            $this->org = Organization::query()->create([
                'name' => 'Student', 'slug' => 'student-org', 'type' => 'individual',
                'owner_user_id' => $this->student->getKey(),
            ]);

            OrganizationUser::query()->create([
                'organization_id' => $this->org->getKey(),
                'user_id' => $this->student->getKey(),
                'status' => 'active', 'is_default' => true,
            ]);
        });
    }

    public function test_it_respects_the_difficulty_mix(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $this->makeQuestions('easy', 20);
            $this->makeQuestions('medium', 20);
            $this->makeQuestions('hard', 20);

            $test = app(AssembleTestAction::class)->execute([
                'organization_id' => $this->org->getKey(),
                'user_id' => $this->student->getKey(),
                'exam_id' => $this->exam->getKey(),
                'language_id' => $this->english->getKey(),
                'type' => 'model',
                'question_count' => 10,
                'difficulty_mix' => ['easy' => 0.4, 'medium' => 0.4, 'hard' => 0.2],
            ]);

            $this->assertSame('ready', $test->status);
            $this->assertSame(10, $test->questions->count());

            $counts = $test->questions
                ->map(fn ($tq) => Question::find($tq->question_id)->difficulty)
                ->countBy();

            $this->assertSame(4, $counts['easy'] ?? 0);
            $this->assertSame(4, $counts['medium'] ?? 0);
            $this->assertSame(2, $counts['hard'] ?? 0);

            // The weights used are recorded, so the paper is explainable later.
            $this->assertNotNull($test->selection_profile);
            $this->assertSame(0.45, $test->selection_profile['quality']);
        });
    }

    public function test_it_never_repeats_a_question_within_one_paper(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $this->makeQuestions('easy', 30);

            $test = app(AssembleTestAction::class)->execute([
                'organization_id' => $this->org->getKey(),
                'exam_id' => $this->exam->getKey(),
                'language_id' => $this->english->getKey(),
                'type' => 'daily',
                'question_count' => 15,
                'difficulty_mix' => ['easy' => 1.0],
            ]);

            $ids = $test->questions->pluck('question_id');
            $this->assertSame($ids->count(), $ids->unique()->count());
        });
    }

    public function test_it_only_selects_questions_available_in_the_requested_language(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $this->makeQuestions('easy', 5);                     // English only
            $tamil = Language::byCode('ta');

            $test = app(AssembleTestAction::class)->execute([
                'organization_id' => $this->org->getKey(),
                'exam_id' => $this->exam->getKey(),
                'language_id' => $tamil->getKey(),
                'type' => 'daily',
                'question_count' => 5,
            ]);

            // A paper that cannot be rendered in the requested language is
            // not a paper — better to fail loudly than to ship English
            // questions to a student who asked for Tamil.
            $this->assertSame(0, $test->questions->count());
            $this->assertSame('failed', $test->status);
        });
    }

    public function test_submitting_scores_the_attempt_and_updates_mastery(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $topic = CanonicalTopic::query()->create([
                'slug' => 'circulation', 'name' => 'Circulation',
                'domain' => 'science', 'path' => 'science.circulation', 'depth' => 1,
            ]);

            $this->makeQuestions('easy', 4, $topic->getKey());

            $test = app(AssembleTestAction::class)->execute([
                'organization_id' => $this->org->getKey(),
                'user_id' => $this->student->getKey(),
                'exam_id' => $this->exam->getKey(),
                'language_id' => $this->english->getKey(),
                'type' => 'daily',
                'question_count' => 4,
                'difficulty_mix' => ['easy' => 1.0],
                'negative_marking' => 0.25,
            ]);

            $attempt = TestAttempt::query()->create([
                'organization_id' => $this->org->getKey(),
                'test_id' => $test->getKey(),
                'user_id' => $this->student->getKey(),
                'language_id' => $this->english->getKey(),
            ]);

            // Three right, one wrong.
            $answers = $test->questions->values()->map(fn ($tq, $i) => [
                'test_question_id' => $tq->getKey(),
                'selected_option' => $i < 3 ? 'B' : 'A',
                'time_spent_seconds' => 20,
            ])->all();

            $result = app(EvaluateAttemptAction::class)->execute($attempt, $answers);

            $this->assertSame(3, $result->correct);
            $this->assertSame(1, $result->wrong);
            $this->assertSame(75.0, $result->accuracy);
            // 3 marks earned, 0.25 deducted for the wrong answer.
            $this->assertSame(2.75, $result->final_score);

            $mastery = TopicMastery::query()
                ->where('user_id', $this->student->getKey())
                ->where('canonical_topic_id', $topic->getKey())
                ->first();

            // EWMA from a 0.5 prior toward the observed 0.75.
            $this->assertNotNull($mastery);
            $this->assertSame(0.6, round((float) $mastery->mastery, 2));

            $this->assertTrue($attempt->fresh()->isSubmitted());
        });
    }

    private function makeQuestions(string $difficulty, int $count, ?string $topicId = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            $question = Question::query()->create([
                'organization_id' => null,
                'master_language_id' => $this->english->getKey(),
                'difficulty' => $difficulty,
                'status' => 'APPROVED',
                'origin' => 'human_authored',
                'quality_score' => 70 + ($i % 30),
                'canonical_topic_id' => $topicId,
            ]);

            foreach (['A' => false, 'B' => true, 'C' => false, 'D' => false] as $key => $correct) {
                QuestionOption::query()->create([
                    'question_id' => $question->getKey(),
                    'option_key' => $key,
                    'position' => ord($key) - 64,
                    'is_correct' => $correct,
                ]);
            }

            QuestionTranslation::query()->create([
                'question_id' => $question->getKey(),
                'language_id' => $this->english->getKey(),
                'is_master' => true,
                // Deliberately varied wording: identical stems would be
                // rejected by the diversity filter, which is correct
                // behaviour but would make this fixture test nothing.
                // Genuinely distinct wording per question. Near-identical
                // stems would be rejected by the diversity filter — correct
                // behaviour, but it would make this fixture test nothing.
                'question_text' => sprintf(
                    'Which %s %s performs %s during %s within specimen marker%d?',
                    ['cardiac', 'vascular', 'pulmonary', 'renal', 'neural', 'hepatic', 'lymphatic'][$i % 7],
                    ['tissue', 'membrane', 'chamber', 'conduit'][intdiv($i, 7) % 4],
                    ['filtration', 'transport', 'exchange', 'regulation', 'absorption'][$i % 5],
                    ['circulation', 'respiration', 'digestion', 'excretion', 'metabolism', 'osmosis'][$i % 6],
                    $i,
                ),
                'option_a' => "Alpha structure {$i} tissue", 'option_b' => "Beta structure {$i} organ",
                'option_c' => "Gamma structure {$i} vessel", 'option_d' => "Delta structure {$i} membrane",
                'explanation' => 'Because the heart pumps blood.',
                'translation_status' => 'HUMAN_APPROVED',
            ]);

            QuestionExamMapping::query()->create([
                'question_id' => $question->getKey(),
                'exam_id' => $this->exam->getKey(),
                'is_active' => true,
            ]);
        }
    }
}
