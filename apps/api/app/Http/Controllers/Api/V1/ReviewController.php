<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Review\ReviewerMetrics;
use App\Domain\Review\ReviewWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\DecideReviewRequest;
use App\Models\Question;
use App\Models\Review;
use App\Models\ReviewComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The reviewer workstation's API.
 *
 * Designed for keyboard-speed review: one call returns the question, its
 * citation, the source text it was drawn from, and every validation verdict —
 * so the reviewer is auditing the machine rather than redoing its work, and
 * never has to go looking for the book.
 */
final class ReviewController extends Controller
{
    public function queue(Request $request, ReviewerMetrics $metrics): JsonResponse
    {
        $this->authorizeReviewer($request);

        $reviews = Review::query()
            ->with('question.master')
            ->claimable()
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->limit(min(50, $request->integer('limit', 20)))
            ->get();

        return response()->json([
            'data' => $reviews->map(fn (Review $r) => [
                'review_id' => $r->getKey(),
                'question_id' => $r->reviewable_id,
                'priority' => $r->priority,
                'quality_score' => $r->question?->quality_score,
                'preview' => mb_substr((string) $r->question?->master?->question_text, 0, 120),
                'waiting_since' => $r->created_at?->toIso8601String(),
            ])->all(),
            'meta' => $metrics->queueHealth(app(TenantContext::class)->organizationId),
        ]);
    }

    /** Claims the next item. Atomic: two reviewers never get the same question. */
    public function next(Request $request, ReviewWorkflow $workflow): JsonResponse
    {
        $this->authorizeReviewer($request);

        $review = $workflow->claimNext($request->user(), app(TenantContext::class)->organizationId);

        if ($review === null) {
            return response()->json(['data' => null, 'meta' => ['message' => 'Queue is empty.']]);
        }

        return response()->json(['data' => $this->workstationPayload($review)]);
    }

    public function show(Request $request, string $reviewId): JsonResponse
    {
        $this->authorizeReviewer($request);

        return response()->json([
            'data' => $this->workstationPayload(Review::query()->findOrFail($reviewId)),
        ]);
    }

    public function decide(
        DecideReviewRequest $request,
        string $reviewId,
        ReviewWorkflow $workflow,
    ): JsonResponse {
        $review = Review::query()->findOrFail($reviewId);

        try {
            $question = $workflow->decide(
                review: $review,
                reviewer: $request->user(),
                decision: $request->string('decision')->toString(),
                reason: $request->input('reason'),
                edits: $request->array('edits'),
                timeSpentSeconds: $request->input('time_spent_seconds'),
            );
        } catch (RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        if ($request->filled('comment')) {
            ReviewComment::query()->create([
                'review_id' => $review->getKey(),
                'user_id' => $request->user()->getKey(),
                'body' => $request->string('comment')->toString(),
                'field_path' => $request->input('field_path'),
            ]);
        }

        return response()->json([
            'data' => [
                'question_id' => $question->getKey(),
                'status' => $question->status,
                'version' => $question->current_version,
            ],
        ]);
    }

    public function metrics(Request $request, ReviewerMetrics $metrics): JsonResponse
    {
        $this->authorizeReviewer($request);

        $organizationId = app(TenantContext::class)->organizationId;

        return response()->json([
            'data' => [
                'me' => $metrics->forScope($organizationId, $request->user()->getKey()),
                'scope' => $metrics->forScope($organizationId),
                'queue' => $metrics->queueHealth($organizationId),
            ],
        ]);
    }

    /**
     * Everything the reviewer needs on one screen: the question, the machine's
     * verdicts, and the source passage it was generated from.
     *
     * @return array<string, mixed>
     */
    private function workstationPayload(Review $review): array
    {
        /** @var Question $question */
        $question = Question::query()
            ->with(['options', 'master', 'sources.chunk', 'validations'])
            ->findOrFail($review->reviewable_id);

        return [
            'review_id' => $review->getKey(),
            'claim_expires_at' => $review->claim_expires_at?->toIso8601String(),
            'question' => [
                'id' => $question->getKey(),
                'status' => $question->status,
                'difficulty' => $question->difficulty,
                'quality_score' => $question->quality_score,
                'ai_confidence' => $question->ai_confidence,
                'question_text' => $question->master?->question_text,
                'options' => $question->master?->optionMap(),
                'correct_option' => $question->correctOptionKey(),
                'explanation' => $question->master?->explanation,
            ],
            // The source pane. Having this on screen is the difference between
            // 40 and 200 reviewed questions per hour.
            'source' => $question->sources->map(fn ($s) => [
                'citation' => $s->citation(),
                'supporting_quote' => $s->supporting_quote,
                'page_start' => $s->page_start,
                'page_end' => $s->page_end,
                'passage' => $s->chunk?->text,
            ])->all(),
            // The machine shows its work, so the human audits rather than redoes.
            'checks' => $question->validations->map(fn ($v) => [
                'code' => $v->check_code,
                'passed' => $v->passed,
                'score' => $v->score,
                'detail' => $v->detail,
            ])->all(),
        ];
    }

    private function authorizeReviewer(Request $request): void
    {
        abort_unless(
            $request->user()?->can('questions.approve.platform')
                || $request->user()?->can('questions.approve.org'),
            403,
            'You do not have reviewer permissions.',
        );
    }
}
