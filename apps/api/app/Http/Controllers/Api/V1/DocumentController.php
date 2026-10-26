<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\RenderDocumentAction;
use App\Domain\PracticePapers\PracticePaper;
use App\Http\Controllers\Controller;
use App\Models\GeneratedDocument;
use App\Models\StudyPlan;
use App\Models\Test;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $user = $request->user();
        $seesAll = $user->isPlatformAdmin() || $user->can('documents.download.org');

        $documents = GeneratedDocument::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            // A student's list is their own papers, not the whole institute's.
            ->when(! $seesAll, fn ($q) => $q->where('user_id', $user->getKey()))
            ->when($request->filled('subject_id'), fn ($q) => $q->where('documentable_id', $request->string('subject_id')))
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

        abort_unless($this->canAccess($request, $subject), 404, 'Not found');

        // An uploaded paper whose PDF had no key would print an answer key of
        // blanks. Asked for by name, that is refused with the way out; as part
        // of "everything", the key and explanations are simply left out so the
        // question paper itself still downloads.
        if ($subject instanceof Test && PracticePaper::is($subject) && ! $this->hasAnyKey($subject)) {
            $keyed = array_values(array_intersect($requested, ['answer_key', 'explanations']));

            abort_if(
                $keyed !== [] && $request->has('types'),
                422,
                'This paper has no answer key yet. Add it on the Upload paper page, then download the key.',
            );

            $requested = array_values(array_diff($requested, ['answer_key', 'explanations']));
        }

        $format = (string) $request->input('format', 'pdf');

        abort_unless(
            in_array($format, ['pdf', 'docx', 'xlsx', 'json'], true),
            422,
            "Unknown export format [{$format}].",
        );

        // A test may be printed in other languages than it was taken in: the
        // English half of a bilingual paper, or a Tamil copy of an English
        // one. Only this render sees the change; the test itself is not saved.
        if ($subject instanceof Test && $request->filled('language_id')) {
            $subject = $this->printedIn($request, $subject);
        }

        $documents = [];

        foreach ($requested as $type) {
            $documents[] = $this->shape($render->execute($subject, $type, null, $format));
        }

        return response()->json(['data' => $documents], 201);
    }

    /**
     * A copy of the test set to print in the requested languages. Questions
     * whose part is fixed to one language (Group IV Part A in Tamil) keep it.
     */
    private function printedIn(Request $request, Test $test): Test
    {
        $data = $request->validate([
            'language_id' => ['required', 'string', 'exists:languages,id'],
            'language_mode' => ['sometimes', 'in:'.implode(',', Test::LANGUAGE_MODES)],
            'secondary_language_id' => ['required_if:language_mode,'.Test::MODE_BILINGUAL, 'nullable', 'string', 'exists:languages,id', 'different:language_id'],
        ]);

        $bilingual = ($data['language_mode'] ?? Test::MODE_SINGLE) === Test::MODE_BILINGUAL;
        $copy = clone $test;
        $copy->forceFill([
            'language_id' => $data['language_id'],
            'language_mode' => $bilingual ? Test::MODE_BILINGUAL : Test::MODE_SINGLE,
            'secondary_language_id' => $bilingual ? $data['secondary_language_id'] : null,
        ]);

        return $copy->unsetRelation('language')->unsetRelation('secondaryLanguage');
    }

    public function show(Request $request, string $documentId): JsonResponse
    {
        $document = GeneratedDocument::query()->findOrFail($documentId);
        $this->assertCanDownload($request, $document);

        return response()->json(['data' => $this->shape($document)]);
    }

    /**
     * Serves the stored bytes. Re-serving never re-renders and never re-runs
     * AI, which is why unlimited downloads are free.
     */
    public function download(Request $request, string $documentId): StreamedResponse
    {
        $document = GeneratedDocument::query()->findOrFail($documentId);
        $this->assertCanDownload($request, $document);

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

    /**
     * A test's papers belong to whoever may sit the test; a plan's PDF to the
     * student it was made for. Staff who assign tests or plans in the
     * organization see them all. Tenant scope already kept other
     * organizations out; this keeps one student out of another's.
     */
    private function canAccess(Request $request, Test|StudyPlan $subject): bool
    {
        $user = $request->user();

        if ($user->isPlatformAdmin()) {
            return true;
        }

        // An uploaded practice paper — and its answer key — is its uploader's alone.
        if ($subject instanceof Test && PracticePaper::is($subject)) {
            return PracticePaper::canManage($subject, $user);
        }

        return $subject instanceof Test
            ? $subject->created_by === $user->getKey() || $subject->is_shared || $user->can('tests.create.org')
            : $subject->user_id === $user->getKey() || $user->can('plans.create.org');
    }

    private function hasAnyKey(Test $test): bool
    {
        return DB::table('question_options')
            ->whereIn('question_id', DB::table('test_questions')->where('test_id', $test->getKey())->select('question_id'))
            ->where('is_correct', true)
            ->exists();
    }

    private function assertCanDownload(Request $request, GeneratedDocument $document): void
    {
        if ($document->user_id === $request->user()->getKey()) {
            return;
        }

        // Rendering is content-addressed, so the stored row may name whoever
        // rendered it first; access follows the subject, not that user.
        $subject = match ($document->documentable_type) {
            Test::class => Test::query()->find($document->documentable_id),
            StudyPlan::class => StudyPlan::query()->find($document->documentable_id),
            default => null,
        };

        abort_unless($subject !== null && $this->canAccess($request, $subject), 404, 'Not found');
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
