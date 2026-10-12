<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ingestion\Contracts\VirusScanner;
use App\Domain\Ingestion\Exceptions\SourceQuarantinedException;
use App\Domain\Ingestion\SourceScanner;
use App\Domain\Jobs\JobStatus;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Antivirus\ClamAvScanner;
use App\Infrastructure\Antivirus\FakeVirusScanner;
use App\Jobs\Ingestion\ProcessSource;
use App\Models\AiJob;
use App\Models\Book;
use App\Models\Language;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * The scan is the first thing that touches an upload, and the only thing
 * standing between a hostile file and pdftotext.
 */
final class VirusScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        Storage::fake('local');
        config([
            'ingestion.ocr_driver' => 'fake',
            'embeddings.driver' => 'fake',
            'ingestion.antivirus.driver' => 'fake',
        ]);
        $this->app->forgetInstance(VirusScanner::class);
    }

    public function test_a_clean_upload_is_promoted_out_of_quarantine(): void
    {
        [$source, $job] = $this->makeSourceAndJob('%PDF-1.7 ordinary textbook bytes');

        $source = TenantContext::withoutTenancy(function () use ($job, $source): Source {
            (new ProcessSource($job->getKey()))->handle();

            return $source->fresh();
        });

        $this->assertSame('ready', $source->status);
        $this->assertStringStartsWith('sources/', $source->storage_path);
        Storage::disk('local')->assertExists($source->storage_path);
        Storage::disk('local')->assertMissing('quarantine/ab/book.pdf');
    }

    public function test_an_infected_upload_is_quarantined_and_never_read(): void
    {
        [$source, $job] = $this->makeSourceAndJob('%PDF-1.7 '.FakeVirusScanner::MARKER);

        [$source, $job] = TenantContext::withoutTenancy(function () use ($job, $source): array {
            (new ProcessSource($job->getKey()))->handle();

            return [$source->fresh(), $job->fresh()];
        });

        $this->assertSame('quarantined', $source->status);
        $this->assertSame('failed', $source->stageStatus('scan'));
        $this->assertNull($source->stageStatus('extract'), 'Nothing after the scan may run.');

        // Failed outright, not scheduled for a retry that would say the same.
        $this->assertSame(JobStatus::Failed, $job->status);

        // The file stays where nothing reads from.
        $this->assertSame('quarantine/ab/book.pdf', $source->storage_path);

        $audit = TenantContext::withoutTenancy(
            fn () => DB::table('audit_logs')->where('action', 'source.quarantined')->first()
        );
        $this->assertNotNull($audit);
        $this->assertStringContainsString('ExamForge.Test.Marker', (string) $audit->changes);
    }

    public function test_a_past_paper_cannot_skip_the_scan(): void
    {
        [$source] = $this->makeSourceAndJob('%PDF-1.7 '.FakeVirusScanner::MARKER);
        $scanner = app(SourceScanner::class);

        $this->expectException(SourceQuarantinedException::class);

        TenantContext::withoutTenancy(fn () => $scanner->ensureScanned($source));
    }

    public function test_ensure_scanned_runs_once(): void
    {
        [$source] = $this->makeSourceAndJob('%PDF-1.7 a past paper');
        $scanner = app(SourceScanner::class);

        TenantContext::withoutTenancy(function () use ($scanner, $source): void {
            $scanner->ensureScanned($source);
            $source->refresh();

            $this->assertSame('completed', $source->stageStatus('scan'));
            $promoted = $source->storage_path;

            // A second call is a no-op: the stage already passed.
            $scanner->ensureScanned($source);
            $this->assertSame($promoted, $source->fresh()->storage_path);
        });
    }

    public function test_clamav_replies_are_interpreted_and_unknown_ones_are_not_clean(): void
    {
        $clam = new ClamAvScanner;

        $this->assertTrue($clam->interpret('stream: OK')->clean);

        $infected = $clam->interpret('stream: Win.Test.EICAR_HDB-1 FOUND');
        $this->assertFalse($infected->clean);
        $this->assertSame('Win.Test.EICAR_HDB-1', $infected->signature);

        // A size-limit error is a failed scan, never a pass.
        $this->expectException(RuntimeException::class);
        $clam->interpret('INSTREAM size limit exceeded. ERROR');
    }

    public function test_an_unreachable_clamav_fails_the_stage_rather_than_passing_the_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'av');
        file_put_contents($path, 'bytes');

        // Port 1 on loopback: nothing listens there.
        $clam = new ClamAvScanner(host: '127.0.0.1', port: 1, timeoutSeconds: 2);

        try {
            $this->expectException(RuntimeException::class);
            $clam->scan($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_production_refuses_to_run_without_a_scanner(): void
    {
        config(['ingestion.antivirus.driver' => 'none']);
        $this->app->forgetInstance(VirusScanner::class);
        $this->app['env'] = 'production';

        try {
            $this->expectException(RuntimeException::class);
            app(VirusScanner::class);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    /** @return array{0: Source, 1: AiJob} */
    private function makeSourceAndJob(string $bytes): array
    {
        return TenantContext::withoutTenancy(function () use ($bytes): array {
            $language = Language::byCode('en');

            Storage::disk('local')->put('quarantine/ab/book.pdf', $bytes);

            $source = Source::query()->create([
                'organization_id' => null,
                'kind' => 'textbook',
                'title' => 'Scan fixture',
                'language_id' => $language->getKey(),
                'rights_basis' => 'govt_open',
                'storage_disk' => 'local',
                'storage_path' => 'quarantine/ab/book.pdf',
                'original_filename' => 'book.pdf',
                'mime_type' => 'application/octet-stream',
                'file_size_bytes' => strlen($bytes),
                'content_sha256' => hash('sha256', $bytes),
            ]);

            Book::query()->create([
                'source_id' => $source->getKey(),
                'organization_id' => null,
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
