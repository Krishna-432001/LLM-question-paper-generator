<?php

declare(strict_types=1);

namespace App\Domain\Pyq\DTOs;

/**
 * Everything read from one paper: the questions in printed order, and what
 * the parser noticed that a person should be told — a paper in several parts,
 * an answer key that could only be matched to some of them.
 *
 * Notes are codes with parameters rather than sentences, so whoever shows
 * them can say them in the reader's language.
 */
final class ParsedPaper
{
    /**
     * @param  list<ParsedPyq>  $questions
     * @param  list<array{code: string, params: array<string, int|string>}>  $notes
     */
    public function __construct(
        public readonly array $questions,
        public readonly array $notes = [],
    ) {}

    /** How many parts the paper numbers separately; 0 when nothing was read. */
    public function sections(): int
    {
        return $this->questions === []
            ? 0
            : max(array_map(static fn (ParsedPyq $q): int => $q->section, $this->questions));
    }
}
