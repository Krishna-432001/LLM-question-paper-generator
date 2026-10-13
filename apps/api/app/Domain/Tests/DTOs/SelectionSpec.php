<?php

declare(strict_types=1);

namespace App\Domain\Tests\DTOs;

final class SelectionSpec
{
    /**
     * @param  array<string, float>  $difficultyMix
     * @param  list<string>|null  $canonicalTopicIds
     * @param  list<string>  $excludeQuestionIds
     * @param  array<string, float>  $masteryByTopic
     * @param  array<string, float>  $predictionByTopic
     * @param  array<string, float>  $syllabusWeightByTopic
     */
    public function __construct(
        public readonly string $testType,
        public readonly string $languageId,
        public readonly int $questionCount,
        public readonly ?string $examId = null,
        public readonly ?string $userId = null,
        /** Restrict the paper to questions drawn from one source book. */
        public readonly ?string $sourceId = null,
        public readonly array $difficultyMix = [],
        public readonly ?array $canonicalTopicIds = null,
        public readonly array $excludeQuestionIds = [],
        public readonly array $masteryByTopic = [],
        public readonly array $predictionByTopic = [],
        public readonly array $syllabusWeightByTopic = [],
        /**
         * Set for a bilingual paper: every question must also be readable in
         * this language, not only in $languageId.
         */
        public readonly ?string $secondaryLanguageId = null,
    ) {}
}
