<?php

declare(strict_types=1);

namespace App\Domain\Pyq\DTOs;

/**
 * One question recovered from a past paper, before it reaches the database.
 *
 * `stem` and `options` are the version printed first, in `language`. A
 * bilingual TNPSC paper prints every question twice, and `translations` holds
 * each version the parser could read in full, keyed by language code with the
 * first-printed one first. A question printed once has one entry, so callers
 * can always iterate it without special-casing monolingual papers.
 */
final class ParsedPyq
{
    /** @var array<string, array{stem: string, options: array<string, string>}> */
    public readonly array $translations;

    /**
     * @param  array<string, string>  $options
     * @param  array<string, array{stem: string, options: array<string, string>}>  $translations
     */
    public function __construct(
        public readonly int $number,
        public readonly string $stem,
        public readonly array $options,
        public readonly ?string $correctOption,
        public readonly string $language = 'en',
        array $translations = [],
    ) {
        $this->translations = $translations !== []
            ? $translations
            : [$language => ['stem' => $stem, 'options' => $options]];
    }

    public function withAnswer(?string $option): self
    {
        return new self(
            $this->number,
            $this->stem,
            $this->options,
            $option,
            $this->language,
            $this->translations,
        );
    }

    public function isBilingual(): bool
    {
        return count($this->translations) > 1;
    }

    /** @return list<string> */
    public function languages(): array
    {
        return array_keys($this->translations);
    }

    /**
     * The row shape of previous_year_questions. Only the first-printed
     * version: that table holds one text per question, and the analysis that
     * reads it matches on stems, so a second language would only add noise.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'question_number' => $this->number,
            'question_text' => $this->stem,
            'option_a' => $this->options['A'] ?? null,
            'option_b' => $this->options['B'] ?? null,
            'option_c' => $this->options['C'] ?? null,
            'option_d' => $this->options['D'] ?? null,
            'correct_option' => $this->correctOption,
        ];
    }
}
