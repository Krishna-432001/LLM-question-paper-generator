<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tests\AssembleExamPatternTestAction;
use App\Actions\Tests\AssembleTestAction;
use App\Actions\Tests\EvaluateAttemptAction;
use App\Domain\PracticePapers\PracticePaper;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tests\Exceptions\ExamPatternShortfall;
use App\Domain\Tests\Exceptions\NotEnoughQuestions;
use App\Domain\Tests\PaperText;
use App\Domain\Translation\TranslationRequester;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tests\CreateExamPatternTestRequest;
use App\Http\Requests\Tests\CreateTestRequest;
use App\Http\Requests\Tests\SubmitAttemptRequest;
use App\Models\Test;
use App\Models\TestAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TestController extends Controller
{
    public function store(CreateTestRequest $request, AssembleTestAction $assemble): JsonResponse
    {
        $context = app(TenantContext::class);
        $topics = $this->topicScope($request);

        // Topics chosen, none of them filed in the bank: refused, rather than
        // quietly widened to the whole syllabus.
        if ($topics === []) {
            return $this->shortage($request, 'None of the chosen topics has any questions linked to it yet.');
        }

        try {
            $test = $assemble->execute(($topics === null ? [] : ['canonical_topic_ids' => $topics]) + [
                'organization_id' => $context->organizationId,
                'user_id' => $context->userId,
                'exam_id' => $request->string('exam_id')->toString(),
                'language_id' => $request->string('language_id')->toString(),
                'language_mode' => $request->input('language_mode', Test::MODE_SINGLE),
                'secondary_language_id' => $request->input('secondary_language_id'),
                'type' => $request->string('type')->toString(),
                'question_count' => $request->integer('question_count'),
                'title' => $request->input('title', 'Practice Test'),
                'difficulty_mix' => $request->array('difficulty_mix'),
                'duration_minutes' => $request->input('duration_minutes'),
                'negative_marking' => $request->input('negative_marking', 0),
            ]);
        } catch (NotEnoughQuestions $e) {
            return $this->shortage($request, $e->getMessage());
        }

        if ($test->status === 'failed') {
            return $this->shortage($request, 'The question bank has no approved questions matching that request yet.');
        }

        return response()->json(['data' => $this->shape($test)], 201);
    }

    /**
     * The bank topics a topic-wise test is drawn from: every syllabus topic
     * of the chosen units, and each chosen topic with its sub-topics. Null
     * when none was chosen (the whole syllabus); empty when the choices map
     * to nothing in the bank.
     *
     * @return list<string>|null
     */
    private function topicScope(CreateTestRequest $request): ?array
    {
        $unitIds = array_map('strtolower', $request->array('exam_unit_ids'));
        $topicIds = array_map('strtolower', $request->array('exam_topic_ids'));

        if ($unitIds === [] && $topicIds === []) {
            return null;
        }

        $chosen = DB::table('exam_topics')->whereIn('id', $topicIds)->get(['syllabus_version_id', 'path']);

        return DB::table('exam_topics as t')
            ->where(function ($q) use ($unitIds, $chosen): void {
                $q->whereIn('t.exam_unit_id', $unitIds);

                foreach ($chosen as $topic) {
                    // A topic covers its sub-topics: everything under its path.
                    $q->orWhere(fn ($w) => $w
                        ->where('t.syllabus_version_id', $topic->syllabus_version_id)
                        ->whereRaw('t.path <@ ?::ltree', [$topic->path]));
                }
            })
            ->whereNotNull('t.canonical_topic_id')
            ->distinct()
            ->pluck('t.canonical_topic_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * A full mock in the exam's pattern: its parts, counts, marks and time.
     * When the bank cannot fill every part yet, the refusal carries the count
     * for each part and whether a shorter mock can be built from what exists.
     */
    public function storeExamPattern(CreateExamPatternTestRequest $request, AssembleExamPatternTestAction $assemble): JsonResponse
    {
        $context = app(TenantContext::class);

        try {
            $test = $assemble->execute([
                'organization_id' => $context->organizationId,
                'user_id' => $context->userId,
                'exam_id' => $request->string('exam_id')->toString(),
                'language_id' => $request->string('language_id')->toString(),
                'language_mode' => $request->input('language_mode', Test::MODE_SINGLE),
                'secondary_language_id' => $request->input('secondary_language_id'),
                'type' => $request->input('type', 'model'),
                'title' => $request->input('title'),
                'allow_short' => $request->boolean('allow_short'),
            ]);
        } catch (ExamPatternShortfall $e) {
            $response = $this->shortage($request, $e->getMessage(), 200);
            $body = $response->getData(true);
            $body['code'] = 'exam_pattern_short';
            $body['meta'] += ['sections' => $e->sections, 'available_total' => $e->availableTotal()];

            return response()->json($body, 422);
        }

        if ($test->status === 'failed') {
            return $this->shortage($request, 'The question bank has no approved questions matching that request yet.', 200);
        }

        return response()->json(['data' => $this->shape($test->load('questions'))], 201);
    }

    /**
     * Too few questions in the paper's language(s). Refused — a short paper
     * would mislead — but the missing translations are queued in the same
     * breath, so asking again later succeeds instead of failing the same way.
     * Translating is idempotent per exam and language: repeated requests
     * return the job already running rather than starting another.
     */
    private function shortage(CreateTestRequest|CreateExamPatternTestRequest $request, string $detail, ?int $wanted = null): JsonResponse
    {
        $languages = array_values(array_unique(array_filter([
            strtolower($request->string('language_id')->toString()),
            $request->input('language_mode') === Test::MODE_BILINGUAL
                ? strtolower((string) $request->input('secondary_language_id'))
                : null,
        ])));

        $requester = app(TranslationRequester::class);
        $jobs = [];

        foreach ($languages as $languageId) {
            $job = $requester->queueMissing(
                $request->string('exam_id')->toString(),
                $languageId,
                max(20, 2 * ($wanted ?? $request->integer('question_count'))),
                app(TenantContext::class)->userId,
            );

            if ($job !== null) {
                $jobs[] = ['job_id' => $job->getKey(), 'language_id' => $languageId, 'status' => $job->status->value];
            }
        }

        if ($jobs !== []) {
            $detail .= ' Translation of the missing questions has started; try again in a few minutes.';
        }

        return response()->json([
            'type' => 'https://api.examforge.in/problems/not-enough-questions',
            'title' => 'Not enough questions',
            'status' => 422,
            'detail' => $detail,
            'instance' => $request->path(),
            'meta' => ['translation_jobs' => $jobs],
        ], 422);
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
        // The pinned rows: the runner shows exactly the text the paper was
        // assembled with, which is also what its PDF prints.
        $test = Test::query()->with(PaperText::RELATIONS)->findOrFail($testId);
        $this->assertCanUse($test);
        $context = app(TenantContext::class);
        $languages = $test->paperLanguages();

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
                'language_mode' => $test->language_mode,
                'languages' => PaperText::languagesOf($test),
                'questions' => $test->questions->map(fn ($tq) => [
                    'test_question_id' => $tq->getKey(),
                    'sequence' => $tq->sequence,
                    'marks' => $tq->marks,
                    // The part of the paper it belongs to (null outside a mock in parts).
                    'section' => $tq->section,
                    // In the paper's own languages, not the bank's master:
                    // question_text/options for the primary, `texts` for
                    // every language, primary first. The correct option is
                    // never sent to the client.
                ] + PaperText::payloadFor($tq, $languages))->all(),
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
                'section_breakdown' => $result->section_breakdown ?? [],
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

        $test = $attempt->test()->with([...PaperText::RELATIONS, 'questions.question.options', 'questions.question.sources.chunk'])->first();
        $languages = $test->paperLanguages();

        return response()->json([
            'data' => $test->questions->map(function ($tq) use ($languages): array {
                // The same pinned text the student answered. A bilingual
                // paper's solutions are bilingual too, explanation included.
                $text = PaperText::payloadFor($tq, $languages, withExplanation: true);

                return [
                    'sequence' => $tq->sequence,
                    'section' => $tq->section,
                    'question_text' => $text['question_text'],
                    'options' => $text['options'],
                    'correct_option' => $tq->question->correctOptionKey(),
                    'explanation' => $text['explanation'],
                    'texts' => $text['texts'],
                    // Every solution shows where the answer comes from. This
                    // is what a student can take to a teacher and check.
                    'sources' => $tq->question->sources->map(fn ($s) => [
                        'citation' => $s->citation(),
                        'supporting_quote' => $s->supporting_quote,
                    ])->all(),
                ];
            })->all(),
            // Alongside the list rather than inside it, so a client that reads
            // data as an array of solutions keeps working.
            'meta' => [
                'language_mode' => $test->language_mode,
                'languages' => PaperText::languagesOf($test),
            ],
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
            'language_id' => $test->language_id,
            'language_mode' => $test->language_mode,
            'secondary_language_id' => $test->secondary_language_id,
            'languages' => PaperText::languagesOf($test),
            'total_marks' => $test->total_marks,
            'duration_minutes' => $test->duration_minutes,
            'negative_marking' => $test->negative_marking,
            'selection_profile' => $test->selection_profile,
            'blueprint_id' => $test->blueprint_id,
            // A mock in parts, as the paper lays them out: name, questions, marks.
            'sections' => $test->questions
                ->filter(static fn ($tq): bool => $tq->section !== null)
                ->sortBy('sequence')
                ->groupBy('section')
                ->map(static fn ($questions, string $name): array => [
                    'name' => $name,
                    'questions' => $questions->count(),
                    'marks' => (float) $questions->sum('marks'),
                ])
                ->values()
                ->all(),
        ];
    }
}
