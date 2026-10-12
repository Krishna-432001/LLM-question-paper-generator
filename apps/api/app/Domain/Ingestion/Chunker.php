<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use App\Domain\Ingestion\DTOs\Chunk;

/**
 * Structure-aware chunking.
 *
 * The rules here decide question quality more than the generation prompt does:
 *
 *  - A chunk never crosses a lesson boundary. One that does produces questions
 *    with incoherent topics and unusable citations.
 *  - 350–600 tokens with 15% overlap. Below ~300 a definition loses its
 *    referent; above ~800 the model picks trivia out of the tail.
 *  - The breadcrumb is prefixed into the chunk body, not just stored beside
 *    it, because it measurably improves both retrieval and citation accuracy.
 *  - Book-back exercises are chunked separately and excluded from generation:
 *    they are ingested as seed questions instead, which is a higher-trust
 *    origin than anything a model writes.
 */
final class Chunker
{
    private const AVG_CHARS_PER_TOKEN = 4;

    public function __construct(
        private readonly int $targetTokens = 450,
        private readonly int $maxTokens = 600,
        private readonly int $minTokens = 120,
        private readonly float $overlapRatio = 0.15,
    ) {}

    /**
     * @param  array<int, string>  $pagesByNumber  page number => normalized text
     * @param  list<array<string, mixed>>  $lessons  each with title/start_page/end_page/breadcrumb
     * @return list<Chunk>
     */
    public function chunk(array $pagesByNumber, array $lessons): array
    {
        $chunks = [];
        $index = 0;

        foreach ($lessons as $lesson) {
            $text = $this->textForRange(
                $pagesByNumber,
                (int) $lesson['start_page'],
                (int) $lesson['end_page'],
            );

            if (trim($text) === '') {
                continue;
            }

            [$body, $exercises] = $this->splitExercises($text);

            foreach ($this->segment($body) as $segment) {
                $chunks[] = $this->makeChunk($segment, $lesson, $index++, false);
            }

            if (trim($exercises) !== '') {
                foreach ($this->segment($exercises) as $segment) {
                    $chunks[] = $this->makeChunk($segment, $lesson, $index++, true);
                }
            }
        }

        return $chunks;
    }

    /**
     * Splits text into overlapping segments on paragraph boundaries, never
     * mid-sentence.
     *
     * @return list<string>
     */
    public function segment(string $text): array
    {
        $paragraphs = array_values(array_filter(
            array_map('trim', preg_split('/\n{2,}/u', $text) ?: []),
            static fn (string $p): bool => $p !== '',
        ));

        if ($paragraphs === []) {
            return [];
        }

        $segments = [];
        $current = [];
        $currentTokens = 0;

        foreach ($paragraphs as $paragraph) {
            $tokens = $this->estimateTokens($paragraph);

            // A single oversized paragraph is split on sentence boundaries
            // rather than being emitted whole.
            if ($tokens > $this->maxTokens) {
                if ($current !== []) {
                    $segments[] = implode("\n\n", $current);
                    $current = [];
                    $currentTokens = 0;
                }

                foreach ($this->splitLongParagraph($paragraph) as $piece) {
                    $segments[] = $piece;
                }

                continue;
            }

            if ($currentTokens + $tokens > $this->maxTokens && $current !== []) {
                $segments[] = implode("\n\n", $current);

                // Overlap: carry the tail of the previous segment forward so a
                // definition split across a boundary stays retrievable.
                $current = $this->overlapTail($current);
                $currentTokens = $this->estimateTokens(implode("\n\n", $current));
            }

            $current[] = $paragraph;
            $currentTokens += $tokens;

            if ($currentTokens >= $this->targetTokens) {
                $segments[] = implode("\n\n", $current);
                $current = $this->overlapTail($current);
                $currentTokens = $this->estimateTokens(implode("\n\n", $current));
            }
        }

        if ($current !== []) {
            $tail = implode("\n\n", $current);

            // Merge a runt tail into the previous segment instead of emitting
            // a fragment too small to answer anything.
            if ($this->estimateTokens($tail) < $this->minTokens && $segments !== []) {
                $segments[array_key_last($segments)] .= "\n\n".$tail;
            } else {
                $segments[] = $tail;
            }
        }

        return array_values(array_unique($segments));
    }

    public function estimateTokens(string $text): int
    {
        return (int) max(1, ceil(mb_strlen($text) / self::AVG_CHARS_PER_TOKEN));
    }

    /**
     * Importance is computed from what the book itself signals, never by a
     * model: callouts, definitions and summaries are what examiners draw from.
     */
    public function scoreImportance(string $text): float
    {
        $score = 0.5;

        if (preg_match('/\b(important|note|remember|definition|முக்கியம்|குறிப்பு)\b/iu', $text) === 1) {
            $score += 0.2;
        }

        if (preg_match('/\b(is called|is known as|refers to|எனப்படும்)\b/iu', $text) === 1) {
            $score += 0.15;
        }

        if (preg_match('/\b(summary|conclusion|சுருக்கம்)\b/iu', $text) === 1) {
            $score += 0.1;
        }

        // Dense numeric content (tables, data) is examinable but rarely prose.
        if (preg_match_all('/\p{Nd}/u', $text) > mb_strlen($text) * 0.08) {
            $score += 0.05;
        }

        return round(min(1.0, $score), 4);
    }

    /** @param  array<int, string>  $pagesByNumber */
    private function textForRange(array $pagesByNumber, int $start, int $end): string
    {
        $parts = [];

        for ($page = $start; $page <= $end; $page++) {
            if (isset($pagesByNumber[$page]) && trim($pagesByNumber[$page]) !== '') {
                $parts[] = $pagesByNumber[$page];
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Separates every exercise block from the prose.
     *
     * A lesson spanning several pages can contain more than one exercise
     * heading, so each block runs only until the next structural heading —
     * splitting once at the first occurrence would misclassify all subsequent
     * prose as exercise material and starve retrieval of source text.
     *
     * @return array{0: string, 1: string} body, exercises
     */
    private function splitExercises(string $text): array
    {
        // Built from StructureDetector's list rather than a second copy of it.
        // Two hand-maintained copies of the same knowledge is how the Tamil
        // headings came to be recognised by the detector while the chunker —
        // the one that actually sets is_exercise — went on missing every one
        // of them, and fed the book's own exercises back into generation.
        $exercisePattern = '/^[ \t]*('.StructureDetector::EXERCISE_HEADINGS.')[ \t]*[.:]?[ \t]*$/imu';
        // An exercise block ends at the next heading of ANY kind, not only the
        // next unit. Waiting for a unit heading lets one exercise section
        // consume every remaining page of a lesson.
        $headingPattern = '/^[ \t]*(unit|chapter|lesson|இயல்|பாடம்|'
            .StructureDetector::CONTENT_HEADINGS.').*$/imu';

        if (preg_match_all($exercisePattern, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [$text, ''];
        }

        preg_match_all($headingPattern, $text, $headings, PREG_OFFSET_CAPTURE);
        $headingOffsets = array_map(
            static fn (array $h): int => (int) $h[1],
            $headings[0] ?? [],
        );

        $body = [];
        $exercises = [];
        $cursor = 0;
        $length = strlen($text);

        foreach ($matches[0] as $match) {
            $start = (int) $match[1];

            if ($start < $cursor) {
                continue;   // already consumed by a previous exercise block
            }

            if ($start > $cursor) {
                $body[] = substr($text, $cursor, $start - $cursor);
            }

            // The block ends at the next structural heading, not at the end
            // of the lesson.
            $end = $length;

            foreach ($headingOffsets as $offset) {
                if ($offset > $start) {
                    $end = $offset;
                    break;
                }
            }

            $exercises[] = substr($text, $start, $end - $start);
            $cursor = $end;
        }

        if ($cursor < $length) {
            $body[] = substr($text, $cursor);
        }

        return [implode("\n\n", $body), implode("\n\n", $exercises)];
    }

    /** @param  list<string>  $paragraphs @return list<string> */
    private function overlapTail(array $paragraphs): array
    {
        $budget = (int) ($this->targetTokens * $this->overlapRatio);
        $tail = [];
        $tokens = 0;

        foreach (array_reverse($paragraphs) as $paragraph) {
            $tokens += $this->estimateTokens($paragraph);
            array_unshift($tail, $paragraph);

            if ($tokens >= $budget) {
                break;
            }
        }

        return $tail;
    }

    /** @return list<string> */
    private function splitLongParagraph(string $paragraph): array
    {
        $sentences = preg_split('/(?<=[.!?।])\s+/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) ?: [$paragraph];

        $pieces = [];
        $current = '';

        foreach ($sentences as $sentence) {
            if ($current !== '' && $this->estimateTokens($current.' '.$sentence) > $this->maxTokens) {
                $pieces[] = trim($current);
                $current = '';
            }

            $current .= ($current === '' ? '' : ' ').$sentence;
        }

        if (trim($current) !== '') {
            $pieces[] = trim($current);
        }

        return $pieces;
    }

    /** @param  array<string, mixed>  $lesson */
    private function makeChunk(string $text, array $lesson, int $index, bool $isExercise): Chunk
    {
        $breadcrumb = (string) $lesson['breadcrumb'];
        // Breadcrumb goes INSIDE the embedded body, not only in metadata.
        $body = $breadcrumb."\n\n".trim($text);

        return new Chunk(
            index: $index,
            text: $body,
            breadcrumb: $breadcrumb,
            tokenCount: $this->estimateTokens($body),
            pageStart: (int) $lesson['start_page'],
            pageEnd: (int) $lesson['end_page'],
            lessonId: $lesson['id'] ?? null,
            type: $isExercise ? 'exercise' : $this->classify($text),
            importance: $isExercise ? 0.9 : $this->scoreImportance($text),
            isExercise: $isExercise,
        );
    }

    private function classify(string $text): string
    {
        return match (true) {
            preg_match('/\b(is called|is known as|refers to|எனப்படும்)\b/iu', $text) === 1 => 'definition',
            preg_match('/\b(important|note|remember|முக்கியம்)\b/iu', $text) === 1 => 'callout',
            preg_match('/\b(summary|conclusion|சுருக்கம்)\b/iu', $text) === 1 => 'summary',
            default => 'prose',
        };
    }
}
