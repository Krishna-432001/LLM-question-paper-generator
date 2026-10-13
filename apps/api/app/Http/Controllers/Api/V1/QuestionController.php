<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Jobs\DispatchTrackedJobAction;
use App\Domain\PracticePapers\PracticePaper;
use App\Domain\Questions\TargetPlanner;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Questions\GenerateQuestionsRequest;
use App\Jobs\Generation\GenerateQuestions;
use App\Models\GenerationPolicy;
use App\Models\Question;
use App\Models\Source;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class QuestionController extends Controller
{
    public function generate(
        GenerateQuestionsRequest $request,
        DispatchTrackedJobAction $dispatch,
        TargetPlanner $planner,
    ): JsonResponse {
        $source = Source::query()->findOrFail($request->string('source_id')->toString());
        $context = app(TenantContext::class);

        abort_unless($source->isReady(), 409, 'This source has not finished ingestion.');

        $requested = $request->integer('target_count');

        // Order the shortfall, not the whole number. Asking for 50 when 43 are
        // already approved otherwise pays for 50 and discards 43 at the dedup
        // stage — after the tokens are spent.
        $plan = $planner->planRequest(
            requested: $requested,
            languageId: $request->string('language_id')->toString(),
            examId: $request->input('exam_id'),
            canonicalTopicId: $request->input('canonical_topic_id'),
            difficulty: $request->input('difficulty'),
            countPending: $request->boolean('count_pending', true),
        );

        // Nothing to do is a legitimate, useful answer, and a far better one
        // than a job that spends money to discover the same thing.
        if ($plan->isSatisfied()) {
            return response()->json([
                'data' => [
                    'job_id' => null,
                    'status' => 'SKIPPED',
                    'plan' => $plan->toArray(),
                    'message' => "Nothing to generate: {$plan->approved} approved"
                        .($plan->pending > 0 ? " and {$plan->pending} awaiting review" : '')
                        ." already meet the target of {$requested}.",
                ],
            ], 200);
        }

        $target = $plan->deficit;
        $policy = GenerationPolicy::resolve($request->input('exam_id'));

        $job = $dispatch->execute(
            type: 'generation',
            jobClass: GenerateQuestions::class,
            input: [
                'source_id' => $source->getKey(),
                'exam_id' => $request->input('exam_id'),
                'language_id' => $request->string('language_id')->toString(),
                'target_count' => $target,
                'formats' => $request->input('formats', ['mcq4']),
                'book_lesson_ids' => $request->input('book_lesson_ids'),
            ],
            organizationId: $source->organization_id,
            userId: $context->userId,
            subjectType: Source::class,
            subjectId: $source->getKey(),
            idempotencyKey: $request->header('Idempotency-Key'),
        );

        return response()->json([
            'data' => [
                'job_id' => $job->getKey(),
                'status' => $job->status->value,
                'poll_url' => "/api/v1/jobs/{$job->getKey()}",
                // Every number, not just the order: "we generated 7" is only
                // trustworthy next to "because 43 are approved".
                'plan' => $plan->toArray(),
                'planned' => [
                    // Surfaced so the caller can see the overgeneration up front
                    // rather than being surprised by the token spend.
                    'target_approved' => $target,
                    'raw_questions' => $policy->rawTargetFor($target),
                    'overgeneration_factor' => (float) $policy->overgeneration_factor,
                ],
            ],
        ], 202);
    }

    public function index(Request $request): JsonResponse
    {
        $questions = Question::query()
            // A student's uploaded practice paper, answers included, is theirs alone.
            ->tap(fn ($q) => PracticePaper::hidePrivateQuestions($q, $request->user()))
            ->with(['options', 'master', 'sources'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            // Scoped to one book: the admin console reviews what a single
            // upload produced, not the whole bank.
            ->when($request->filled('source_id'), fn ($q) => $q->whereHas(
                'sources',
                fn ($s) => $s->where('source_id', $request->string('source_id')),
            ))
            ->when($request->filled('difficulty'), fn ($q) => $q->where('difficulty', $request->string('difficulty')))
            ->when($request->filled('language_id'), fn ($q) => $q->availableIn($request->string('language_id')->toString()))
            ->orderByDesc('quality_score')
            ->limit(min(100, $request->integer('limit', 25)))
            ->get();

        return response()->json(['data' => $questions->map($this->shape(...))->all()]);
    }

    public function show(string $questionId): JsonResponse
    {
        $question = Question::query()
            ->tap(fn ($q) => PracticePaper::hidePrivateQuestions($q, request()->user()))
            ->with(['options', 'translations', 'sources.chunk', 'validations'])
            ->findOrFail($questionId);

        return response()->json([
            'data' => $this->shape($question) + [
                'translations' => $question->translations->mapWithKeys(fn ($t) => [
                    $t->language->code ?? $t->language_id => [
                        'question_text' => $t->question_text,
                        'options' => $t->optionMap(),
                        'explanation' => $t->explanation,
                        'status' => $t->translation_status,
                        'is_master' => $t->is_master,
                    ],
                ])->all(),
                'validations' => $question->validations->map(fn ($v) => [
                    'check' => $v->check_code,
                    'passed' => $v->passed,
                    'score' => $v->score,
                ])->all(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function shape(Question $question): array
    {
        $master = $question->master;

        return [
            'id' => $question->getKey(),
            'status' => $question->status,
            'difficulty' => $question->difficulty,
            'bloom_level' => $question->bloom_level,
            'quality_score' => $question->quality_score,
            'ai_confidence' => $question->ai_confidence,
            'question_text' => $master?->question_text,
            'options' => $master?->optionMap(),
            // The answer comes from question_options, never from a translation.
            'correct_option' => $question->correctOptionKey(),
            'explanation' => $master?->explanation,
            // Every question carries its citation. This is the trust surface.
            'sources' => $question->sources->map(fn ($s) => [
                'citation' => $s->citation(),
                'page_start' => $s->page_start,
                'page_end' => $s->page_end,
                'supporting_quote' => $s->supporting_quote,
            ])->all(),
        ];
    }
}
