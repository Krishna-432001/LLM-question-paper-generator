<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Jobs\JobStatus;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\Ingestion\ProcessSource;
use App\Models\AiJob;
use App\Models\Book;
use App\Models\Language;
use App\Models\Source;
use App\Models\SourceChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Engine 1, ingestion half, end to end: upload → pages → structure → chunks →
 * embeddings, with no network and no external binaries.
 */
final class IngestionPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        Storage::fake('local');
        config(['ingestion.ocr_driver' => 'fake', 'embeddings.driver' => 'fake']);
    }

    public function test_it_ingests_a_source_into_chunks_and_embeddings(): void
    {
        [$source, $job] = $this->makeSourceAndJob();

        // Reads stay inside the bypass: ai_jobs is a tenant-private table, and
        // a platform-owned job (organization_id IS NULL) is correctly hidden
        // from a caller with no tenant context.
        [$job, $source] = TenantContext::withoutTenancy(function () use ($job, $source): array {
            (new ProcessSource($job->getKey()))->handle();

            return [$job->fresh(), $source->fresh()];
        });

        $this->assertSame(JobStatus::Completed, $job->status);
        $this->assertSame(100, $job->progress);
        $this->assertSame('ready', $source->status);

        // Every stage recorded itself, which is what makes a resume possible.
        foreach (Source::STAGES as $stage) {
            $this->assertSame('completed', $source->stageStatus($stage), "stage {$stage}");
        }

        $book = TenantContext::withoutTenancy(fn () => $source->book);
        $this->assertSame(12, $book->pages()->count());
        $this->assertGreaterThan(0, $book->chapters()->count());

        $chunks = SourceChunk::query()->where('source_id', $source->getKey())->get();
        $this->assertGreaterThan(0, $chunks->count(), 'no chunks were produced');

        // The breadcrumb is inside the chunk body, not only beside it: that is
        // what makes retrieval and citation accurate.
        $this->assertStringContainsString($chunks->first()->breadcrumb, $chunks->first()->text);

        $embeddings = DB::table('source_embeddings')->count();
        $this->assertSame($chunks->count(), $embeddings, 'every chunk must be embedded');
    }

    public function test_book_back_exercises_are_separated_from_generation_source(): void
    {
        [$source, $job] = $this->makeSourceAndJob();

        TenantContext::withoutTenancy(fn () => (new ProcessSource($job->getKey()))->handle());

        $exercises = SourceChunk::query()
            ->where('source_id', $source->getKey())
            ->where('is_exercise', true)
            ->count();

        $this->assertGreaterThan(0, $exercises, 'exercise blocks should be chunked separately');

        // Generation must never draw from book-back questions: they are seed
        // questions with a higher-trust origin, not source material.
        $generationPool = SourceChunk::query()
            ->where('source_id', $source->getKey())
            ->generationSource()
            ->get();

        $this->assertGreaterThan(0, $generationPool->count());
        $this->assertTrue($generationPool->every(fn (SourceChunk $c): bool => $c->is_exercise === false));
    }

    public function test_rerunning_reuses_completed_stages_and_embeddings(): void
    {
        [$source, $job] = $this->makeSourceAndJob();
        TenantContext::withoutTenancy(fn () => (new ProcessSource($job->getKey()))->handle());

        $firstCount = DB::table('source_embeddings')->count();

        // A second run on a fresh job must not redo the expensive stages.
        $second = TenantContext::withoutTenancy(function () use ($source): AiJob {
            $job = AiJob::query()->create([
                'type' => 'ingest', 'queue' => 'ingest',
                'subject_type' => Source::class, 'subject_id' => $source->getKey(),
            ]);

            (new ProcessSource($job->getKey()))->handle();

            return $job->fresh();
        });

        $this->assertSame(JobStatus::Completed, $second->status);
        $this->assertCount(count(Source::STAGES), $second->output['stages_skipped']);
        $this->assertSame($firstCount, DB::table('source_embeddings')->count());
    }

    /** @return array{0: Source, 1: AiJob} */
    private function makeSourceAndJob(): array
    {
        return TenantContext::withoutTenancy(function (): array {
            $language = Language::byCode('en');

            Storage::disk('local')->put('quarantine/ab/book.pdf', '%PDF-1.7 fake bytes');

            $source = Source::query()->create([
                'organization_id' => null,
                'kind' => 'textbook',
                'title' => '6th Standard Science (TN State Board)',
                'language_id' => $language->getKey(),
                'rights_basis' => 'govt_open',
                'storage_disk' => 'local',
                'storage_path' => 'quarantine/ab/book.pdf',
                'original_filename' => 'science6.pdf',
                // Not application/pdf, so the pipeline takes the OCR branch
                // rather than trying to read a text layer that is not there.
                'mime_type' => 'application/octet-stream',
                'file_size_bytes' => 19,
                'content_sha256' => hash('sha256', 'science6'),
            ]);

            Book::query()->create([
                'source_id' => $source->getKey(),
                'organization_id' => null,
                'board' => 'TN State Board',
                'standard' => '6',
                'subject_name' => 'Science',
                'medium_language_id' => $language->getKey(),
            ]);

            $job = AiJob::query()->create([
                'type' => 'ingest', 'queue' => 'ingest',
                'subject_type' => Source::class, 'subject_id' => $source->getKey(),
            ]);

            return [$source->fresh(), $job];
        });
    }
}
