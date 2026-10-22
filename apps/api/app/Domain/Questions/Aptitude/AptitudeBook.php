<?php

declare(strict_types=1);

namespace App\Domain\Questions\Aptitude;

use Closure;
use Random\Randomizer;

/**
 * One textbook's set of number-varied aptitude methods. Every question cites
 * the page that teaches its method; the numbers change, the method and the
 * citation do not, and every answer is computed.
 */
abstract class AptitudeBook
{
    use BuildsAptitudeQuestions;

    /** The source's exact title, as questions:import expects it. */
    abstract public function source(): string;

    abstract public function breadcrumb(): string;

    /** The file the set is written to, in the tnpsc-g4 question folder. */
    abstract public function file(): string;

    /**
     * @return array<string, Closure(Randomizer): ?array<string, mixed>>
     */
    abstract protected function templates(): array;

    /**
     * @return list<array<string, mixed>> questions in the questions:import format
     */
    public function generate(int $perTemplate, int $seed): array
    {
        return $this->fromTemplates($this->templates(), $perTemplate, $seed);
    }

    public function methodCount(): int
    {
        return count($this->templates());
    }
}
