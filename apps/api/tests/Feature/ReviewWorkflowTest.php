<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Review\ReviewerMetrics;
use App\Domain\Review\ReviewWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Models\Language;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionTranslation;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class ReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private ReviewWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->workflow = app(ReviewWorkflow::class);
    }

    public function test_a_reviewer_can_claim_and_approve_a_question(): void
    {
        [$question, $review, $reviewer] = $this->makeReviewable();

        TenantContext::withoutTenancy(function () use ($review, $reviewer): void {
            $claimed = $this->workflow->claimNext($reviewer);

            $this->assertNotNull($claimed);
            $this->assertSame($review->getKey(), $claimed->getKey());
            $this->assertSame('in_review', $claimed->status);
            // A lease, not a permanent assignment: an abandoned session
            // returns its work to the queue.
            $this->assertTrue($claimed->claim_expires_at->isFuture());

            $this->workflow->decide($claimed, $reviewer, 'approve', timeSpentSeconds: 18);
        });

        $question = TenantContext::withoutTenancy(fn () => $question->fresh());

        $this->assertSame('APPROVED', $question->status);
        $this->assertNotNull($question->approved_at);
        $this->assertSame($reviewer->getKey(), $question->approved_by);
    }

    public function test_two_reviewers_never_receive_the_same_question(): void
    {
        [, , $first] = $this->makeReviewable();
        $second = $this->makeReviewer('second@example.test');

        TenantContext::withoutTenancy(function () use ($first, $second): void {
            $a = $this->workflow->claimNext($first);
            $b = $this->workflow->claimNext($second);

            $this->assertNotNull($a);
            // Only one item exists, and it is already claimed and unexpired.
            $this->assertNull($b, 'a claimed item was handed to a second reviewer');
        });
    }

    public function test_editing_the_answer_key_versions_the_question_and_stales_translations(): void
    {
        [$question, $review, $reviewer] = $this->makeReviewable();

        $tamil = TenantContext::withoutTenancy(fn () => QuestionTranslation::query()->create([
            'question_id' => $question->getKey(),
            'language_id' => Language::byCode('ta')->getKey(),
            'is_master' => false,
            'question_text' => 'மனித உடலில் இரத்தத்தை உந்தித் தள்ளும் உறுப்பு எது?',
            'option_a' => 'நுரையீரல்', 'option_b' => 'இதயம்',
            'option_c' => 'சிறுநீரகம்', 'option_d' => 'கல்லீரல்',
            'translation_status' => 'AI_VALIDATED',
        ]));

        TenantContext::withoutTenancy(function () use ($review, $reviewer): void {
            $this->workflow->decide($review, $reviewer, 'approve', reason: 'key was wrong', edits: [
                'correct_option' => 'C',
                'difficulty' => 'hard',
            ]);
        });

        [$question, $tamil, $versions, $correct] = TenantContext::withoutTenancy(fn () => [
            $question->fresh(),
            $tamil->fresh(),
            DB::table('question_versions')->where('question_id', $question->getKey())->count(),
            QuestionOption::query()->where('question_id', $question->getKey())
                ->where('is_correct', true)->first(),
        ]);

        $this->assertSame('C', $correct->option_key);
        $this->assertSame('hard', $question->difficulty);
        $this->assertSame(2, $question->current_version);

        // Nothing is overwritten without a snapshot first.
        $this->assertSame(1, $versions);

        // A changed key invalidates every translation's validation — a Tamil
        // paper must never keep an answer the English master no longer has.
        $this->assertSame('STALE', $tamil->translation_status);
    }

    public function test_illegal_transitions_are_refused(): void
    {
        [$question, , $reviewer] = $this->makeReviewable();

        TenantContext::withoutTenancy(function () use ($question, $reviewer): void {
            $question->forceFill(['status' => 'RETIRED'])->save();

            $this->expectException(RuntimeException::class);
            $this->workflow->transition($question->fresh(), 'APPROVED', $reviewer);
        });
    }

    public function test_auto_approval_stays_locked_until_agreement_is_proven(): void
    {
        [, $review, $reviewer] = $this->makeReviewable();

        TenantContext::withoutTenancy(function () use ($review, $reviewer): void {
            $this->workflow->decide($review, $reviewer, 'approve', timeSpentSeconds: 20);
        });

        $metrics = TenantContext::withoutTenancy(
            fn () => app(ReviewerMetrics::class)->forScope()
        );

        $this->assertSame(1, $metrics['sample']);
        $this->assertSame(1.0, $metrics['agreement_rate']);
        // Perfect agreement on a sample of one proves nothing. Automation is
        // earned over MIN_SAMPLE decisions, not inferred from a good streak.
        $this->assertFalse(
            $metrics['auto_approve_eligible'],
            'auto-approval unlocked on an insufficient sample'
        );
        $this->assertSame(180.0, $metrics['per_hour']);
    }

    /** @return array{0: Question, 1: Review, 2: User} */
    private function makeReviewable(): array
    {
        return TenantContext::withoutTenancy(function (): array {
            $language = Language::byCode('en');

            $question = Question::query()->create([
                'organization_id' => null,
                'master_language_id' => $language->getKey(),
                'difficulty' => 'easy',
                'status' => 'PENDING_REVIEW',
                'quality_score' => 82.5,
                'origin' => 'human_authored',
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
                'language_id' => $language->getKey(),
                'is_master' => true,
                'question_text' => 'Which organ pumps blood throughout the human body?',
                'option_a' => 'Lungs', 'option_b' => 'Heart',
                'option_c' => 'Kidney', 'option_d' => 'Liver',
                'explanation' => 'The heart pumps blood through the circulatory system.',
                'translation_status' => 'HUMAN_APPROVED',
            ]);

            $review = $this->workflow->enqueue($question->fresh(), priority: 8);

            return [$question->fresh(), $review, $this->makeReviewer('reviewer@example.test')];
        });
    }

    private function makeReviewer(string $email): User
    {
        return TenantContext::withoutTenancy(fn () => User::query()->create([
            'name' => 'Reviewer',
            'email' => $email,
            'password' => 'password-reviewer',
        ]));
    }
}
