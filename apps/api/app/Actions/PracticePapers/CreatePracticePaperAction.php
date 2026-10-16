<?php

declare(strict_types=1);

namespace App\Actions\PracticePapers;

use App\Actions\Jobs\DispatchTrackedJobAction;
use App\Actions\Sources\CreateSourceAction;
use App\Domain\Ingestion\SourceScanner;
use App\Domain\PracticePapers\DTOs\PaperSettings;
use App\Domain\PracticePapers\Exceptions\PracticePaperException;
use App\Domain\PracticePapers\PaperReader;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\PracticePapers\OcrPracticePaper;
use App\Models\Exam;
use App\Models\Language;
use App\Models\Source;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Upload a question paper, sit it as a timed test.
 *
 * The order is the safety: the file is stored in quarantine, scanned, and only
 * then read. A paper with a text layer becomes a test inside this request —
 * pdftotext and the parser take well under a second. A scanned paper — or
 * one the uploader asks to have read as a scan — is handed to the OCR queue
 * and the caller gets a job to poll instead.
 */
final class CreatePracticePaperAction
{
    public function __construct(
        private readonly CreateSourceAction $sources,
        private readonly SourceScanner $scanner,
        private readonly PaperReader $reader,
        private readonly BuildPracticeTestAction $build,
        private readonly DispatchTrackedJobAction $dispatch,
    ) {}

    /**
     * @return array{status: 'created', report: array<string, mixed>}|array{status: 'queued', job_id: string, source_id: string}
     */
    public function execute(
        User $user,
        UploadedFile $file,
        string $title,
        ?int $durationMinutes = null,
        ?string $examId = null,
        ?string $language = null,
        bool $readAsScan = false,
    ): array {
        $context = app(TenantContext::class);

        // Before the upload is stored: a paper with nowhere to belong, or one
        // asked to be read by an OCR that is not there, should fail before it
        // costs a scan.
        $exam = $this->examFor($user, $examId);

        if ($readAsScan && ! $this->reader->ocrAvailable()) {
            throw PracticePaperException::ocrNotInstalled();
        }
        $preferred = $language ?? $this->preferredLanguage($user);

        $source = $this->sources->execute(
            file: $file,
            kind: 'previous_paper',
            title: $title,
            // Provisional: the paper's real languages are only known once it
            // has been read, and they are recorded per question, not here.
            languageId: (string) (Language::query()->where('code', $preferred ?? 'en')->value('id')
                ?? Language::query()->where('code', 'en')->value('id')),
            // The uploader's own copy of a paper, used only by them.
            rightsBasis: 'owned',
        );

        // Throws SourceQuarantinedException for an infected file, which the
        // API renders as a 422 — nothing below ever sees those bytes.
        $this->scanner->ensureScanned($source);
        $source->refresh();

        $settings = new PaperSettings(
            organizationId: $context->organizationId,
            userId: (string) $user->getKey(),
            examId: $exam->getKey(),
            title: $title,
            durationMinutes: $durationMinutes,
            language: $preferred,
        );

        // Asked to read the page images: the text layer is there but its Tamil
        // came out broken, and the pictures of the letters are still right.
        if (! $readAsScan) {
            $reading = $this->reader->fromTextLayer($source);

            if ($reading->questions !== []) {
                return ['status' => 'created', 'report' => $this->build->execute($source, $reading, $settings)];
            }

            // Text everywhere but no questions: not a paper this tool can
            // read, and OCR would only read the same text again.
            if (! $reading->looksScanned) {
                throw PracticePaperException::noQuestions();
            }

            if (! $this->reader->ocrAvailable()) {
                throw PracticePaperException::scannedWithoutOcr();
            }
        }

        $job = $this->dispatch->execute(
            type: 'ocr',
            jobClass: OcrPracticePaper::class,
            input: ['source_id' => $source->getKey(), 'settings' => $settings->toArray()],
            organizationId: $context->organizationId,
            userId: (string) $user->getKey(),
            subjectType: Source::class,
            subjectId: $source->getKey(),
        );

        return ['status' => 'queued', 'job_id' => $job->getKey(), 'source_id' => $source->getKey()];
    }

    /**
     * The exam asked for, else the student's target exam, else any active
     * exam they can see. A practice paper is not about an exam, but every test
     * row must name one; the fallback only satisfies that.
     */
    private function examFor(User $user, ?string $examId): Exam
    {
        if ($examId !== null) {
            return Exam::query()->active()->find($examId) ?? throw PracticePaperException::examNotAvailable();
        }

        if ($user->target_exam_id !== null) {
            $target = Exam::query()->active()->find($user->target_exam_id);

            if ($target !== null) {
                return $target;
            }
        }

        return Exam::query()->active()->orderBy('created_at')->first()
            ?? throw PracticePaperException::noExam();
    }

    private function preferredLanguage(User $user): ?string
    {
        if ($user->preferred_language_id !== null) {
            $code = Language::query()->whereKey($user->preferred_language_id)->value('code');

            if (in_array($code, ['en', 'ta'], true)) {
                return $code;
            }
        }

        return in_array($user->locale, ['en', 'ta'], true) ? $user->locale : null;
    }
}
