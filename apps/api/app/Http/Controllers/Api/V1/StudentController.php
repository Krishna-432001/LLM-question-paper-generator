<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tests\PaperText;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\Language;
use App\Models\Test;
use App\Models\TestAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The student's own view: profile, history, resuming, and progress.
 *
 * Everything here is a read of what the student has already done — no model
 * is called and nothing is generated, so every screen is a query.
 */
final class StudentController extends Controller
{
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'timezone' => ['sometimes', 'timezone'],
            'preferred_language_id' => ['sometimes', 'nullable', 'uuid', 'exists:languages,id'],
            'target_exam_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        if (isset($data['target_exam_id'])) {
            // Through the tenant scope: a student may only target an exam they
            // can see (platform exams, or their own institute's).
            Exam::query()->active()->findOrFail($data['target_exam_id']);
        }

        if (isset($data['preferred_language_id'])) {
            $data['locale'] = Language::query()->whereKey($data['preferred_language_id'])->value('code') ?? $user->locale;
        }

        $user->forceFill($data)->save();

        return response()->json(['data' => $this->profile($request)]);
    }

    public function profileShow(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->profile($request)]);
    }

    /** Tests the student made or was given, newest first, with their best try. */
    public function tests(Request $request): JsonResponse
    {
        $userId = app(TenantContext::class)->userId;

        $tests = Test::query()
            ->with(['language', 'secondaryLanguage'])
            ->where(fn ($q) => $q->where('created_by', $userId)->orWhere('is_shared', true))
            ->when($request->filled('exam_id'), fn ($q) => $q->where('exam_id', $request->string('exam_id')))
            ->latest('created_at')
            ->limit(100)
            ->get();

        $attempts = DB::table('test_attempts as a')
            ->leftJoin('test_results as r', 'r.test_attempt_id', '=', 'a.id')
            ->whereIn('a.test_id', $tests->pluck('id'))
            ->where('a.user_id', $userId)
            ->orderByDesc('a.started_at')
            ->get(['a.id', 'a.test_id', 'a.status', 'a.started_at', 'a.submitted_at', 'r.final_score', 'r.accuracy'])
            ->groupBy('test_id');

        return response()->json([
            'data' => $tests->map(function (Test $test) use ($attempts): array {
                $mine = $attempts->get($test->getKey(), collect());
                $best = $mine->whereNotNull('final_score')->sortByDesc('final_score')->first();
                $open = $mine->firstWhere('status', 'in_progress');

                return [
                    'id' => $test->getKey(),
                    'title' => $test->title,
                    'type' => $test->type,
                    'question_count' => $test->question_count,
                    'duration_minutes' => $test->duration_minutes,
                    // So the list can say "Tamil + English" and a download
                    // button can promise the format it will produce.
                    'language_mode' => $test->language_mode,
                    'languages' => $test->paperLanguages()->pluck('code')->all(),
                    'created_at' => $test->created_at?->toIso8601String(),
                    'attempts' => $mine->count(),
                    'best_score' => $best?->final_score === null ? null : (float) $best->final_score,
                    'best_accuracy' => $best?->accuracy === null ? null : (float) $best->accuracy,
                    'open_attempt_id' => $open?->id,
                ];
            })->all(),
        ]);
    }

    public function attempts(Request $request): JsonResponse
    {
        $rows = DB::table('test_attempts as a')
            ->join('tests as t', 't.id', '=', 'a.test_id')
            ->leftJoin('test_results as r', 'r.test_attempt_id', '=', 'a.id')
            ->where('a.user_id', app(TenantContext::class)->userId)
            ->when($request->filled('exam_id'), fn ($q) => $q->where('t.exam_id', $request->string('exam_id')))
            ->orderByDesc('a.started_at')
            ->limit(min(200, max(1, (int) $request->query('limit', 50))))
            ->get([
                'a.id', 'a.test_id', 't.title', 't.type', 'a.status', 'a.started_at', 'a.submitted_at',
                'r.total_questions', 'r.correct', 'r.wrong', 'r.final_score', 'r.accuracy', 'r.time_taken_seconds',
            ]);

        return response()->json(['data' => $rows->map(fn ($r) => [
            'attempt_id' => $r->id,
            'test_id' => $r->test_id,
            'title' => $r->title,
            'type' => $r->type,
            'status' => $r->status,
            'started_at' => CarbonImmutable::parse($r->started_at)->toIso8601String(),
            'submitted_at' => $r->submitted_at ? CarbonImmutable::parse($r->submitted_at)->toIso8601String() : null,
            'total_questions' => $r->total_questions,
            'correct' => $r->correct,
            'wrong' => $r->wrong,
            'final_score' => $r->final_score === null ? null : (float) $r->final_score,
            'accuracy' => $r->accuracy === null ? null : (float) $r->accuracy,
            'time_taken_seconds' => $r->time_taken_seconds,
        ])->all()]);
    }

    /**
     * One attempt: the paper again if it is still open (so a reload or a
     * dropped connection resumes rather than restarts), or the result.
     */
    public function attempt(string $attemptId): JsonResponse
    {
        $attempt = TestAttempt::query()->with('test')->findOrFail($attemptId);
        abort_unless($attempt->user_id === app(TenantContext::class)->userId, 404, 'Not found');

        if (! $attempt->isSubmitted()) {
            $test = Test::query()->with('questions.question.translations')->findOrFail($attempt->test_id);
            $languages = $test->paperLanguages();

            // Resuming must show the paper exactly as it was started: a
            // bilingual paper that came back single-language after a reload
            // would change under the student mid-test.
            return response()->json(['data' => [
                'attempt_id' => $attempt->getKey(),
                'status' => $attempt->status,
                'test' => [
                    'id' => $test->getKey(),
                    'title' => $test->title,
                    'duration_minutes' => $test->duration_minutes,
                    'language_mode' => $test->language_mode,
                ],
                'language_mode' => $test->language_mode,
                'languages' => PaperText::languagesOf($test),
                'started_at' => $attempt->started_at?->toIso8601String(),
                'expires_at' => $attempt->expires_at?->toIso8601String(),
                'questions' => $test->questions->map(fn ($tq) => [
                    'test_question_id' => $tq->getKey(),
                    'sequence' => $tq->sequence,
                    'marks' => $tq->marks,
                    'question_text' => $tq->question->textIn($test->language_id)?->question_text,
                    'options' => $tq->question->textIn($test->language_id)?->optionMap(),
                    'texts' => PaperText::byLanguage($tq->question, $languages),
                ])->all(),
            ]]);
        }

        $result = DB::table('test_results')->where('test_attempt_id', $attempt->getKey())->first();
        $topicNames = DB::table('canonical_topics')
            ->whereIn('id', array_keys((array) json_decode((string) ($result->topic_breakdown ?? '{}'), true)))
            ->pluck('name', 'id');

        return response()->json(['data' => [
            'attempt_id' => $attempt->getKey(),
            'status' => $attempt->status,
            'test' => ['id' => $attempt->test_id, 'title' => $attempt->test?->title],
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'result' => $result === null ? null : [
                'total_questions' => $result->total_questions,
                'attempted' => $result->attempted,
                'correct' => $result->correct,
                'wrong' => $result->wrong,
                'unattempted' => $result->unattempted,
                'final_score' => (float) $result->final_score,
                'accuracy' => (float) $result->accuracy,
                'time_taken_seconds' => $result->time_taken_seconds,
                'topics' => collect(json_decode((string) $result->topic_breakdown, true) ?: [])
                    ->map(fn ($v, $id) => ['topic' => $topicNames[$id] ?? 'Other'] + (array) $v)
                    ->values()->all(),
                'difficulty' => json_decode((string) $result->difficulty_breakdown, true) ?: [],
            ],
        ]]);
    }

    /**
     * Where the student stands on one exam: mastery per topic, the weakest
     * topics with enough evidence to trust, accuracy over time, and a streak.
     */
    public function progress(Request $request): JsonResponse
    {
        $userId = app(TenantContext::class)->userId;
        $examId = $request->filled('exam_id')
            ? $request->string('exam_id')->toString()
            : $request->user()->target_exam_id;

        abort_if($examId === null, 422, 'Choose an exam first.');
        $days = min(90, max(7, (int) $request->query('days', 30)));
        $timezone = $request->user()->timezone ?: 'Asia/Kolkata';

        $mastery = DB::table('topic_mastery as m')
            ->join('canonical_topics as t', 't.id', '=', 'm.canonical_topic_id')
            ->where('m.user_id', $userId)
            ->where('m.exam_id', $examId)
            ->orderBy('m.mastery')
            ->get(['m.canonical_topic_id', 't.name', 'm.attempts', 'm.correct', 'm.mastery', 'm.last_practiced_at', 'm.next_review_at'])
            ->map(fn ($m) => [
                'canonical_topic_id' => $m->canonical_topic_id,
                'topic' => $m->name,
                'attempts' => (int) $m->attempts,
                'correct' => (int) $m->correct,
                'mastery' => round((float) $m->mastery, 4),
                'last_practiced_at' => $m->last_practiced_at,
                'review_due' => $m->next_review_at !== null && CarbonImmutable::parse($m->next_review_at)->isPast(),
            ]);

        // Weak needs evidence: one wrong answer is noise, five is a pattern.
        $weak = $mastery->filter(fn ($m) => $m['attempts'] >= 5 && $m['mastery'] < 0.55)->take(5)->values();
        $strong = $mastery->filter(fn ($m) => $m['attempts'] >= 5 && $m['mastery'] >= 0.85)->sortByDesc('mastery')->take(5)->values();

        $from = CarbonImmutable::now($timezone)->startOfDay()->subDays($days - 1);

        $daily = DB::table('test_attempts as a')
            ->join('test_results as r', 'r.test_attempt_id', '=', 'a.id')
            ->join('tests as t', 't.id', '=', 'a.test_id')
            ->where('a.user_id', $userId)
            ->where('t.exam_id', $examId)
            ->where('a.submitted_at', '>=', $from->utc())
            ->selectRaw('(a.submitted_at AT TIME ZONE ?)::date AS day, sum(r.correct) AS correct, sum(r.attempted) AS attempted, count(*) AS tests', [$timezone])
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($r) => (string) $r->day);

        $trend = [];

        for ($d = $from; $d->lte(CarbonImmutable::now($timezone)); $d = $d->addDay()) {
            $row = $daily->get($d->toDateString());
            $trend[] = [
                'date' => $d->toDateString(),
                'tests' => (int) ($row->tests ?? 0),
                'accuracy' => $row !== null && (int) $row->attempted > 0
                    ? round((int) $row->correct / (int) $row->attempted * 100, 1)
                    : null,
            ];
        }

        $totals = DB::table('test_attempts as a')
            ->join('test_results as r', 'r.test_attempt_id', '=', 'a.id')
            ->join('tests as t', 't.id', '=', 'a.test_id')
            ->where('a.user_id', $userId)
            ->where('t.exam_id', $examId)
            ->selectRaw('count(*) AS tests, coalesce(sum(r.attempted), 0) AS attempted, coalesce(sum(r.correct), 0) AS correct')
            ->first();

        return response()->json(['data' => [
            'exam_id' => $examId,
            'totals' => [
                'tests' => (int) $totals->tests,
                'questions_answered' => (int) $totals->attempted,
                'accuracy' => (int) $totals->attempted > 0 ? round((int) $totals->correct / (int) $totals->attempted * 100, 1) : null,
                'topics_practised' => $mastery->count(),
            ],
            'streak_days' => $this->streak($userId, $timezone),
            'weak_topics' => $weak,
            'strong_topics' => $strong,
            'review_due' => $mastery->where('review_due', true)->count(),
            'mastery' => $mastery->values(),
            'trend' => $trend,
        ]]);
    }

    /**
     * Consecutive days, ending today or yesterday, on which the student
     * submitted a test or finished a plan item. Today not being done yet
     * does not break a streak until the day is over.
     */
    private function streak(string $userId, string $timezone): int
    {
        $active = DB::query()->fromSub(
            DB::table('test_attempts')
                ->where('user_id', $userId)
                ->whereNotNull('submitted_at')
                ->selectRaw('(submitted_at AT TIME ZONE ?)::date AS day', [$timezone])
                ->union(
                    DB::table('study_plan_items as i')
                        ->join('study_plan_days as d', 'd.id', '=', 'i.study_plan_day_id')
                        ->join('study_plans as p', 'p.id', '=', 'd.study_plan_id')
                        ->where('p.user_id', $userId)
                        ->whereNotNull('i.completed_at')
                        ->selectRaw('(i.completed_at AT TIME ZONE ?)::date AS day', [$timezone])
                ),
            'activity',
        )->distinct()->pluck('day')->map(fn ($d) => (string) $d)->flip();

        $day = CarbonImmutable::now($timezone)->startOfDay();

        if (! $active->has($day->toDateString())) {
            $day = $day->subDay();
        }

        $streak = 0;

        while ($active->has($day->toDateString())) {
            $streak++;
            $day = $day->subDay();
        }

        return $streak;
    }

    /** @return array<string, mixed> */
    private function profile(Request $request): array
    {
        $user = $request->user()->fresh();
        $exam = $user->target_exam_id ? Exam::query()->find($user->target_exam_id) : null;

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => $user->timezone,
            'locale' => $user->locale,
            'preferred_language_id' => $user->preferred_language_id,
            'target_exam' => $exam === null ? null : ['id' => $exam->getKey(), 'code' => $exam->code, 'name' => $exam->name],
        ];
    }
}
