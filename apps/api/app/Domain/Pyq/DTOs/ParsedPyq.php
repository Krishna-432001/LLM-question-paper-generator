<?php

declare(strict_types=1);

namespace App\Domain\Pyq\DTOs;

/**
 * One question recovered from a past paper, before it reaches the database.
 */
final class ParsedPyq
{
    /** @param  array<string, string>  $options */
    public function __construct(
        public readonly int $number,
        public readonly string $stem,
        public readonly array $options,
        public readonly ?string $correctOption,
    ) {}

    public function withAnswer(?string $option): self
    {
        return new self($this->number, $this->stem, $this->options, $option);
    }

    /** @return array<string, mixed> */
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
