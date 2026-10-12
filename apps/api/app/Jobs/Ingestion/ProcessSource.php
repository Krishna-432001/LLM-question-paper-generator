<?php

declare(strict_types=1);

namespace App\Jobs\Ingestion;

use App\Domain\Ingestion\Exceptions\SourceQuarantinedException;
use App\Domain\Ingestion\IngestionPipeline;
use App\Domain\Ingestion\SourceScanner;
use App\Jobs\TrackedJob;
use App\Models\AiJob;
use App\Models\Source;
use RuntimeException;

/**
 * Engine 1, ingestion half: upload → readable, chunked, embedded knowledge.
 *
 * Stages are resumable. Each records its result in sources.pipeline_state
 * before advancing, so a failure at "embed" on a 200-page book re-runs only
 * the embedding, not the 22-minute OCR.
 */
final class ProcessSource extends TrackedJob
{
    /** Progress weights: OCR dominates wall-clock on a real textbook. */
    private const STAGE_WEIGHTS = [
        'scan' => 5,
        'extract' => 45,
        'normalize' => 10,
        'structure' => 15,
        'chunk' => 10,
        'embed' => 10,
        'map' => 5,
    ];

    /** @return array<string, mixed> */
    protected function execute(AiJob $job): array
    {
        $source = Source::query()->findOrFail($job->subject_id);
        $pipeline = app(IngestionPipeline::class);

        $results = [];
        $skipped = [];
        $progress = 0;

        foreach (Source::STAGES as $stage) {
            if ($source->stageStatus($stage) === 'completed') {
                $skipped[] = $stage;
                $progress += self::STAGE_WEIGHTS[$stage];
                $job->progressTo($progress, "Skipped {$stage} (already complete)");

                continue;
            }

            $source->markStage($stage, 'processing', $job->getKey());
            $job->progressTo($progress, ucfirst($stage).'…');

            try {
                $results[$stage] = $this->runStage($stage, $source, $pipeline);
            } catch (\Throwable $e) {
                $source->markStage($stage, 'failed', $job->getKey());
                $source->forceFill([
                    // Quarantine is a verdict on the file, not a processing
                    // failure: it must not look retryable in the console.
                    'status' => $e instanceof SourceQuarantinedException ? 'quarantined' : 'failed',
                    'error_message' => mb_substr($e->getMessage(), 0, 1000),
                ])->save();

                throw $e;
            }

            $source->markStage($stage, 'completed', $job->getKey());
            $source->refresh();

            $progress += self::STAGE_WEIGHTS[$stage];
            $job->progressTo($progress, $this->summarise($stage, $results[$stage]));
        }

        $source->forceFill(['status' => 'ready', 'error_message' => null])->save();

        return [
            'source_id' => $source->getKey(),
            'stages' => $results,
            'stages_skipped' => $skipped,
        ];
    }

    /** @return array<string, mixed> */
    private function runStage(string $stage, Source $source, IngestionPipeline $pipeline): array
    {
        return match ($stage) {
            'scan' => app(SourceScanner::class)->scan($source),
            'extract' => $pipeline->extract($source),
            'normalize' => $pipeline->normalize($source),
            'structure' => $pipeline->detectStructure($source),
            'chunk' => $pipeline->chunk($source),
            'embed' => $pipeline->embed($source),
            'map' => $pipeline->mapToSyllabus($source),
            default => throw new RuntimeException("Unknown ingestion stage: {$stage}"),
        };
    }

    /** @param  array<string, mixed>  $result */
    private function summarise(string $stage, array $result): string
    {
        return match ($stage) {
            'extract' => "Extracted {$result['pages']} pages via {$result['engine']}"
                .($result['flagged'] > 0 ? " ({$result['flagged']} flagged for review)" : ''),
            'structure' => "Detected {$result['chapters']} chapters, {$result['lessons']} lessons",
            'chunk' => "Created {$result['chunks']} chunks ({$result['exercises']} exercise)",
            'embed' => "Embedded {$result['embedded']} chunks ({$result['reused']} reused)",
            default => ucfirst($stage).' complete',
        };
    }
}
