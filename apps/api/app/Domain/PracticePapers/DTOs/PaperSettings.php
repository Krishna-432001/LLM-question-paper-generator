<?php

declare(strict_types=1);

namespace App\Domain\PracticePapers\DTOs;

/**
 * Everything about a practice paper that is decided at upload time.
 *
 * Carried as an array through the OCR job's input, so a scanned paper becomes
 * the same test it would have been had its text been readable straight away.
 */
final class PaperSettings
{
    public function __construct(
        public readonly string $organizationId,
        public readonly string $userId,
        public readonly string $examId,
        public readonly string $title,
        /** Null means one minute per question read. */
        public readonly ?int $durationMinutes,
        /** The language the student would rather read, as a code ('en', 'ta'). */
        public readonly ?string $language,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'user_id' => $this->userId,
            'exam_id' => $this->examId,
            'title' => $this->title,
            'duration_minutes' => $this->durationMinutes,
            'language' => $this->language,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            organizationId: (string) $data['organization_id'],
            userId: (string) $data['user_id'],
            examId: (string) $data['exam_id'],
            title: (string) $data['title'],
            durationMinutes: isset($data['duration_minutes']) ? (int) $data['duration_minutes'] : null,
            language: isset($data['language']) ? (string) $data['language'] : null,
        );
    }
}
