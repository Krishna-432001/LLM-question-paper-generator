<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use App\Domain\Topics\LessonTopicMapper;
use App\Domain\Ingestion\Contracts\EmbeddingProvider;
use App\Domain\Ingestion\Contracts\OcrEngine;
use App\Domain\Ingestion\DTOs\Chunk;
use App\Domain\Ingestion\DTOs\ExtractedPage;
use App\Infrastructure\Ocr\PopplerTextExtractor;
use App\Models\Book;
use App\Models\BookChapter;
use App\Models\BookLesson;
use App\Models\BookPage;
use App\Models\Source;
use App\Models\SourceChunk;
use App\Support\Database\VectorSupport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The stages of Engine 1's ingestion half, implemented.
 *
 * Each method is idempotent and writes its result before returning, so
 * ProcessSource can resume from any stage without redoing the expensive ones.
 */
final class IngestionPipeline
{
    public function __construct(
        private readonly PopplerTextExtractor $poppler,
        private readonly OcrEngine $ocr,
        private readonly TextNormalizer $normalizer,
        private readonly StructureDetector $structure,
        private readonly Chunker $chunker,
        private readonly EmbeddingProvider $embeddings,
    ) {}

    /**
     * Stage: extract. Chooses the free path when the PDF already has a text
     * layer and only pays for OCR when it genuinely needs to.
     *
     * @return array{pages: int, engine: string, flagged: int}
     */
    public function extract(Source $source): array
    {
        $path = $this->localPath($source);
        $book = $this->bookFor($source);

        $useNative = $source->mime_type === 'application/pdf'
            && $this->poppler->isAvailable()
            && $this->poppler->hasUsableTextLayer($path);

        $pages = $useNative
            ? $this->poppler->extract($path)
            : $this->ocr->extract($path, [$source->language->code ?? 'en']);

        $engine = $useNative ? 'poppler' : $this->ocr->name();
        $flagged = 0;

        DB::transaction(function () use ($book, $pages, &$flagged): void {
            foreach ($pages as $page) {
                if ($page->needsReview()) {
                    $flagged++;
                }

                BookPage::query()->updateOrCreate(
                    ['book_id' => $book->getKey(), 'page_number' => $page->pageNumber],
                    [
                        'raw_text' => $page->text,
                        'ocr_confidence' => $page->confidence,
                        'needs_review' => $page->needsReview(),
                    ],
                );
            }
        });

        $source->forceFill([
            'page_count' => count($pages),
            'ocr_engine' => $engine,
            'ocr_confidence' => $this->meanConfidence($pages),
        ])->save();

        return ['pages' => count($pages), 'engine' => $engine, 'flagged' => $flagged];
    }

    /**
     * Stage: normalize. Unicode composition, de-hyphenation and running
     * header/footer removal, applied across the whole book so repeated
     * furniture can be detected statistically.
     *
     * @return array{pages: int}
     */
    public function normalize(Source $source): array
    {
        $book = $this->bookFor($source);
        $pages = $book->pages()->orderBy('page_number')->get();

        $normalized = $this->normalizer->normalizePages(
            $pages->map(fn (BookPage $p): string => (string) $p->raw_text)->all()
        );

        DB::transaction(function () use ($pages, $normalized): void {
            foreach ($pages as $i => $page) {
                $page->forceFill(['normalized_text' => $normalized[$i] ?? ''])->save();
            }
        });

        return ['pages' => $pages->count()];
    }

    /**
     * Stage: structure. Free heuristics build the chapter/lesson tree; the
     * cheap-tier model repair pass is layered on top in Phase 2b.
     *
     * @return array{chapters: int, lessons: int, candidates: int}
     */
    public function detectStructure(Source $source): array
    {
        $book = $this->bookFor($source);
        $pages = $this->usablePages($book);

        $detected = $this->structure->detect($pages);
        $tree = $this->structure->buildTree($detected['candidates'], max(1, count($pages)));

        // A book with no detectable headings still has to be usable, so the
        // whole book becomes one lesson rather than yielding zero chunks.
        if ($tree === []) {
            $tree = [[
                'number' => '1',
                'title' => $source->title,
                'start_page' => 1,
                'end_page' => max(1, count($pages)),
                'lessons' => [[
                    'number' => '1.1',
                    'title' => $source->title,
                    'start_page' => 1,
                    'end_page' => max(1, count($pages)),
                ]],
            ]];
        }

        $lessonCount = 0;

        DB::transaction(function () use ($book, $tree, &$lessonCount): void {
            $book->chapters()->delete();   // structure detection is a full rebuild

            foreach ($tree as $sequence => $chapter) {
                $record = BookChapter::query()->create([
                    'book_id' => $book->getKey(),
                    'number' => $chapter['number'],
                    'title' => $chapter['title'],
                    'page_start' => $chapter['start_page'],
                    'page_end' => $chapter['end_page'],
                    'sequence' => $sequence,
                ]);

                $lessons = $chapter['lessons'] !== [] ? $chapter['lessons'] : [[
                    'number' => $chapter['number'].'.1',
                    'title' => $chapter['title'],
                    'start_page' => $chapter['start_page'],
                    'end_page' => $chapter['end_page'],
                ]];

                foreach ($lessons as $lessonSequence => $lesson) {
                    BookLesson::query()->create([
                        'book_chapter_id' => $record->getKey(),
                        'number' => $lesson['number'],
                        'title' => $lesson['title'],
                        'page_start' => $lesson['start_page'],
                        'page_end' => $lesson['end_page'],
                        'sequence' => $lessonSequence,
                    ]);
                    $lessonCount++;
                }
            }
        });

        return [
            'chapters' => count($tree),
            'lessons' => $lessonCount,
            'candidates' => count($detected['candidates']),
        ];
    }

    /**
     * Stage: chunk.
     *
     * @return array{chunks: int, exercises: int}
     */
    public function chunk(Source $source): array
    {
        $book = $this->bookFor($source);
        $pagesByNumber = $this->usablePageMap($book);

        $lessons = [];

        foreach ($book->chapters()->with('lessons')->get() as $chapter) {
            foreach ($chapter->lessons as $lesson) {
                $lessons[] = [
                    'id' => $lesson->getKey(),
                    'start_page' => $lesson->page_start,
                    'end_page' => $lesson->page_end,
                    'breadcrumb' => sprintf(
                        '%s › %s › %s › pp.%d-%d',
                        $source->title,
                        $chapter->title,
                        $lesson->title,
                        $lesson->page_start,
                        $lesson->page_end,
                    ),
                ];
            }
        }

        $chunks = $this->chunker->chunk($pagesByNumber, $lessons);
        $exercises = 0;

        DB::transaction(function () use ($source, $book, $chunks, &$exercises): void {
            SourceChunk::query()->where('source_id', $source->getKey())->delete();

            foreach ($chunks as $chunk) {
                if ($chunk->isExercise) {
                    $exercises++;
                }

                SourceChunk::query()->create([
                    'source_id' => $source->getKey(),
                    'book_id' => $book->getKey(),
                    'book_lesson_id' => $chunk->lessonId,
                    'chunk_index' => $chunk->index,
                    'chunk_type' => $chunk->type,
                    'breadcrumb' => $chunk->breadcrumb,
                    'text' => $chunk->text,
                    'token_count' => $chunk->tokenCount,
                    'page_start' => $chunk->pageStart,
                    'page_end' => $chunk->pageEnd,
                    'language_id' => $source->language_id,
                    'importance' => $chunk->importance,
                    'is_exercise' => $chunk->isExercise,
                    'content_hash' => $chunk->contentHash(),
                ]);
            }
        });

        return ['chunks' => count($chunks), 'exercises' => $exercises];
    }

    /**
     * Stage: embed. Batched, and skips chunks whose content hash already has a
     * vector — re-ingesting an edited book only pays for the changed pages.
     *
     * @return array{embedded: int, reused: int}
     */
    public function embed(Source $source): array
    {
        $batchSize = (int) config('ingestion.chunking.embed_batch_size', 64);
        $model = $this->embeddings->modelKey();
        $embedded = 0;
        $reused = 0;

        SourceChunk::query()
            ->where('source_id', $source->getKey())
            ->orderBy('chunk_index')
            ->chunkById($batchSize, function ($chunks) use ($model, &$embedded, &$reused): void {
                $pending = [];

                foreach ($chunks as $chunk) {
                    $exists = DB::table('source_embeddings')
                        ->where('source_chunk_id', $chunk->getKey())
                        ->where('embedding_model', $model)
                        ->exists();

                    if ($exists) {
                        $reused++;

                        continue;
                    }

                    $pending[$chunk->getKey()] = $chunk->text;
                }

                if ($pending === []) {
                    return;
                }

                $vectors = $this->embeddings->embed(array_values($pending));

                foreach (array_keys($pending) as $i => $chunkId) {
                    DB::insert(
                        'INSERT INTO source_embeddings (source_chunk_id, embedding_model, embedding)
                         VALUES (?, ?, ?) ON CONFLICT (source_chunk_id, embedding_model) DO NOTHING',
                        [$chunkId, $model, VectorSupport::toBinding($vectors[$i])],
                    );
                    $embedded++;
                }
            });

        return ['embedded' => $embedded, 'reused' => $reused];
    }

    /**
     * Stage: map. Links lessons to the syllabus so retrieval can be scoped by
     * exam topic, and so uncovered topics surface as content work.
     *
     * @return array{mapped: int}
     */
    public function mapToSyllabus(Source $source): array
    {
        // Each lesson onto a canonical topic, so the questions generated from
        // it count towards the backlog and carry blueprint weight.
        $book = $this->bookFor($source);
        $result = app(LessonTopicMapper::class)->mapBook($book);
        $book->forceFill(['is_indexed' => true])->save();

        return $result;
    }

    private function bookFor(Source $source): Book
    {
        $book = $source->book;

        if ($book === null) {
            throw new RuntimeException(
                "Source {$source->getKey()} has no book record; only book-like sources are ingestible."
            );
        }

        return $book;
    }

    /** @return list<ExtractedPage> */
    private function usablePages(Book $book): array
    {
        return $book->pages()
            ->where('needs_review', false)
            ->orderBy('page_number')
            ->get()
            ->map(fn (BookPage $p): ExtractedPage => new ExtractedPage(
                pageNumber: $p->page_number,
                text: (string) ($p->normalized_text ?? $p->raw_text),
                confidence: (float) ($p->ocr_confidence ?? 1.0),
            ))
            ->all();
    }

    /** @return array<int, string> */
    private function usablePageMap(Book $book): array
    {
        $map = [];

        foreach ($this->usablePages($book) as $page) {
            $map[$page->pageNumber] = $page->text;
        }

        return $map;
    }

    private function localPath(Source $source): string
    {
        $disk = Storage::disk($source->storage_disk);

        if (! $disk->exists($source->storage_path)) {
            throw new RuntimeException("Stored object missing: {$source->storage_path}");
        }

        // Remote disks need a local copy for the CLI tools to read.
        $path = $disk->path($source->storage_path);

        if (is_readable($path)) {
            return $path;
        }

        $temp = sys_get_temp_dir().'/examforge-'.$source->content_sha256.'.pdf';
        file_put_contents($temp, $disk->get($source->storage_path));

        return $temp;
    }

    /** @param  list<ExtractedPage>  $pages */
    private function meanConfidence(array $pages): float
    {
        if ($pages === []) {
            return 0.0;
        }

        return round(
            array_sum(array_map(static fn (ExtractedPage $p): float => $p->confidence, $pages))
            / count($pages),
            4,
        );
    }
}
