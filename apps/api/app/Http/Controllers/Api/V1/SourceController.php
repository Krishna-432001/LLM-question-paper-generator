<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\RenderDocumentAction;
use App\Domain\Audit\AuditRecorder;
use App\Actions\Jobs\DispatchTrackedJobAction;
use App\Actions\Sources\CreateSourceAction;
use App\Actions\Tests\AssembleTestAction;
use App\Domain\Review\ReviewWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sources\StoreSourceRequest;
use App\Jobs\Ingestion\ProcessSource;
use App\Models\Language;
use App\Models\Question;
use App\Models\Review;
use App\Models\Source;
use App\Models\Test;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

final class SourceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sources = Source::query()
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('created_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $sources->map($this->shape(...))->all()]);
    }

    public function store(StoreSourceRequest $request, CreateSourceAction $action): JsonResponse
    {
        $source = $action->execute(
            file: $request->file('file'),
            kind: $request->string('kind')->toString(),
            title: $request->string('title')->toString(),
            languageId: $request->string('language_id')->toString(),
            rightsBasis: $request->string('rights_basis')->toString(),
            meta: $request->array('meta'),
            isPlatformContent: $request->boolean('platform_content'),
        );

        return response()->json(['data' => $this->shape($source)], 201);
    }

    public function show(string $sourceId): JsonResponse
    {
        $source = Source::query()->findOrFail($sourceId);

        return response()->json([
            'data' => $this->shape($source) + [
                'pipeline_state' => $source->pipeline_state,
                'questions' => $this->questionCounts($source),
            ],
        ]);
    }

    /**
     * Book to paper in one call.
     *
     * The admin flow is upload -> ingest -> generate -> paper, and everything
     * before this point is already asynchronous and expensive. This step is
     * neither: selection is a query and rendering is a layout pass, so it
     * answers inline with a download link rather than another job to poll.
     */
    public function paper(
        Request $request,
        string $sourceId,
        AssembleTestAction $assemble,
        RenderDocumentAction $render,
    ): JsonResponse {
        $source = Source::query()->findOrFail($sourceId);
        $context = app(TenantContext::class);

        $validated = $request->validate([
            'question_count' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'title' => ['sometimes', 'string', 'max:200'],
            // The paper's language. Defaults to the book's own; a different
            // one draws only the questions validated in it.
            'language_id' => ['sometimes', 'uuid', 'exists:languages,id'],
            // bilingual prints every question in language_id and again in
            // secondary_language_id beneath it. Omitted, the paper is single
            // language, exactly as before language modes existed.
            'language_mode' => ['sometimes', Rule::in(Test::LANGUAGE_MODES)],
            'secondary_language_id' => [
                'nullable',
                'required_if:language_mode,'.Test::MODE_BILINGUAL,
                'uuid',
                'exists:languages,id',
            ],
            'exam_id' => ['sometimes', 'nullable', 'uuid'],
            'duration_minutes' => ['sometimes', 'integer', 'min:1', 'max:600'],
            'marks_per_question' => ['sometimes', 'numeric', 'min:0.25', 'max:10'],
            'include_answer_key' => ['sometimes', 'boolean'],
            'include_explanations' => ['sometimes', 'boolean'],
            'format' => ['sometimes', 'in:pdf,docx,xlsx,json'],
        ]);

        $counts = $this->questionCounts($source);
        $requested = (int) ($validated['question_count'] ?? $counts['approved']);

        // Failing here with the real number is far kinder than rendering a
        // three-question paper and letting the admin discover it in the PDF.
        abort_if(
            $counts['approved'] === 0,
            409,
            $counts['total'] === 0
                ? 'No questions have been generated from this source yet.'
                : "This source has {$counts['total']} generated questions but none are approved yet. Approve them in the review queue first.",
        );

        $examId = $validated['exam_id'] ?? $this->examFor($source);

        abort_if(
            $examId === null,
            422,
            'These questions are not mapped to any exam, so a paper cannot be assembled. Pass exam_id explicitly.',
        );

        $languageId = $validated['language_id'] ?? $source->language_id;
        $bilingual = ($validated['language_mode'] ?? Test::MODE_SINGLE) === Test::MODE_BILINGUAL;
        $secondaryId = $bilingual ? $validated['secondary_language_id'] : null;

        abort_if(
            $secondaryId !== null && $secondaryId === $languageId,
            422,
            "A bilingual paper's second language must differ from its main language (the book's own language unless language_id is given).",
        );

        $selection = [
            'organization_id' => $source->organization_id,
            'user_id' => $context->userId,
            'exam_id' => $examId,
            'source_id' => $source->getKey(),
            'type' => 'topic_practice',
            'language_id' => $languageId,
            'language_mode' => $bilingual ? Test::MODE_BILINGUAL : Test::MODE_SINGLE,
            'secondary_language_id' => $secondaryId,
        ];

        // Approved is not the same as printable: a question approved in
        // English but never translated cannot go on a Tamil paper, nor on a
        // bilingual one. Sizing to what is printable keeps "asking for 50
        // gives the 12 there are" true in every language.
        $available = $assemble->availability($selection);
        $printable = $bilingual ? (int) $available['both'] : $available['primary'];

        if (! $bilingual && $printable === 0) {
            $language = Language::query()->whereKey($languageId)->value('name') ?? 'that language';

            abort(422, "None of this book's {$counts['approved']} approved questions are available in {$language} yet. "
                .'Print it in the language the questions were written in, or approve translations first.');
        }

        // A bilingual request with nothing printable in both languages is
        // refused inside the action, with the per-language counts.
        $test = $assemble->execute($selection + [
            'title' => $validated['title'] ?? $source->title,
            'question_count' => min($requested, $counts['approved'], $printable),
            'duration_minutes' => $validated['duration_minutes'] ?? null,
            'marks_per_question' => $validated['marks_per_question'] ?? 1,
        ]);

        $format = $validated['format'] ?? 'pdf';
        $types = ['question_paper'];

        // Only a printed paper needs a separate key document. The machine
        // formats carry paper and answers in one bundle, so emitting a second
        // file would hand the consumer two half-answers to reconcile.
        if ($format === 'pdf') {
            if ($request->boolean('include_answer_key', true)) {
                $types[] = 'answer_key';
            }

            if ($request->boolean('include_explanations')) {
                $types[] = 'explanations';
            }
        }

        $documents = [];

        foreach ($types as $type) {
            $document = $render->execute($test, $type, null, $format);

            $documents[] = [
                'id' => $document->getKey(),
                'type' => $document->type,
                'format' => $document->format,
                'title' => $document->title,
                'document_code' => $document->document_code,
                'page_count' => $document->page_count,
                'file_size_bytes' => $document->file_size_bytes,
                'download_url' => "/api/v1/documents/{$document->getKey()}/download",
            ];
        }

        return response()->json([
            'data' => [
                'test_id' => $test->getKey(),
                'question_count' => $test->question_count,
                // Surfaced, not hidden: asking for 50 from a bank of 12 gives
                // 12, and the caller is told so.
                'requested_count' => $requested,
                'language_mode' => $test->language_mode,
                'languages' => $test->paperLanguages()->pluck('code')->all(),
                // How many of this book's questions each language could have
                // supplied, so a short bilingual paper explains itself.
                'availability' => $available,
                'documents' => $documents,
            ],
        ], 201);
    }

    /**
     * Bulk review decision for one book's generated questions.
     *
     * Approving thirty questions one screen at a time is the single biggest
     * drag on the upload-to-paper loop, and a reviewer who must do it that way
     * simply stops reviewing. Deciding in bulk is a real editorial action, so
     * it goes through the same workflow as a single decision — same legal
     * transitions, same audit trail, same version bump — rather than writing
     * statuses directly.
     */
    public function decideQuestions(Request $request, string $sourceId, ReviewWorkflow $workflow): JsonResponse
    {
        $source = Source::query()->findOrFail($sourceId);

        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'question_ids' => ['sometimes', 'array'],
            'question_ids.*' => ['uuid'],
            // Lets an admin take the top of the batch and leave the rest for a
            // closer look, instead of an all-or-nothing choice.
            'min_quality' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ]);

        $target = $validated['decision'] === 'approve' ? 'APPROVED' : 'REJECTED';

        $pending = Question::query()
            ->whereHas('sources', fn ($q) => $q->where('source_id', $source->getKey()))
            ->whereIn('status', ['AI_VALIDATED', 'PENDING_REVIEW'])
            ->when(
                $validated['question_ids'] ?? null,
                fn ($q, $ids) => $q->whereIn('id', $ids),
            )
            ->when(
                isset($validated['min_quality']),
                fn ($q) => $q->where('quality_score', '>=', $validated['min_quality']),
            )
            ->get();

        $decided = 0;
        $failed = [];

        foreach ($pending as $question) {
            try {
                // Prefer the review record: decide() closes the queue item as
                // well as moving the question. Transitioning on its own would
                // approve the question and leave its review sitting in the
                // queue forever, so the reviewer keeps seeing work already done.
                $review = Review::query()
                    ->where('reviewable_type', Question::class)
                    ->where('reviewable_id', $question->getKey())
                    ->whereIn('status', ['pending', 'in_review'])
                    ->first();

                if ($review !== null) {
                    $workflow->decide($review, $request->user(), $validated['decision']);
                } else {
                    $workflow->transition($question, $target, $request->user());
                }

                $decided++;
            } catch (Throwable $e) {
                // One bad row must not abort the batch, but it is reported
                // rather than swallowed.
                $failed[] = ['question_id' => $question->getKey(), 'reason' => $e->getMessage()];
            }
        }

        // Each question already carries its own transition entry. This records
        // the human act: one person decided this batch at once, which is a
        // different fact from thirty separate reviews and is the one worth
        // finding later.
        app(AuditRecorder::class)->record('questions.bulk_decided', $source, [
            'decision' => $validated['decision'],
            'considered' => $pending->count(),
            'decided' => $decided,
            'failed' => count($failed),
            'min_quality' => $validated['min_quality'] ?? null,
            'explicit_ids' => count($validated['question_ids'] ?? []),
        ]);

        return response()->json([
            'data' => [
                'decision' => $validated['decision'],
                'considered' => $pending->count(),
                'decided' => $decided,
                'failed' => $failed,
                'questions' => $this->questionCounts($source->fresh()),
            ],
        ]);
    }

    /**
     * A paper always belongs to an exam. Rather than making the admin restate
     * something the questions already know, take the exam most of this book's
     * questions are mapped to.
     */
    private function examFor(Source $source): ?string
    {
        return DB::table('question_exam_mappings AS m')
            ->join('question_sources AS s', 's.question_id', '=', 'm.question_id')
            ->where('s.source_id', $source->getKey())
            ->where('m.is_active', true)
            ->groupBy('m.exam_id')
            ->orderByRaw('count(*) DESC')
            ->value('m.exam_id');
    }

    /**
     * How far this book has travelled down the funnel.
     *
     * @return array{total: int, approved: int, pending_review: int, rejected: int}
     */
    private function questionCounts(Source $source): array
    {
        $byStatus = Question::query()
            ->whereHas('sources', fn ($q) => $q->where('source_id', $source->getKey()))
            ->selectRaw('status, count(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Statuses are stored upper-case; reading them back lower-case
        // silently reports an empty bank for a book full of questions.
        return [
            'total' => (int) $byStatus->sum(),
            'approved' => (int) $byStatus->get('APPROVED', 0),
            'pending_review' => (int) $byStatus->only(['AI_VALIDATED', 'PENDING_REVIEW'])->sum(),
            'rejected' => (int) $byStatus->get('REJECTED', 0),
        ];
    }

    /**
     * Kicks off ingestion. Returns 202 with a job id — never blocks the
     * request on OCR, which takes minutes on a real textbook.
     */
    public function process(string $sourceId, DispatchTrackedJobAction $dispatch): JsonResponse
    {
        $source = Source::query()->findOrFail($sourceId);
        $context = app(TenantContext::class);

        abort_if($source->status === 'ready', 409, 'This source has already been processed.');

        $job = $dispatch->execute(
            type: 'ingest',
            jobClass: ProcessSource::class,
            input: ['source_id' => $source->getKey()],
            organizationId: $source->organization_id,
            userId: $context->userId,
            subjectType: Source::class,
            subjectId: $source->getKey(),
            idempotencyKey: 'ingest:'.$source->getKey(),
        );

        return response()->json([
            'data' => [
                'job_id' => $job->getKey(),
                'type' => $job->type,
                'status' => $job->status->value,
                'progress' => $job->progress,
                'poll_url' => "/api/v1/jobs/{$job->getKey()}",
            ],
        ], 202);
    }

    /** @return array<string, mixed> */
    private function shape(Source $source): array
    {
        return [
            'id' => $source->getKey(),
            'kind' => $source->kind,
            'title' => $source->title,
            'status' => $source->status,
            'language_id' => $source->language_id,
            'page_count' => $source->page_count,
            'file_size_bytes' => $source->file_size_bytes,
            'rights_basis' => $source->rights_basis,
            'is_platform' => $source->isPlatformOwned(),
            'created_at' => $source->created_at?->toIso8601String(),
        ];
    }
}
