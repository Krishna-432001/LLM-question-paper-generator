<?php

declare(strict_types=1);

namespace App\Domain\Questions\DTOs;

use App\Models\SourceChunk;

/**
 * A generated, not-yet-trusted question moving through the gauntlet.
 *
 * Rejections accumulate rather than short-circuit so a reviewer can see every
 * reason a candidate failed, and so rejection-code statistics can tune the
 * overgeneration factor and surface bad OCR pages.
 */
final class QuestionCandidate
{
    /** @var list<array{stage: string, code: string, detail: array<string, mixed>}> */
    public array $rejections = [];

    /** @var array<string, array{passed: bool, score: float|null, detail: array<string, mixed>}> */
    public array $checks = [];

    /** @var list<SourceChunk> */
    public array $citedChunks = [];

    public ?float $qualityScore = null;

    public ?array $embedding = null;

    /**
     * @param  array<string, string>  $options  key => text
     * @param  list<string>  $sourceRefs  e.g. ["S1","S4"]
     */
    public function __construct(
        public readonly string $ref,
        public readonly string $questionText,
        public readonly array $options,
        public readonly string $correctOption,
        public readonly string $explanation,
        public string $difficulty,
        public readonly string $bloomLevel,
        public readonly string $topic,
        public readonly ?string $subtopic,
        public readonly array $sourceRefs,
        public readonly string $supportingQuote,
        public readonly float $confidence,
        /** Shape of the stem. Every format is still four options, one correct. */
        public readonly string $format = 'mcq4',
    ) {}

    /** @param  array<string, mixed>  $raw */
    public static function fromModelOutput(array $raw, int $index): self
    {
        /** @var array<string, string> $options */
        $options = $raw['options'] ?? [];

        return new self(
            ref: 'Q'.($index + 1),
            questionText: trim((string) ($raw['question_text'] ?? '')),
            options: array_map(static fn ($v): string => trim((string) $v), $options),
            correctOption: (string) ($raw['correct_option'] ?? ''),
            explanation: trim((string) ($raw['explanation'] ?? '')),
            difficulty: (string) ($raw['difficulty'] ?? 'medium'),
            bloomLevel: (string) ($raw['bloom_level'] ?? 'understand'),
            topic: (string) ($raw['topic'] ?? ''),
            subtopic: isset($raw['subtopic']) ? (string) $raw['subtopic'] : null,
            sourceRefs: array_values(array_map('strval', (array) ($raw['source_refs'] ?? []))),
            supportingQuote: trim((string) ($raw['supporting_quote'] ?? '')),
            confidence: (float) ($raw['confidence'] ?? 0.0),
            format: (string) ($raw['format'] ?? 'mcq4'),
        );
    }

    /** @param  array<string, mixed>  $detail */
    public function reject(string $stage, string $code, array $detail = []): void
    {
        $this->rejections[] = ['stage' => $stage, 'code' => $code, 'detail' => $detail];
    }

    /** @param  array<string, mixed>  $detail */
    public function record(string $code, bool $passed, ?float $score = null, array $detail = []): void
    {
        $this->checks[$code] = ['passed' => $passed, 'score' => $score, 'detail' => $detail];
    }

    public function isRejected(): bool
    {
        return $this->rejections !== [];
    }

    public function score(string $code): float
    {
        return $this->checks[$code]['score'] ?? 0.0;
    }

    /** Stable identity for exact-duplicate detection. */
    public function normalizedStem(): string
    {
        $text = mb_strtolower($this->questionText);
        $text = preg_replace('/[^\p{L}\p{Nd}\s]/u', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->normalizedStem());
    }

    /** Text embedded for semantic duplicate detection: stem plus options. */
    public function embeddingText(): string
    {
        return $this->questionText.' '.implode(' ', $this->options);
    }
}
