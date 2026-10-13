<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tests\AssembleTestAction;
use App\Actions\Tests\EvaluateAttemptAction;
use App\Domain\PracticePapers\PracticePaper;
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
        $this->assertCanUse($test);

        return response()->json(['data' => $this->shape($test)]);
    }

    /**
     * Starting an attempt is when option text is released. Before this, the
     * paper exposes only question count and metadata — otherwise the bank
     * could be harvested by listing tests.
     */
    public function attempt(Request $request, string $testId): JsonResponse
    {
        $test = Test::query()->with('questions.question.translations')->findOrFail($testId);
        $this->assertCanUse($test);
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
                    // In the paper's own language, not the bank's master.
                    'question_text' => $tq->question->textIn($test->language_id)?->question_text,
                    'options' => $tq->question->textIn($test->language_id)?->optionMap(),
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
    public function solutions(Request $request, string $attemptId): JsonResponse
    {
        $attempt = TestAttempt::query()->findOrFail($attemptId);

        // Another student's solutions reveal their answers; only the student
        // and those who assign tests (teachers, admins) may see them. A
        // practice paper someone uploaded is not a test anyone assigned, so
        // there it is the uploader (or a platform admin) only.
        $practice = $attempt->test !== null && PracticePaper::is($attempt->test);
        abort_unless(
            $attempt->user_id === app(TenantContext::class)->userId
                || ($practice
                    ? PracticePaper::canManage($attempt->test, $request->user())
                    : $request->user()->can('tests.create.org')),
            404,
            'Not found',
        );
        abort_unless($attempt->isSubmitted(), 409, 'Submit the attempt before viewing solutions.');

        $test = $attempt->test()->with('questions.question.translations', 'questions.question.options', 'questions.question.sources.chunk')->first();

        return response()->json([
            'data' => $test->questions->map(fn ($tq) => [
                'sequence' => $tq->sequence,
                'question_text' => $tq->question->textIn($test->language_id)?->question_text,
                'options' => $tq->question->textIn($test->language_id)?->optionMap(),
                'correct_option' => $tq->question->correctOptionKey(),
                'explanation' => $tq->question->textIn($test->language_id)?->explanation,
                // Every solution shows where the answer comes from. This is
                // what a student can take to a teacher and check.
                'sources' => $tq->question->sources->map(fn ($s) => [
                    'citation' => $s->citation(),
                    'supporting_quote' => $s->supporting_quote,
                ])->all(),
            ])->all(),
        ]);
    }

    /**
     * A test is usable by whoever made it, by anyone when shared, and by the
     * people who assign tests in the organization. Tenant scope has already
     * kept other organizations out; this keeps one student out of another's.
     */
    private function assertCanUse(Test $test): void
    {
        $user = request()->user();

        // An uploaded practice paper is its uploader's, whatever role is asking.
        if (PracticePaper::is($test)) {
            abort_unless($user !== null && PracticePaper::canManage($test, $user), 404, 'Not found');

            return;
        }

        abort_unless(
            $test->created_by === $user?->getKey()
                || $test->is_shared
                || $user?->can('tests.create.org')
                || $user?->isPlatformAdmin(),
            404,
            'Not found',
        );
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
