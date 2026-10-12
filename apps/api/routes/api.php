<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\AiHistoryController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\CatalogueController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\TrendController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\ExamController;
use App\Http\Controllers\Api\V1\GenerationTargetController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PyqController;
use App\Http\Controllers\Api\V1\QuestionController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\StructureController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\StudyPlanController;
use App\Http\Controllers\Api\V1\SyllabusController;
use App\Http\Controllers\Api\V1\TestController;
use App\Http\Controllers\Api\V1\SourceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {

    /*
    | Public — no tenant context exists yet.
    */
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:10,60');
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:20,1');
    Route::get('languages', [CatalogueController::class, 'languages']);

    /*
    | Authenticated. ResolveTenant runs globally on the api group, so every
    | route below has a resolved organization and RLS session variables set.
    */
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('me', [MeController::class, 'show']);

        // The student's own surface: profile, history, resume, progress.
        Route::get('me/profile', [StudentController::class, 'profileShow']);
        Route::patch('me/profile', [StudentController::class, 'updateProfile']);
        Route::get('me/tests', [StudentController::class, 'tests']);
        Route::get('me/attempts', [StudentController::class, 'attempts']);
        Route::get('me/progress', [StudentController::class, 'progress']);
        Route::get('attempts/{attempt}', [StudentController::class, 'attempt']);
        Route::get('me/organizations', [MeController::class, 'organizations']);

        // One aggregated call: a dashboard that issues a query per card
        // becomes the slowest page and is then loaded on every navigation.
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('dashboard/trend', [TrendController::class, 'index']);

        Route::get('exams', [CatalogueController::class, 'exams']);
        Route::post('exams', [ExamController::class, 'store']);
        Route::get('exams/{exam}', [ExamController::class, 'show']);
        Route::patch('exams/{exam}', [ExamController::class, 'update']);
        Route::patch('exams/{exam}/papers/{paper}', [ExamController::class, 'updatePaper']);
        Route::get('exams/{exam}/syllabus', [ExamController::class, 'syllabus']);
        Route::post('exams/{exam}/syllabus/drafts', [SyllabusController::class, 'createDraft']);

        // Syllabus versions. Only a draft can change; publishing supersedes
        // the version in force in one transaction.
        Route::get('canonical-topics', [SyllabusController::class, 'canonicalTopics']);
        Route::prefix('syllabus-versions/{version}')->group(function (): void {
            Route::post('discard', [SyllabusController::class, 'discard']);
            Route::post('units', [SyllabusController::class, 'storeUnit']);
            Route::patch('units/{unit}', [SyllabusController::class, 'updateUnit']);
            Route::delete('units/{unit}', [SyllabusController::class, 'destroyUnit']);
            Route::post('units/{unit}/topics', [SyllabusController::class, 'storeTopic']);
            Route::patch('topics/{topic}', [SyllabusController::class, 'updateTopic']);
            Route::delete('topics/{topic}', [SyllabusController::class, 'destroyTopic']);
            Route::post('import', [SyllabusController::class, 'import'])->middleware('throttle:30,1');
            Route::get('diff', [SyllabusController::class, 'diff']);
            Route::post('publish', [SyllabusController::class, 'publish']);
        });

        Route::get('sources', [SourceController::class, 'index']);
        Route::post('sources', [SourceController::class, 'store'])
            ->middleware('throttle:120,60');
        Route::get('sources/{source}', [SourceController::class, 'show']);
        Route::post('sources/{source}/process', [SourceController::class, 'process'])
            ->middleware('throttle:120,60');
        // Book -> paper in one call: the whole point of the admin console.
        Route::post('sources/{source}/paper', [SourceController::class, 'paper'])
            ->middleware('throttle:30,1');
        Route::post('sources/{source}/questions/decide', [SourceController::class, 'decideQuestions'])
            ->middleware('throttle:60,1');

        /*
        | Structure editor and OCR correction. Detection is heuristic, so a
        | person has to be able to fix what it got wrong — and every fix marks
        | the derived stages stale so chunks are rebuilt rather than left
        | describing a structure that no longer exists.
        */
        Route::prefix('sources/{source}')->group(function (): void {
            Route::get('structure', [StructureController::class, 'show']);
            Route::patch('chapters/{chapter}', [StructureController::class, 'updateChapter']);
            Route::post('chapters/{chapter}/lessons', [StructureController::class, 'storeLesson']);
            Route::patch('lessons/{lesson}', [StructureController::class, 'updateLesson']);
            Route::delete('lessons/{lesson}', [StructureController::class, 'destroyLesson']);
            Route::get('pages', [StructureController::class, 'pages']);
            Route::patch('pages/{page}', [StructureController::class, 'correctPage']);
            // Repair proportional to the damage: one page, not the book.
            Route::post('pages/{page}/retry', [StructureController::class, 'retryPage']);
            Route::get('topic-mappings', [SyllabusController::class, 'lessonTopics']);
            Route::put('lessons/{lesson}/topic', [SyllabusController::class, 'assignLessonTopic']);
            Route::post('map-topics', [SyllabusController::class, 'remapTopics'])
                ->middleware('throttle:20,60');
            Route::post('reprocess', [StructureController::class, 'reprocess'])
                ->middleware('throttle:20,60');
        });

        /*
        | Previous-year papers. The analysis they produce is what supplies the
        | prediction weight in every paper the selection engine assembles, so
        | it is a first-class surface rather than a reporting afterthought.
        */
        Route::prefix('pyqs')->group(function (): void {
            Route::get('/', [PyqController::class, 'index']);
            Route::post('/', [PyqController::class, 'store']);
            Route::get('analysis/{exam}', [PyqController::class, 'analysis']);
            Route::get('{paper}', [PyqController::class, 'show']);
            Route::post('{paper}/questions', [PyqController::class, 'storeQuestions']);
            Route::post('{paper}/parse', [PyqController::class, 'parse'])
                ->middleware('throttle:60,60');
            Route::post('{paper}/map', [PyqController::class, 'map'])
                ->middleware('throttle:30,60');
            Route::post('{paper}/analyze', [PyqController::class, 'analyze']);
        });

        /*
        | Generation targets: how many approved questions a topic should hold.
        | The backlog view turns "which topic is thin?" from a hunch into a
        | number, and generation orders the shortfall rather than the total.
        */
        Route::prefix('generation-targets')->group(function (): void {
            Route::get('/', [GenerationTargetController::class, 'index']);
            Route::post('/', [GenerationTargetController::class, 'store']);
            Route::get('backlog/{exam}', [GenerationTargetController::class, 'backlog']);
            Route::delete('{target}', [GenerationTargetController::class, 'destroy']);
        });

        Route::get('questions', [QuestionController::class, 'index']);
        Route::get('questions/{question}', [QuestionController::class, 'show']);
        // Generation is the expensive call, but the real ceiling is the daily
        // budget guard inside AiGateway; this only stops a stuck loop.
        Route::post('questions/generate', [QuestionController::class, 'generate'])
            ->middleware('throttle:30,1');

        // Reviewer workstation. The queue is the platform's real throughput
        // constraint once generation works, so it gets a first-class surface.
        Route::prefix('reviews')->group(function (): void {
            Route::get('/', [ReviewController::class, 'queue']);
            Route::post('next', [ReviewController::class, 'next']);
            Route::get('metrics', [ReviewController::class, 'metrics']);
            Route::get('{review}', [ReviewController::class, 'show']);
            Route::post('{review}/decide', [ReviewController::class, 'decide']);
        });

        /*
        | Study plans. Each mode gets its own route because they are separate
        | pages in the product and separate entitlements in the plan matrix.
        | Generation is synchronous: the scheduler is deterministic arithmetic,
        | so there is no model call to wait on.
        */
        Route::prefix('study-plans')->group(function (): void {
            Route::get('/', [StudyPlanController::class, 'index']);
            Route::post('daily', [StudyPlanController::class, 'daily']);
            Route::post('three-day', [StudyPlanController::class, 'threeDay']);
            Route::post('weekly', [StudyPlanController::class, 'weekly']);
            Route::post('monthly', [StudyPlanController::class, 'monthly']);
            Route::post('model-test', [StudyPlanController::class, 'modelTest']);
            Route::get('{plan}', [StudyPlanController::class, 'show']);
            Route::get('{plan}/today', [StudyPlanController::class, 'today']);
            Route::post('{plan}/items/{item}/complete', [StudyPlanController::class, 'completeItem']);
            Route::post('{plan}/days/{day}/test', [StudyPlanController::class, 'dayTest']);
        });

        // Test assembly, attempts and results. No LLM on any of these paths.
        Route::post('tests', [TestController::class, 'store'])->middleware('throttle:30,1');
        Route::get('tests/{test}', [TestController::class, 'show']);
        Route::post('tests/{test}/attempt', [TestController::class, 'attempt']);
        Route::post('attempts/{attempt}/submit', [TestController::class, 'submit']);
        Route::get('results/{attempt}/solutions', [TestController::class, 'solutions']);

        /*
        | Documents. Rendering is synchronous and free: everything expensive
        | was already paid for during ingestion and generation, so a download
        | re-serves stored bytes rather than rebuilding anything.
        */
        Route::get('documents', [DocumentController::class, 'index']);
        Route::post('{subjectType}/{subjectId}/documents', [DocumentController::class, 'generate'])
            ->whereIn('subjectType', ['tests', 'study-plans']);
        Route::get('documents/{document}', [DocumentController::class, 'show']);
        Route::get('documents/{document}/download', [DocumentController::class, 'download']);

        // Read-only: the table revokes UPDATE and DELETE for the app role,
        // so there is no write path to expose.
        // The AI ledger has been written since the gateway existed and was
        // never readable. It is the only thing that answers where the money
        // went.
        Route::get('ai-history', [AiHistoryController::class, 'index']);
        Route::get('ai-history/{invocation}', [AiHistoryController::class, 'show']);

        Route::get('audit-logs', [AuditLogController::class, 'index']);
        Route::get('audit-logs/subject/{subject}', [AuditLogController::class, 'forSubject']);

        Route::get('jobs', [JobController::class, 'index']);
        Route::get('jobs/{job}', [JobController::class, 'show']);
        Route::post('jobs/{job}/cancel', [JobController::class, 'cancel']);
    });
});
