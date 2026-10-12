<?php

declare(strict_types=1);

namespace App\Domain\Questions\DTOs;

/**
 * What a generation request actually amounts to once the bank is consulted.
 *
 * Every number is carried, not just the deficit, because "we ordered 7" is
 * only trustworthy alongside "because 43 are approved and 0 are in review".
 */
final class GenerationPlan
{
    public function __construct(
        public readonly int $requested,
        public readonly int $approved,
        public readonly int $pending,
        public readonly int $rejected,
        public readonly int $deficit,
        public readonly ?string $canonicalTopicId = null,
        public readonly ?string $difficulty = null,
    ) {}

    public function isSatisfied(): bool
    {
        return $this->deficit === 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'requested' => $this->requested,
            'approved' => $this->approved,
            'pending_review' => $this->pending,
            'rejected' => $this->rejected,
            'deficit' => $this->deficit,
            'canonical_topic_id' => $this->canonicalTopicId,
            'difficulty' => $this->difficulty,
            'satisfied' => $this->isSatisfied(),
        ];
    }
}
