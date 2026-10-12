<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use App\Domain\Audit\AuditRecorder;
use App\Infrastructure\Ocr\PopplerTextExtractor;
use Illuminate\Support\Facades\Storage;
use RuntimeException as PageRetryFailed;
use App\Models\Book;
use App\Models\BookChapter;
use App\Models\BookLesson;
use App\Models\BookPage;
use App\Models\Source;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Human correction of what the machine read.
 *
 * Structure detection is heuristics over a PDF text dump, and on a real
 * textbook it will misplace a heading, merge two lessons, or invent one out of
 * a running header. Those mistakes are not cosmetic: the page range of a lesson
 * decides which passages a question may be generated from, and its title is
 * printed in the citation a reviewer trusts. So the tree has to be editable by
 * a person, and editing it has to invalidate the work derived from it.
 *
 * Every method here therefore does two things: change the record, and mark the
 * downstream stages stale so chunks and embeddings are rebuilt rather than left
 * describing a structure that no longer exists.
 */
final class StructureEditor
{
    /** Stages whose output is derived from the structure tree or the page text. */
    private const DERIVED_STAGES = ['chunk', 'embed', 'map'];

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * The tree as an editor needs to see it: every chapter and lesson with the
     * page range it claims and how much usable material it actually yielded.
     *
     * @return array<string, mixed>
     */
    public function tree(Source $source): array
    {
        $book = Book::query()->where('source_id', $source->getKey())->first();

        if ($book === null) {
            return ['book_id' => null, 'pages' => 0, 'chapters' => []];
        }

        $chunkCounts = DB::table('source_chunks')
            ->where('source_id', $source->getKey())
            ->selectRaw('book_lesson_id, count(*) FILTER (WHERE NOT is_exercise) AS usable, count(*) AS total')
            ->groupBy('book_lesson_id')
            ->get()
            ->keyBy('book_lesson_id');

        $chapters = BookChapter::query()
            ->where('book_id', $book->getKey())
            ->orderBy('sequence')
            ->get();

        $lessons = BookLesson::query()
            ->whereIn('book_chapter_id', $chapters->pluck('id'))
            ->orderBy('sequence')
            ->get()
            ->groupBy('book_chapter_id');

        return [
            'book_id' => $book->getKey(),
            'pages' => BookPage::query()->where('book_id', $book->getKey())->count(),
            'chapters' => $chapters->map(fn (BookChapter $chapter): array => [
                'id' => $chapter->getKey(),
                'number' => $chapter->number,
                'title' => $chapter->title,
                'page_start' => $chapter->page_start,
                'page_end' => $chapter->page_end,
                'sequence' => $chapter->sequence,
                'lessons' => ($lessons[$chapter->getKey()] ?? collect())
                    ->map(function (BookLesson $lesson) use ($chunkCounts): array {
                        $counts = $chunkCounts->get($lesson->getKey());

                        return [
                            'id' => $lesson->getKey(),
                            'number' => $lesson->number,
                            'title' => $lesson->title,
                            'page_start' => $lesson->page_start,
                            'page_end' => $lesson->page_end,
                            'sequence' => $lesson->sequence,
                            // The number that decides whether this lesson can
                            // produce questions at all: the coverage guard
                            // needs at least three non-exercise chunks.
                            'usable_chunks' => (int) ($counts->usable ?? 0),
                            'total_chunks' => (int) ($counts->total ?? 0),
                        ];
                    })->values()->all(),
            ])->values()->all(),
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    public function updateChapter(BookChapter $chapter, array $attributes, Source $source): BookChapter
    {
        $chapter->fill($this->clean($attributes, [
            'number', 'title', 'title_native', 'page_start', 'page_end', 'sequence',
        ]))->save();

        $this->invalidate($source);

        return $chapter;
    }

    /** @param  array<string, mixed>  $attributes */
    public function updateLesson(BookLesson $lesson, array $attributes, Source $source): BookLesson
    {
        // Snapshot before the write: a lesson title is printed in the citation
        // of every question drawn from it, so an edit rewrites what already
        // published papers claim as their source.
        $before = $lesson->getAttributes();

        $fields = $this->clean($attributes, [
            'number', 'title', 'title_native', 'page_start', 'page_end', 'sequence',
        ]);

        // Moving a lesson to a different chapter is a real correction: the
        // detector regularly attaches the first lesson of a chapter to the
        // previous one.
        if (isset($attributes['book_chapter_id'])) {
            $target = BookChapter::query()->findOrFail($attributes['book_chapter_id']);

            if ($target->book_id !== $lesson->chapter?->book_id) {
                throw new RuntimeException('A lesson cannot be moved into a different book.');
            }

            $fields['book_chapter_id'] = $target->getKey();
        }

        $lesson->fill($fields)->save();

        $this->audit->recordChange('book_lesson.updated', $lesson, $before);
        $this->invalidate($source);

        return $lesson;
    }

    /** @param  array<string, mixed>  $attributes */
    public function createLesson(BookChapter $chapter, array $attributes, Source $source): BookLesson
    {
        $lesson = BookLesson::query()->create($this->clean($attributes, [
            'number', 'title', 'title_native', 'page_start', 'page_end',
        ]) + [
            'book_chapter_id' => $chapter->getKey(),
            'sequence' => (int) BookLesson::query()
                ->where('book_chapter_id', $chapter->getKey())
                ->max('sequence') + 1,
        ]);

        $this->invalidate($source);

        return $lesson;
    }

    public function deleteLesson(BookLesson $lesson, Source $source): void
    {
        $lesson->delete();

        $this->invalidate($source);
    }

    /**
     * Corrects the text of a page the OCR read badly, and clears its review
     * flag. The raw text is deliberately left untouched: it is the record of
     * what the machine actually saw, and overwriting it would make a bad OCR
     * pass impossible to diagnose later.
     */
    public function correctPage(BookPage $page, string $text, ?string $reviewerId, Source $source): BookPage
    {
        $before = $page->getAttributes();

        $page->forceFill([
            'normalized_text' => $text,
            'needs_review' => false,
            'reviewed_by' => $reviewerId,
        ])->save();

        // A corrected page changes the text every question drawn from it was
        // generated against, so who changed it — and to what — has to survive.
        $this->audit->recordChange('book_page.corrected', $page, $before);
        $this->invalidate($source);

        return $page;
    }

    /**
     * Re-reads one page from the original PDF.
     *
     * Distinct from correctPage, which records what a human typed. This asks
     * the extractor again — the right move when a page was misread because of
     * a transient failure or a since-fixed extraction bug, rather than because
     * the page is genuinely hard to read.
     *
     * Scoped to a single page on purpose: re-running the book to repair one
     * page costs twenty minutes on a real textbook, so in practice nobody does
     * it and the bad page simply stays.
     */
    public function retryPage(BookPage $page, Source $source): BookPage
    {
        $before = $page->getAttributes();
        $extractor = app(PopplerTextExtractor::class);

        if (! $extractor->isAvailable()) {
            throw new PageRetryFailed(
                'pdftotext is not available, so a page cannot be re-read.'
            );
        }

        $disk = Storage::disk($source->storage_disk);

        if (! $disk->exists($source->storage_path)) {
            throw new PageRetryFailed("The stored file is missing: {$source->storage_path}");
        }

        $extracted = $extractor->extractPage($disk->path($source->storage_path), $page->page_number);

        // raw_text is replaced because this genuinely is what the machine now
        // reads; a human correction, by contrast, never overwrites it.
        $page->forceFill([
            'raw_text' => $extracted->text,
            'normalized_text' => app(TextNormalizer::class)->normalizePage($extracted->text),
            'ocr_confidence' => $extracted->confidence,
            'needs_review' => trim($extracted->text) === '',
        ])->save();

        $this->audit->recordChange('book_page.retried', $page, $before);
        $this->invalidate($source);

        return $page;
    }

    /**
     * Marks everything derived from the structure or the page text as stale.
     *
     * Without this an edit is cosmetic: the chunks a question is generated from
     * would still carry the old lesson boundaries and the old citation text,
     * and nothing would tell the admin the two had diverged.
     */
    public function invalidate(Source $source): void
    {
        $state = $source->pipeline_state ?? [];

        foreach (self::DERIVED_STAGES as $stage) {
            unset($state[$stage]);
        }

        $source->pipeline_state = $state;
        // Back to the stage the pipeline must resume at. This also makes
        // isReady() false, so generation refuses until the rebuild has run —
        // while a paper built from already-approved questions still works,
        // because their citations are snapshotted rather than read live.
        $source->status = 'chunking';
        $source->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private function clean(array $attributes, array $allowed): array
    {
        return array_filter(
            array_intersect_key($attributes, array_flip($allowed)),
            static fn ($value): bool => $value !== null,
        );
    }
}
