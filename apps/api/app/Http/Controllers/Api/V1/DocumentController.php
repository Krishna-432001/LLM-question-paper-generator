<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\RenderDocumentAction;
use App\Http\Controllers\Controller;
use App\Models\GeneratedDocument;
use App\Models\StudyPlan;
use App\Models\Test;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentController extends Controller
{
    private const MIME_TYPES = [
        'pdf' => 'application/pdf',
        'json' => 'application/json',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** Document types each subject can produce. */
    private const ALLOWED = [
        'tests' => ['question_paper', 'answer_key', 'explanations'],
        'study-plans' => ['study_plan'],
    ];

    public function index(Request $request): JsonResponse
    {
        $documents = GeneratedDocument::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->where('status', 'ready')
            ->latest('created_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $documents->map($this->shape(...))->all()]);
    }

    /**
     * Renders one or more documents for a subject.
     *
     * Synchronous: rendering a paper is string building plus a layout pass, so
     * there is nothing to queue. Only ingestion and generation are async.
     */
    public function generate(
        Request $request,
        string $subjectType,
        string $subjectId,
        RenderDocumentAction $render,
    ): JsonResponse {
        abort_unless(isset(self::ALLOWED[$subjectType]), 404, 'Unknown document subject.');

        $requested = $request->array('types') ?: self::ALLOWED[$subjectType];
        $invalid = array_diff($requested, self::ALLOWED[$subjectType]);

        abort_if(
            $invalid !== [],
            422,
            'Cannot produce '.implode(', ', $invalid).' from a '.rtrim($subjectType, 's').'.',
        );

        $subject = match ($subjectType) {
            'tests' => Test::query()->findOrFail($subjectId),
            default => StudyPlan::query()->findOrFail($subjectId),
        };

        $format = (string) $request->input('format', 'pdf');

        abort_unless(
            in_array($format, ['pdf', 'docx', 'xlsx', 'json'], true),
            422,
            "Unknown export format [{$format}].",
        );

        $documents = [];

        foreach ($requested as $type) {
            $documents[] = $this->shape($render->execute($subject, $type, null, $format));
        }

        return response()->json(['data' => $documents], 201);
    }

    public function show(string $documentId): JsonResponse
    {
        return response()->json([
            'data' => $this->shape(GeneratedDocument::query()->findOrFail($documentId)),
        ]);
    }

    /**
     * Serves the stored bytes. Re-serving never re-renders and never re-runs
     * AI, which is why unlimited downloads are free.
     */
    public function download(string $documentId): StreamedResponse
    {
        $document = GeneratedDocument::query()->findOrFail($documentId);

        abort_unless($document->isReady(), 409, 'This document is not ready yet.');

        $disk = Storage::disk($document->storage_disk);

        abort_unless($disk->exists($document->storage_path), 404, 'The stored file is missing.');

        $document->increment('download_count');

        return $disk->download(
            $document->storage_path,
            $this->filename($document),
            // Serving a DOCX as application/pdf makes the browser save a file
            // Word then refuses to open.
            ['Content-Type' => self::MIME_TYPES[$document->format] ?? 'application/pdf'],
        );
    }

    private function filename(GeneratedDocument $document): string
    {
        // The document code is printed on the page too, so a downloaded file
        // can always be matched back to the paper it belongs to.
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $document->document_code.'-'.$document->title)
            .'.'.($document->format ?? 'pdf');
    }

    /** @return array<string, mixed> */
    private function shape(GeneratedDocument $document): array
    {
        return [
            'id' => $document->getKey(),
            'type' => $document->type,
            'format' => $document->format,
            'title' => $document->title,
            'document_code' => $document->document_code,
            'status' => $document->status,
            'page_count' => $document->page_count,
            'file_size_bytes' => $document->file_size_bytes,
            'download_count' => $document->download_count,
            'download_url' => "/api/v1/documents/{$document->getKey()}/download",
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }
}
