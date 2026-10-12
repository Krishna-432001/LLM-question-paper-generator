<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Jobs\DispatchTrackedJobAction;
use App\Domain\Ingestion\StructureEditor;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Jobs\Ingestion\ProcessSource;
use App\Models\BookChapter;
use App\Models\BookLesson;
use App\Models\BookPage;
use App\Models\Source;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The structure editor and OCR correction surface.
 *
 * Both exist for the same reason: the machine reads a book approximately, and
 * everything downstream — which passages a question may cite, what the citation
 * says, whether a lesson has enough material to be generated from at all —
 * inherits that reading. Without a way to correct it, a misread heading becomes
 * a permanently unusable chapter.
 */
final class StructureController extends Controller
{
    public function __construct(private readonly StructureEditor $editor) {}

    public function show(string $sourceId): JsonResponse
    {
        $source = Source::query()->findOrFail($sourceId);

        return response()->json([
            'data' => $this->editor->tree($source) + [
                'source_status' => $source->status,
                // True once an edit has invalidated the derived stages, so the
                // console can say "re-chunk needed" rather than quietly serving
                // questions cited against a structure that no longer exists.
                'needs_reprocess' => $source->stageStatus('chunk') !== 'completed',
            ],
        ]);
    }

    public function updateChapter(Request $request, string $sourceId, string $chapterId): JsonResponse
    {
        $source = Source::query()->findOrFail($sourceId);
        $chapter = BookChapter::query()->findOrFail($chapterId);

        $validated = $request->validate([
            'number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'title' => ['sometimes', 'string', 'max:300'],
            'title_native' => ['sometimes', 'nullable', 'string', 'max:300'],
            'page_start' => ['sometimes', 'integer', 'min:1'],
            'page_end' => ['sometimes', 'integer', 'min:1'],
            'sequence' => ['sometimes', 'integer', 'min:1'],
        ]);

        $this->assertPageRange($validated);

        return response()->json([
            'data' => ['chapter' => $this->editor->updateChapter($chapter, $validated, $source)],
        ]);
    }

    public function updateLesson(Request $request, string $sourceId, string $lessonId): JsonResponse
    {
        $source = Source::query()->findOrFail($sourceId);
        $lesson = BookLesson::query()->findOrFail($lessonId);

        $validated = $request->validate([
            'number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'title' => ['sometimes', 'string', 'max:300'],
            'title_native' => ['sometimes', 'nullable', 'string', 'max:300'],
            'page_start' => ['sometimes', 'integer', 'min:1'],
            'page_end' => ['sometimes', 'integer', 'min:1'],
            'sequence' => ['sometimes', 'integer', 'min:1'],
            'book_chapter_id' => ['sometimes', 'uuid'],
        ]);

        $this->assertPageRange($validated);

        return response()->json([
            'data' => ['lesson' => $this->editor->updateLesson($lesson, $validated, $source)],
        ]);
    }

    public function storeLesson(Request $request, string $sourceId, string $chapterId): JsonResponse
    {
        $source = Source::query()->findOrFail($sourceId);
        $chapter = BookChapter::query()->findOrFail($chapterId);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:300'],
            'number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'page_start' => ['required', 'integer', 'min:1'],
            'page_end' => ['required', 'integer', 'min:1'],
        ]);

        $this->assertPageRange($validated);

        return response()->json([
            'data' => ['lesson' => $this->editor->createLesson($chapter, $validated, $source)],
        ], 201);
    }

    public function destroyLesson(string $sourceId, string $lessonId): JsonResponse
    {
        $source = Source::query()->findOrFail($sourceId);

        $this->editor->deleteLesson(BookLesson::query()->findOrFail($lessonId), $source);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Pages the OCR was unsure about, worst first — that is the order a human
     * should spend their attention in.
     */
    public function pages(Request $request, string $sourceId): JsonResponse
    {
        $source = Source::query()->findOrFail($sourceId);
        $book = $source->book;

        if ($book === null) {
            return response()->json(['data' => [], 'meta' => ['needs_review' => 0]]);
        }

        $pages = BookPage::query()
            ->where('book_id', $book->getKey())
            ->when($request->boolean('needs_review'), fn ($q) => $q->where('needs_review', true))
            ->orderBy('ocr_confidence')
            ->orderBy('page_number')
            ->limit(min(200, $request->integer('limit', 50)))
            ->get();

        return response()->json([
            'data' => $pages->map(fn (BookPage $page): array => [
                'id' => $page->getKey(),
                'page_number' => $page->page_number,
                'ocr_confidence' => $page->ocr_confidence,
                'needs_review' => (bool) $page->needs_review,
                'reviewed_by' => $page->reviewed_by,
                'text' => $page->normalized_text ?? $page->raw_text,
                // Kept separate so a reviewer can see what the machine read
                // versus what a previous human corrected it to.
                'raw_text' => $page->raw_text,
            ])->all(),
            'meta' => [
                'needs_review' => BookPage::query()
                    ->where('book_id', $book->getKey())
                    ->where('needs_review', true)
                    ->count(),
            ],
        ]);
    }

    public function correctPage(Request $request, string $sourceId, string $pageId): JsonResponse
    {
        $source = Source::query()->findOrFail($sourceId);
        $page = BookPage::query()->findOrFail($pageId);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:100000'],
        ]);

        $corrected = $this->editor->correctPage(
            $page,
            $validated['text'],
            app(TenantContext::class)->userId,
            $source,
        );

        return response()->json([
            'data' => [
                'page_number' => $corrected->page_number,
                'needs_review' => (bool) $corrected->needs_review,
            ],
        ]);
    }

    /**
     * Asks the extractor to read one page again.
     *
     * Separate from a human correction: this is for a page that failed rather
     * than one that is hard.
     */
    public function retryPage(string $sourceId, string $pageId): JsonResponse
    {
        $source = Source::query()->findOrFail($sourceId);
        $page = BookPage::query()->findOrFail($pageId);

        $retried = $this->editor->retryPage($page, $source);

        return response()->json([
            'data' => [
                'page_number' => $retried->page_number,
                'characters' => mb_strlen((string) $retried->normalized_text),
                'needs_review' => (bool) $retried->needs_review,
            ],
        ]);
    }

    /**
     * Rebuilds everything downstream of the corrections.
     *
     * Deliberately a separate call rather than firing on every keystroke: an
     * editor fixes a dozen headings in a sitting, and re-chunking after each
     * one would waste minutes and thrash the embedding budget.
     */
    public function reprocess(string $sourceId, DispatchTrackedJobAction $dispatch): JsonResponse
    {
        $source = Source::query()->findOrFail($sourceId);

        $job = $dispatch->execute(
            type: 'ingest',
            jobClass: ProcessSource::class,
            input: ['source_id' => $source->getKey()],
            organizationId: $source->organization_id,
            userId: app(TenantContext::class)->userId,
            subjectType: Source::class,
            subjectId: $source->getKey(),
            // No idempotency key: re-chunking after an edit is exactly the
            // repeat call that a key would suppress.
        );

        return response()->json([
            'data' => [
                'job_id' => $job->getKey(),
                'status' => $job->status->value,
                'poll_url' => "/api/v1/jobs/{$job->getKey()}",
            ],
        ], 202);
    }

    /** @param  array<string, mixed>  $validated */
    private function assertPageRange(array $validated): void
    {
        abort_if(
            isset($validated['page_start'], $validated['page_end'])
                && $validated['page_end'] < $validated['page_start'],
            422,
            'A section cannot end before it begins.',
        );
    }
}
