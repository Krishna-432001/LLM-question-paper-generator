<?php

declare(strict_types=1);

namespace App\Domain\Plans\DTOs;

use Carbon\CarbonImmutable;

final class ScheduledDay
{
    /** @param  list<array<string, mixed>>  $items */
    public function __construct(
        public readonly int $dayNumber,
        public readonly CarbonImmutable $date,
        public string $role,
        public int $targetMinutes,
        public array $items = [],
    ) {}

    public function totalMinutes(): int
    {
        return array_sum(array_map(
            static fn (array $i): int => (int) ($i['minutes'] ?? 0),
            $this->items,
        ));
    }
}
