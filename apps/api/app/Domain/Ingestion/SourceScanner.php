<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Ingestion\Contracts\VirusScanner;
use App\Domain\Ingestion\Exceptions\SourceQuarantinedException;
use App\Models\Source;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The scan stage, shared by every path that reads an upload.
 *
 * Books reach it through ProcessSource; previous-year papers are parsed
 * straight from the PDF and never enter that pipeline, so they call
 * ensureScanned() first. Without that, a past paper would be the one upload
 * that reached pdftotext unscanned.
 */
final class SourceScanner
{
    public function __construct(
        private readonly VirusScanner $scanner,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Scan now. Clean files are promoted out of the quarantine prefix; an
     * infected one is quarantined and the exception is permanent.
     *
     * @return array<string, mixed>
     */
    public function scan(Source $source): array
    {
        if ($source->storage_path === '' || $source->content_sha256 === '') {
            throw new RuntimeException("Source {$source->getKey()} has no stored object.");
        }

        [$path, $isTemporary] = $this->localCopy($source);

        try {
            $result = $this->scanner->scan($path);
        } finally {
            if ($isTemporary) {
                @unlink($path);
            }
        }

        if (! $result->clean) {
            $source->forceFill([
                'status' => 'quarantined',
                'error_message' => "Malware scan: {$result->signature}",
            ])->save();

            $this->audit->record('source.quarantined', $source, [
                'engine' => $result->engine,
                'signature' => $result->signature,
                'content_sha256' => $source->content_sha256,
            ]);

            throw SourceQuarantinedException::infected($source->getKey(), $result->signature);
        }

        $this->promote($source);

        return $result->toArray();
    }

    /**
     * Scan unless the scan stage already passed. For readers that bypass the
     * ingestion pipeline.
     */
    public function ensureScanned(Source $source): void
    {
        if ($source->status === 'quarantined') {
            throw SourceQuarantinedException::infected($source->getKey(), null);
        }

        if ($source->stageStatus('scan') === 'completed') {
            return;
        }

        $this->scan($source);
        $source->markStage('scan', 'completed');
    }

    /**
     * Uploads land under the quarantine prefix so that an unscanned object is
     * never on a read path. A pass moves it under the sources prefix.
     */
    private function promote(Source $source): void
    {
        $prefix = config('examforge.uploads.quarantine_prefix').'/';

        if (! str_starts_with($source->storage_path, $prefix)) {
            return;
        }

        $target = config('examforge.uploads.sources_prefix').'/'.substr($source->storage_path, strlen($prefix));
        $disk = Storage::disk($source->storage_disk);

        if (! $disk->exists($target)) {
            $disk->move($source->storage_path, $target);
        }

        $source->forceFill(['storage_path' => $target])->save();
    }

    /** @return array{0: string, 1: bool} path, and whether it is a temp copy */
    private function localCopy(Source $source): array
    {
        $disk = Storage::disk($source->storage_disk);

        if (! $disk->exists($source->storage_path)) {
            throw new RuntimeException("Stored object missing: {$source->storage_path}");
        }

        $path = $disk->path($source->storage_path);

        if (is_readable($path)) {
            return [$path, false];
        }

        // Remote disks: the scanner needs a local file to stream from.
        $temp = tempnam(sys_get_temp_dir(), 'examforge-scan-');
        file_put_contents($temp, $disk->get($source->storage_path));

        return [$temp, true];
    }
}
