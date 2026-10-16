<?php

declare(strict_types=1);

namespace App\Domain\Tests\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The bank cannot yet fill the exam's pattern, part by part.
 *
 * Carries the count for every part so the student sees exactly what is
 * missing ("Part A: 0 of 100") and can choose a shorter mock built from what
 * exists — never a short paper handed over as if it were the real pattern.
 */
final class ExamPatternShortfall extends UnprocessableEntityHttpException
{
    /**
     * @param  list<array{name: string, needed: int, available: int, units?: list<array{name: string, needed: int, available: int}>}>  $sections
     */
    public function __construct(public readonly array $sections)
    {
        $parts = implode('; ', array_map(
            static fn (array $s): string => "{$s['name']}: {$s['available']} of {$s['needed']}",
            $sections,
        ));

        parent::__construct($this->availableTotal() === 0
            ? "The question bank has no approved questions for any part of this exam's pattern yet ({$parts})."
            : "The question bank cannot fill this exam's pattern yet ({$parts}). "
                .'Build a shorter mock from the questions that exist, or add more questions first.');
    }

    /** How many questions a shorter mock would have. */
    public function availableTotal(): int
    {
        return array_sum(array_map(
            static fn (array $s): int => min($s['available'], $s['needed']),
            $this->sections,
        ));
    }
}
