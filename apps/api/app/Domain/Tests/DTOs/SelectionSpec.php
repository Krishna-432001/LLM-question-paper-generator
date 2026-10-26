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
     * @param  array<string, float>  $topicShareByTopic
     * @param  array<string, string>  $languageByTopic
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
        /**
         * Each topic's share of the exam paper (its unit's weight split over
         * the unit's topics). What the paper's questions are divided by; empty
         * means every topic in scope gets an equal share.
         */
        public readonly array $topicShareByTopic = [],
        /**
         * Topics always set in one language whatever the paper's (TNPSC
         * Group IV Part A is Tamil): drawn and printed in that language alone.
         */
        public readonly array $languageByTopic = [],
        /**
         * Orders questions that are otherwise equal (served as often) and
         * picks topics when there are more topics than questions. Recorded on
         * the test so the paper can be rebuilt; derived from the spec if null.
         */
        public readonly ?int $seed = null,
    ) {}
}
