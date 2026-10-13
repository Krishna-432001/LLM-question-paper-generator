<?php

declare(strict_types=1);

namespace App\Jobs\PracticePapers;

use App\Actions\PracticePapers\BuildPracticeTestAction;
use App\Domain\Ingestion\SourceScanner;
use App\Domain\PracticePapers\DTOs\PaperSettings;
use App\Domain\PracticePapers\Exceptions\PracticePaperException;
use App\Domain\PracticePapers\PaperReader;
use App\Jobs\TrackedJob;
use App\Models\AiJob;
use App\Models\Source;

/**
 * A scanned question paper: OCR every page, then build the test exactly as an
 * upload with a text layer would have.
 *
 * Queued because OCR is minutes, not milliseconds, and an upload request that
 * holds a connection open that long times out in the browser first. The job's
 * output is the same report the upload would have returned, test_id included,
 * so the page polling it needs nothing extra to start the test.
 */
final class OcrPracticePaper extends TrackedJob
{
    /** @return array<string, mixed> */
    protected function execute(AiJob $job): array
    {
        $source = Source::query()->findOrFail($job->input['source_id']);
        $settings = PaperSettings::fromArray((array) $job->input['settings']);

        // Already scanned at upload; repeated in case the source was replaced
        // or quarantined since. It is a no-op once the stage has passed.
        app(SourceScanner::class)->ensureScanned($source);
        $source->refresh();

        try {
            $job->progressTo(10, 'Reading the scanned pages…');
            $reading = app(PaperReader::class)->fromOcr($source);

            if ($reading->questions === []) {
                throw PracticePaperException::noQuestions(afterOcr: true);
            }

            $job->progressTo(90, 'Building the test…');

            return app(BuildPracticeTestAction::class)->execute($source, $reading, $settings);
        } catch (PracticePaperException $e) {
            // The job ledger keeps only the exception's class as its error
            // code; the reason the page translates is kept beside it.
            $job->forceFill(['output' => ['error' => ['code' => $e->reason, 'message' => $e->getMessage()]]])->save();

            throw $e;
        }
    }
}
