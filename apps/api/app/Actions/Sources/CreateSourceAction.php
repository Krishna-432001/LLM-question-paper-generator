<?php

declare(strict_types=1);

namespace App\Actions\Sources;

use App\Domain\Tenancy\TenantContext;
use App\Models\Book;
use App\Domain\Audit\AuditRecorder;
use App\Models\Source;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class CreateSourceAction
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function execute(
        UploadedFile $file,
        string $kind,
        string $title,
        string $languageId,
        string $rightsBasis,
        array $meta = [],
        bool $isPlatformContent = false,
    ): Source {
        $context = app(TenantContext::class);
        $hash = hash_file('sha256', $file->getRealPath());
        $organizationId = $isPlatformContent ? null : $context->organizationId;

        // Content-addressed dedup: re-uploading the same file is a no-op that
        // returns the existing source instead of paying to ingest it twice.
        $existing = $this->existing($hash, $organizationId);

        if ($existing !== null) {
            return $existing;
        }

        // Uploads land in a quarantine prefix and are promoted only after the
        // scan stage passes, so an unscanned object is never in the read path.
        // On the sources disk, which can sit on a drive with room for books.
        $disk = (string) config('filesystems.sources_disk', config('filesystems.default'));
        $path = $file->store(
            config('examforge.uploads.quarantine_prefix').'/'.substr($hash, 0, 2),
            $disk,
        );

        return $this->record(
            $disk, $path, $file->getClientOriginalName(), (string) $file->getClientMimeType(), (int) $file->getSize(),
            $hash, $kind, $title, $languageId, $rightsBasis, $meta, $organizationId,
        );
    }

    /**
     * A file already in storage registered as a source where it lies — a
     * folder of textbooks read in place rather than copied. The same dedup
     * and records as an upload. The file is never moved or changed: it is
     * outside the quarantine prefix, which is all the scanner ever promotes.
     *
     * @param  array<string, mixed>  $meta
     */
    public function registerStored(
        string $disk,
        string $path,
        string $originalName,
        string $kind,
        string $title,
        string $languageId,
        string $rightsBasis,
        array $meta = [],
        bool $isPlatformContent = false,
    ): Source {
        $absolute = Storage::disk($disk)->path($path);
        $hash = hash_file('sha256', $absolute);
        // Platform content needs no tenant: an import run from the console has none.
        $organizationId = $isPlatformContent ? null : app(TenantContext::class)->organizationId;

        return $this->existing($hash, $organizationId) ?? $this->record(
            $disk, $path, $originalName, (string) (mime_content_type($absolute) ?: 'application/pdf'), (int) filesize($absolute),
            $hash, $kind, $title, $languageId, $rightsBasis, $meta, $organizationId,
        );
    }

    private function existing(string $hash, ?string $organizationId): ?Source
    {
        return Source::query()
            ->where('content_sha256', $hash)
            ->when(
                $organizationId === null,
                fn ($q) => $q->whereNull('organization_id'),
                fn ($q) => $q->where('organization_id', $organizationId),
            )
            ->first();
    }

    /** @param  array<string, mixed>  $meta */
    private function record(
        string $disk,
        string $path,
        string $originalName,
        string $mimeType,
        int $size,
        string $hash,
        string $kind,
        string $title,
        string $languageId,
        string $rightsBasis,
        array $meta,
        ?string $organizationId,
    ): Source {
        // Unbound outside a request (a console import): nobody uploaded it.
        $context = app()->bound(TenantContext::class) ? app(TenantContext::class) : null;

        return DB::transaction(function () use (
            $originalName, $mimeType, $size, $kind, $title, $languageId, $rightsBasis, $meta,
            $organizationId, $hash, $path, $context, $disk
        ): Source {
            $source = new Source([
                'organization_id' => $organizationId,
                'kind' => $kind,
                'title' => $title,
                'language_id' => $languageId,
                'rights_basis' => $rightsBasis,
                'publisher' => $meta['publisher'] ?? null,
                'edition' => $meta['edition'] ?? null,
                'publication_year' => $meta['publication_year'] ?? null,
                'storage_disk' => $disk,
                'storage_path' => $path,
                'original_filename' => $originalName,
                'mime_type' => $mimeType,
                'file_size_bytes' => $size,
                'content_sha256' => $hash,
                'status' => 'uploaded',
                'uploaded_by' => $context?->userId,
            ]);

            if ($organizationId === null) {
                $source->asPlatformOwned();
            }

            $source->save();

            if ($kind === 'textbook' || $kind === 'reference_book') {
                $book = new Book([
                    'source_id' => $source->getKey(),
                    'organization_id' => $organizationId,
                    'board' => $meta['board'] ?? null,
                    'standard' => $meta['standard'] ?? null,
                    'subject_name' => $meta['subject_name'] ?? null,
                    'medium_language_id' => $languageId,
                ]);

                if ($organizationId === null) {
                    $book->asPlatformOwned();
                }

                $book->save();
            }

            // Rights basis is the field that matters here: it is the record of
            // why this material was legal to ingest, and it is asserted at
            // upload time by a person.
            app(AuditRecorder::class)->record('source.uploaded', $source, [
                'title' => $source->title,
                'kind' => $source->kind,
                'rights_basis' => $source->rights_basis,
                'original_filename' => $source->original_filename,
                'content_sha256' => $source->content_sha256,
            ]);

            return $source;
        });
    }
}
