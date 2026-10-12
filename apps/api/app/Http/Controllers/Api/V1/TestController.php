<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tests\AssembleTestAction;
use App\Actions\Tests\EvaluateAttemptAction;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tests\CreateTestRequest;
use App\Http\Requests\Tests\SubmitAttemptRequest;
use App\Models\Test;
use App\Models\TestAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TestController extends Controller
{
    public function store(CreateTestRequest $request, AssembleTestAction $assemble): JsonResponse
    {
        $context = app(TenantContext::class);

        $test = $assemble->execute([
            'organization_id' => $context->organizationId,
            'user_id' => $context->userId,
            'exam_id' => $request->string('exam_id')->toString(),
            'language_id' => $request->string('language_id')->toString(),
            'type' => $request->string('type')->toString(),
            'question_count' => $request->integer('question_count'),
            'title' => $request->input('title', 'Practice Test'),
            'difficulty_mix' => $request->array('difficulty_mix'),
            'duration_minutes' => $request->input('duration_minutes'),
            'negative_marking' => $request->input('negative_marking', 0),
        ]);

        abort_if(
            $test->status === 'failed',
            422,
            'The question bank has no approved questions matching that request yet.',
        );

        return response()->json(['data' => $this->shape($test)], 201);
    }

    public function show(string $testId): JsonResponse
    {
        $test = Test::query()->with('questions')->findOrFail($testId);

        return response()->json(['data' => $this->shape($test)]);
    }

    /**
     * Starting an attempt is when option text is released. Before this, the
     * paper exposes only question count and metadata — otherwise the bank
     * could be harvested by listing tests.
     */
    public function attempt(Request $request, string $testId): JsonResponse
    {
        $test = Test::query()->with('questions.question.master')->findOrFail($testId);
        $context = app(TenantContext::class);

        $attempt = TestAttempt::query()->create([
            'organization_id' => $context->organizationId,
            'test_id' => $test->getKey(),
            'user_id' => $context->userId,
            'attempt_number' => TestAttempt::query()
                ->where('test_id', $test->getKey())
                ->where('user_id', $context->userId)
                ->count() + 1,
            'language_id' => $test->language_id,
            'started_at' => now(),
            'expires_at' => $test->duration_minutes
                ? now()->addMinutes($test->duration_minutes)
                : null,
        ]);

        return response()->json([
            'data' => [
                'attempt_id' => $attempt->getKey(),
                'expires_at' => $attempt->expires_at?->toIso8601String(),
                'questions' => $test->questions->map(fn ($tq) => [
                    'test_question_id' => $tq->getKey(),
                    'sequence' => $tq->sequence,
                    'marks' => $tq->marks,
                    'question_text' => $tq->question->master?->question_text,
                    'options' => $tq->question->master?->optionMap(),
                    // The correct option is never sent to the client.
                ])->all(),
            ],
        ], 201);
    }

    public function submit(
        SubmitAttemptRequest $request,
        string $attemptId,
        EvaluateAttemptAction $evaluate,
    ): JsonResponse {
        $attempt = TestAttempt::query()->findOrFail($attemptId);

        abort_if($attempt->isSubmitted(), 409, 'This attempt has already been submitted.');
        abort_unless(
            $attempt->user_id === app(TenantContext::class)->userId,
            403,
            'This attempt belongs to another user.',
        );

        $result = $evaluate->execute($attempt, $request->array('answers'));

        return response()->json([
            'data' => [
                'attempt_id' => $attempt->getKey(),
                'total_questions' => $result->total_questions,
                'attempted' => $result->attempted,
                'correct' => $result->correct,
                'wrong' => $result->wrong,
                'final_score' => $result->final_score,
                'accuracy' => $result->accuracy,
                'time_taken_seconds' => $result->time_taken_seconds,
                'topic_breakdown' => $result->topic_breakdown,
                'difficulty_breakdown' => $result->difficulty_breakdown,
                'weak_topics' => $result->weak_topics,
                'solutions_url' => "/api/v1/results/{$attempt->getKey()}/solutions",
            ],
        ]);
    }

    /** Solutions are released only after submission, with their citations. */
    public function solutions(string $attemptId): JsonResponse
    {
        $attempt = TestAttempt::query()->findOrFail($attemptId);

        abort_unless($attempt->isSubmitted(), 409, 'Submit the attempt before viewing solutions.');

        $test = $attempt->test()->with('questions.question.master', 'questions.question.sources.chunk')->first();

        return response()->json([
            'data' => $test->questions->map(fn ($tq) => [
                'sequence' => $tq->sequence,
                'question_text' => $tq->question->master?->question_text,
                'correct_option' => $tq->question->correctOptionKey(),
                'explanation' => $tq->question->master?->explanation,
                // Every solution shows where the answer comes from. This is
                // what a student can take to a teacher and check.
                'sources' => $tq->question->sources->map(fn ($s) => [
                    'citation' => $s->citation(),
                    'supporting_quote' => $s->supporting_quote,
                ])->all(),
            ])->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function shape(Test $test): array
    {
        return [
            'id' => $test->getKey(),
            'title' => $test->title,
            'type' => $test->type,
            'status' => $test->status,
            'question_count' => $test->question_count,
            'total_marks' => $test->total_marks,
            'duration_minutes' => $test->duration_minutes,
            'negative_marking' => $test->negative_marking,
            'selection_profile' => $test->selection_profile,
        ];
    }
}
