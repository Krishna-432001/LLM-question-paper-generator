<?php

declare(strict_types=1);

namespace App\Infrastructure\Export;

use App\Domain\Documents\Contracts\DocumentExporter;

/**
 * The handover format for the separate student site.
 *
 * This is the one export another system parses rather than a person reads, so
 * it is the one that must not drift. Two rules keep it honest:
 *
 *   - It is emitted from the same composed payload as the PDF, never from a
 *     second query. A JSON bundle that disagreed with the printed paper would
 *     be almost impossible to notice and very expensive to discover.
 *   - It carries an explicit schema version. The student site will be deployed
 *     separately and will lag; a consumer that cannot tell which shape it has
 *     received has to guess, and guessing wrong silently mis-scores a test.
 */
final class JsonExporter implements DocumentExporter
{
    /**
     * Bump on any breaking change to the emitted shape. Additive fields do
     * not need a bump; removing or renaming one does.
     */
    private const SCHEMA_VERSION = '1.0';

    public function format(): string
    {
        return 'json';
    }

    public function mimeType(): string
    {
        return 'application/json';
    }

    public function extension(): string
    {
        return 'json';
    }

    public function export(array $composed): string
    {
        $payload = $composed['payload'];

        return json_encode(
            [
                'schema_version' => self::SCHEMA_VERSION,
                'exported_at' => now()->toIso8601String(),
                'document' => $payload['template'] ?? 'question_paper',
                // Additive, so no schema bump: which languages the paper is
                // in and in what order, without inferring it from the key
                // order of each question's `texts`.
                'language_mode' => $payload['language_mode'] ?? 'single',
                'languages' => $payload['languages'] ?? [$payload['locale'] ?? 'en'],
                'meta' => $payload['meta'] ?? [],
                'questions' => $payload['questions'] ?? [],
                // Present only on a bundle that includes the key. A paper
                // exported on its own must not leak its answers.
                'answers' => $payload['answers'] ?? null,
            ],
            // Unescaped unicode: a Tamil question paper is unreadable as
            // தமிழ், and every consumer would have to
            // decode it before it could be inspected by eye.
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
