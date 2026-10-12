<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Documents\Contracts\DocumentExporter;
use App\Domain\Documents\Contracts\PdfRenderer;
use App\Domain\Documents\DocumentComposer;
use App\Domain\Tenancy\TenantContext;
use App\Models\GeneratedDocument;
use App\Models\StudyPlan;
use App\Models\Test;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Materialises a document once and re-serves it forever.
 *
 * A download must never re-render, and it must never re-run AI. Identical
 * requests from thousands of students resolve to one stored object, which is
 * what makes unlimited downloads cost effectively nothing.
 */
final class RenderDocumentAction
{
    /** @param  array<string, DocumentExporter>  $exporters  keyed by format */
    public function __construct(
        private readonly DocumentComposer $composer,
        private readonly PdfRenderer $renderer,
        private readonly array $exporters = [],
    ) {}

    public function execute(
        Model $subject,
        string $type,
        ?string $languageId = null,
        string $format = 'pdf',
    ): GeneratedDocument {
        // Rendering also runs from queued jobs and console commands, where no
        // request-scoped tenant exists. The subject already carries the
        // organization it belongs to, so a missing context is not an error.
        $context = app()->bound(TenantContext::class) ? app(TenantContext::class) : null;
        $languageId ??= $subject->language_id;

        $hash = $this->contentHash($subject, $type, $languageId, $format);

        // Reuse: the same inputs always produce the same document, so if one
        // already exists for this hash it is served rather than rebuilt.
        $existing = GeneratedDocument::query()
            ->where('documentable_type', $subject::class)
            ->where('documentable_id', $subject->getKey())
            ->where('type', $type)
            ->where('language_id', $languageId)
            ->where('content_hash', $hash)
            ->where('format', $format)
            ->first();

        if ($existing?->isReady()) {
            return $existing;
        }

        $code = $this->documentCode($type);
        $composed = $this->compose($subject, $type, $code, $format);

        $document = $existing ?? GeneratedDocument::query()->create([
            'organization_id' => $subject->organization_id ?? $context?->organizationId,
            'user_id' => $context?->userId,
            'type' => $type,
            'documentable_type' => $subject::class,
            'documentable_id' => $subject->getKey(),
            'language_id' => $languageId,
            'title' => $composed['title'],
            'document_code' => $code,
            'content_hash' => $hash,
            'format' => $format,
            'status' => 'rendering',
        ]);

        try {
            if ($format === 'pdf') {
                $result = $this->renderer->render($composed['html'], [
                    'title' => $composed['title'],
                    'footer' => '<div style="font-size:8pt;color:#888;text-align:center">'
                        .e($code).' &middot; page {PAGENO} of {nbpg}</div>',
                ]);
                $extension = 'pdf';
            } else {
                $exporter = $this->exporters[$format] ?? null;

                if ($exporter === null) {
                    throw new RuntimeException("No exporter is registered for format [{$format}].");
                }

                // Page count is meaningless outside a paginated format, and
                // reporting a fabricated 1 would be worse than reporting none.
                $result = ['content' => $exporter->export($composed), 'pages' => 0];
                $extension = $exporter->extension();
            }

            $path = sprintf(
                'documents/%s/%s.%s',
                substr($hash, 0, 2),
                $document->getKey(),
                $extension,
            );

            Storage::disk(config('pdf.disk'))->put($path, $result['content']);

            // A produced document is a thing that leaves the building. What
            // went out, in what format, and which version of each question it
            // pinned, has to be reconstructable later.
            app(AuditRecorder::class)->record('document.rendered', $document, [
                'type' => $type,
                'format' => $format,
                'content_hash' => $hash,
            ]);

            $document->forceFill([
                'storage_disk' => config('pdf.disk'),
                'storage_path' => $path,
                'file_size_bytes' => strlen($result['content']),
                'page_count' => $result['pages'],
                'render_payload' => $composed['payload'],
                'status' => 'ready',
                'error_message' => null,
            ])->save();

            return $document->fresh();
        } catch (\Throwable $e) {
            $document->forceFill([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            throw $e;
        }
    }

    /**
     * @return array{payload: array<string, mixed>, html: string, title: string}
     */
    private function compose(Model $subject, string $type, string $code, string $format = 'pdf'): array
    {
        return match (true) {
            // A machine format gets paper AND key from the one composed
            // payload, so an exported bundle can never contradict the PDF
            // printed from the same test.
            $subject instanceof Test && $type === 'question_paper' && $format !== 'pdf'
                => $this->composer->portableBundle($subject, $code),
            $subject instanceof Test && $type === 'question_paper' => $this->composer->questionPaper($subject, $code),
            $subject instanceof Test && $type === 'answer_key' => $this->composer->answerKey($subject, $code),
            $subject instanceof Test && $type === 'explanations' => $this->composer->explanations($subject, $code),
            $subject instanceof StudyPlan && $type === 'study_plan' => $this->composer->studyPlan($subject, $code),
            default => throw new RuntimeException(
                'Cannot render a '.$type.' from '.class_basename($subject).'.'
            ),
        };
    }

    /**
     * Hash over everything that can change the rendered bytes. Comparing it
     * makes "has anything actually changed?" free, and lets an edited question
     * mark its papers stale without scanning them.
     */
    private function contentHash(
        Model $subject,
        string $type,
        string $languageId,
        string $format,
    ): string {
        // The renderer is part of the identity: switching engines produces
        // different bytes, so a document rendered by the fake engine must not
        // be served as though mPDF had produced it. The format is part of it
        // for the same reason — without it the DOCX of a paper would collide
        // with its PDF and one would be served as the other.
        $parts = [
            $type,
            $format,
            $languageId,
            (string) config('pdf.template_version'),
            $this->renderer->name(),
        ];

        if ($subject instanceof Test) {
            $subject->loadMissing('questions');
            // Question VERSIONS, not ids: an edited question changes the hash,
            // so the paper is rebuilt rather than silently going out of date.
            $parts[] = $subject->questions
                ->map(fn ($q) => $q->question_id.':'.$q->question_version)
                ->implode(',');
            $parts[] = (string) $subject->updated_at;
        }

        if ($subject instanceof StudyPlan) {
            $parts[] = $subject->getKey().':'.$subject->revision;
            $parts[] = (string) $subject->updated_at;
        }

        return hash('sha256', implode('|', $parts));
    }

    private function documentCode(string $type): string
    {
        $prefix = match ($type) {
            'question_paper' => 'QP',
            'answer_key' => 'AK',
            'explanations' => 'EX',
            'study_plan' => 'SP',
            default => 'DOC',
        };

        return sprintf(
            '%s-%s-%s',
            config('examforge.documents.code_prefix'),
            $prefix,
            Str::upper(Str::random(6)),
        );
    }
}
