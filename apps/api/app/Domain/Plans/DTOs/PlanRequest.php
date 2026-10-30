<?php

declare(strict_types=1);

namespace App\Domain\Plans\DTOs;

final class PlanRequest
{
    /**
     * @param  list<int>  $offDays  0 = Sunday
     * @param  array<string, float>  $masteryByTopic
     * @param  array<string, float>  $predictionByTopic
     * @param  array<string, mixed>  $testConfig
     */
    public function __construct(
        public readonly string $syllabusVersionId,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly float $hoursPerDay = 4.0,
        public readonly array $offDays = [],
        public readonly array $masteryByTopic = [],
        public readonly array $predictionByTopic = [],
        public readonly array $testConfig = [],
        // The last day of new material, when the student wants a longer
        // revision stretch than the default tail; null keeps the default.
        public readonly ?string $firstPassEnd = null,
    ) {}
}
