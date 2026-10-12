<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Questions\TargetPlanner;
use App\Domain\Tenancy\TenantContext;
use App\Models\CanonicalTopic;
use App\Models\Exam;
use App\Models\Language;
use App\Models\Question;
use App\Models\QuestionExamMapping;
use App\Models\QuestionGenerationTarget;
use App\Models\QuestionTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ordering the whole number instead of the shortfall is the single largest
 * avoidable cost in the platform: the tokens are spent on the way in, long
 * before dedup can throw the duplicates away. These tests pin the arithmetic
 * that prevents it.
 */
final class GenerationTargetTest extends TestCase
{
    use RefreshDatabase;

    private Exam $exam;

    private Language $english;

    private CanonicalTopic $topic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->english = Language::byCode('en');

        TenantContext::withoutTenancy(function (): void {
            $this->exam = Exam::query()->create([
                'organization_id' => null, 'code' => 'TARGET_EXAM',
                'name' => 'Target Exam', 'is_active' => true,
            ]);

            $this->topic = CanonicalTopic::query()->create([
                'slug' => 'polity', 'name' => 'Polity',
                'domain' => 'general_studies', 'path' => 'polity', 'depth' => 0,
            ]);
        });
    }

    private function makeQuestions(int $count, string $status, ?string $difficulty = 'medium'): void
    {
        TenantContext::withoutTenancy(function () use ($count, $status, $difficulty): void {
            for ($i = 0; $i < $count; $i++) {
                $question = Question::query()->create([
                    'organization_id' => null,
                    'master_language_id' => $this->english->getKey(),
                    'canonical_topic_id' => $this->topic->getKey(),
                    'difficulty' => $difficulty,
                    'status' => $status,
                    'origin' => 'ai_generated',
                    'quality_score' => 80,
                ]);

                QuestionTranslation::query()->create([
                    'question_id' => $question->getKey(),
                    'language_id' => $this->english->getKey(),
                    'is_master' => true,
                    'question_text' => "Question {$status} {$difficulty} marker{$i}?",
                    'option_a' => 'A', 'option_b' => 'B', 'option_c' => 'C', 'option_d' => 'D',
                    'translation_status' => 'HUMAN_APPROVED',
                ]);

                QuestionExamMapping::query()->create([
                    'question_id' => $question->getKey(),
                    'exam_id' => $this->exam->getKey(),
                    'is_active' => true,
                ]);
            }
        });
    }

    private function plan(int $requested, bool $countPending = true, ?string $difficulty = null)
    {
        return app(TargetPlanner::class)->planRequest(
            requested: $requested,
            languageId: $this->english->getKey(),
            examId: $this->exam->getKey(),
            canonicalTopicId: $this->topic->getKey(),
            difficulty: $difficulty,
            countPending: $countPending,
        );
    }

    public function test_it_orders_only_the_shortfall(): void
    {
        // The example from the specification: 43 approved against a request
        // for 50 is an order of 7, not 50.
        $this->makeQuestions(43, 'APPROVED');

        $plan = $this->plan(50);

        $this->assertSame(43, $plan->approved);
        $this->assertSame(7, $plan->deficit);
        $this->assertFalse($plan->isSatisfied());
    }

    public function test_a_met_target_orders_nothing(): void
    {
        $this->makeQuestions(50, 'APPROVED');

        $plan = $this->plan(50);

        $this->assertSame(0, $plan->deficit);
        $this->assertTrue($plan->isSatisfied());
    }

    public function test_an_over_full_bank_never_orders_a_negative_number(): void
    {
        $this->makeQuestions(80, 'APPROVED');

        $this->assertSame(0, $this->plan(50)->deficit);
    }

    public function test_questions_awaiting_review_hold_their_slot_by_default(): void
    {
        $this->makeQuestions(30, 'APPROVED');
        $this->makeQuestions(15, 'PENDING_REVIEW');

        // Generating twice before reviewing once would otherwise order the same
        // shortfall twice and double a queue that is already the bottleneck.
        $this->assertSame(5, $this->plan(50)->deficit);

        // Overridable, because a reviewer who expects to reject most of a batch
        // has a legitimate reason to ignore it.
        $this->assertSame(20, $this->plan(50, countPending: false)->deficit);
    }

    public function test_rejected_questions_do_not_hold_a_slot(): void
    {
        $this->makeQuestions(10, 'APPROVED');
        $this->makeQuestions(25, 'REJECTED');

        // A rejected draft is a gap, not a holding. Counting it would leave the
        // topic permanently short by however many bad drafts it once produced.
        $plan = $this->plan(50);

        $this->assertSame(25, $plan->rejected);
        $this->assertSame(40, $plan->deficit);
    }

    public function test_a_difficulty_target_is_counted_separately(): void
    {
        $this->makeQuestions(40, 'APPROVED', 'easy');
        $this->makeQuestions(2, 'APPROVED', 'hard');

        // A bank of 42 looks healthy until a paper needs hard questions. The
        // per-difficulty count is what makes that visible before the paper is
        // built short.
        $this->assertSame(0, $this->plan(20, difficulty: 'easy')->deficit);
        $this->assertSame(18, $this->plan(20, difficulty: 'hard')->deficit);
    }

    public function test_another_languages_questions_do_not_satisfy_the_target(): void
    {
        $this->makeQuestions(50, 'APPROVED');

        $tamil = Language::byCode('ta');

        $plan = app(TargetPlanner::class)->planRequest(
            requested: 50,
            languageId: $tamil->getKey(),
            examId: $this->exam->getKey(),
            canonicalTopicId: $this->topic->getKey(),
        );

        // Fifty English questions do not make a Tamil paper printable.
        $this->assertSame(0, $plan->approved);
        $this->assertSame(50, $plan->deficit);
    }

    public function test_the_backlog_lists_the_largest_shortfall_first(): void
    {
        $this->makeQuestions(5, 'APPROVED');

        TenantContext::withoutTenancy(function (): void {
            $other = CanonicalTopic::query()->create([
                'slug' => 'geography', 'name' => 'Geography',
                'domain' => 'general_studies', 'path' => 'geography', 'depth' => 0,
            ]);

            foreach ([[$this->topic->getKey(), 20], [$other->getKey(), 100]] as [$topicId, $count]) {
                QuestionGenerationTarget::query()->create([
                    'organization_id' => null,
                    'exam_id' => $this->exam->getKey(),
                    'canonical_topic_id' => $topicId,
                    'language_id' => $this->english->getKey(),
                    'target_count' => $count,
                ]);
            }
        });

        $backlog = app(TargetPlanner::class)->planExam($this->exam->getKey());

        $this->assertCount(2, $backlog);
        // Geography needs 100 and has none; polity needs 15 more. The thinnest
        // topic has to surface first or the backlog is not actionable.
        $this->assertSame(100, $backlog[0]->deficit);
        $this->assertSame(15, $backlog[1]->deficit);
    }
}
