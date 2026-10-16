<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\PracticePapers\CreatePracticePaperAction;
use App\Actions\PracticePapers\SetAnswerKeyAction;
use App\Domain\PracticePapers\Exceptions\PracticePaperException;
use App\Domain\PracticePapers\PracticePaper;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\PracticePapers\StorePracticePaperRequest;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\TestResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Question papers a student uploads and sits as timed tests.
 *
 * Only the upload, the list and the answer key live here. Sitting the paper,
 * submitting it and reading the solutions go through the ordinary test
 * endpoints, because a practice paper is an ordinary test once it exists.
 */
final class PracticePaperController extends Controller
{
    /** The student's own uploaded papers, newest first, with how they have done on each. */
    public function index(): JsonResponse
    {
        $userId = app(TenantContext::class)->userId;

        $tests = Test::query()
            ->where('created_by', $userId)
            ->where('type', 'custom')
            ->where('selection_profile->kind', PracticePaper::KIND)
            ->latest('created_at')
            ->limit(100)
            ->get();

        $ids = $tests->modelKeys();

        // Read live rather than from the upload report: a key added later
        // has to show here straight away.
        $keyed = DB::table('test_questions as tq')
            ->join('question_options as o', 'o.question_id', '=', 'tq.question_id')
            ->whereIn('tq.test_id', $ids)
            ->where('o.is_correct', true)
            ->groupBy('tq.test_id')
            ->selectRaw('tq.test_id, count(*) AS keyed')
            ->pluck('keyed', 'test_id');

        $attempts = DB::table('test_attempts as a')
            ->leftJoin('test_results as r', 'r.test_attempt_id', '=', 'a.id')
            ->whereIn('a.test_id', $ids)
            ->where('a.user_id', $userId)
            ->orderByDesc('a.started_at')
            ->get(['a.id', 'a.test_id', 'a.status', 'r.final_score', 'r.accuracy'])
            ->groupBy('test_id');

        return response()->json([
            'data' => $tests->map(function (Test $test) use ($keyed, $attempts): array {
                $mine = $attempts->get($test->getKey(), collect());
                $best = $mine->whereNotNull('final_score')->sortByDesc('final_score')->first();
                $report = $test->selection_profile['report'] ?? [];

                return [
                    'test_id' => $test->getKey(),
                    'title' => $test->title,
                    'question_count' => $test->question_count,
                    'keyed' => (int) ($keyed[$test->getKey()] ?? 0),
                    'duration_minutes' => $test->duration_minutes,
                    'languages_detected' => $report['languages_detected'] ?? [],
                    'skipped' => (int) ($report['skipped'] ?? 0),
                    'created_at' => $test->created_at?->toIso8601String(),
                    'attempts' => $mine->count(),
                    'best_score' => $best?->final_score === null ? null : (float) $best->final_score,
                    'best_accuracy' => $best?->accuracy === null ? null : (float) $best->accuracy,
                    'open_attempt_id' => $mine->firstWhere('status', 'in_progress')?->id,
                ];
            })->all(),
        ]);
    }

    /**
     * 201 with the parse report when the PDF had text; 202 with a job id when
     * it is a scan going through OCR. The job's output is the same report.
     */
    public function store(StorePracticePaperRequest $request, CreatePracticePaperAction $create): JsonResponse
    {
        try {
            $result = $create->execute(
                user: $request->user(),
                file: $request->file('file'),
                title: $request->string('title')->trim()->toString(),
                durationMinutes: $request->filled('duration_minutes') ? $request->integer('duration_minutes') : null,
                examId: $request->filled('exam_id') ? $request->string('exam_id')->toString() : null,
                language: $request->filled('language') ? $request->string('language')->toString() : null,
                readAsScan: $request->boolean('ocr'),
            );
        } catch (PracticePaperException $e) {
            return $this->problem($request, $e);
        }

        if ($result['status'] === 'queued') {
            return response()->json([
                'data' => [
                    'job_id' => $result['job_id'],
                    'status' => 'queued',
                    'message' => ($request->boolean('ocr')
                        ? 'As asked, the pages are being read from their images with OCR. '
                        : 'This PDF is a scan, so its pages are being read with OCR. ')
                        .'The test will be ready when the job completes.',
                ],
            ], 202);
        }

        return response()->json(['data' => $result['report']], 201);
    }

    /** "1-A 2-C 3-B …" as text, or a {"1": "A"} map, under the field `key`. */
    public function answerKey(Request $request, string $testId, SetAnswerKeyAction $setKey): JsonResponse
    {
        // Ownership before validation: someone else's paper is a 404 whatever
        // they send, never a validation error that confirms it exists.
        $test = $this->paperFor($request, $testId);

        $key = $request->validate(['key' => ['required', 'max:20000']])['key'];

        abort_unless(is_string($key) || is_array($key), 422, 'Send the key as text ("1-A 2-C") or as a map of question number to option.');

        try {
            $result = $setKey->execute($test, $key);
        } catch (PracticePaperException $e) {
            return $this->problem($request, $e);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * How many questions on a submitted attempt had no answer key. The
     * result screen needs it to say "not scored" rather than let a paper
     * with no key read as a score of zero.
     */
    public function scoring(string $attemptId): JsonResponse
    {
        $attempt = Str::isUuid($attemptId) ? TestAttempt::query()->find($attemptId) : null;

        abort_if(
            $attempt === null || $attempt->user_id !== app(TenantContext::class)->userId,
            404,
            'Not found',
        );

        $result = TestResult::query()->where('test_attempt_id', $attempt->getKey())->first();

        return response()->json([
            'data' => [
                'attempt_id' => $attempt->getKey(),
                'submitted' => $attempt->isSubmitted(),
                'total_questions' => $result?->total_questions,
                'unscored' => (int) ($result?->unscored ?? 0),
                'scored' => $result === null ? null : $result->total_questions - (int) $result->unscored,
            ],
        ]);
    }

    /**
     * The same RFC 7807 shape every API error has, plus `code`: the student
     * app shows these in Tamil as well as English, and translates by code.
     */
    private function problem(Request $request, PracticePaperException $e): JsonResponse
    {
        return response()->json([
            'type' => 'https://api.examforge.in/problems/practice-paper',
            'title' => 'Request cannot be processed',
            'status' => 422,
            'detail' => $e->getMessage(),
            'code' => $e->reason,
            'instance' => $request->path(),
            'meta' => ['request_id' => (string) $request->header('X-Request-Id', (string) Str::uuid7())],
        ], 422);
    }

    private function paperFor(Request $request, string $testId): Test
    {
        $test = Str::isUuid($testId) ? Test::query()->find($testId) : null;

        abort_if($test === null || ! PracticePaper::canManage($test, $request->user()), 404, 'Not found');

        return $test;
    }
}
