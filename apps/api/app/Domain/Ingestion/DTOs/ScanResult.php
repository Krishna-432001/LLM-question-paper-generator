<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

final readonly class ScanResult
{
    public function __construct(
        public bool $clean,
        public string $engine,
        /** The matched signature when not clean. */
        public ?string $signature = null,
        /** False when no scanner is configured: clean by assumption, not by check. */
        public bool $scanned = true,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'scanned' => $this->scanned,
            'engine' => $this->engine,
            'result' => $this->clean ? ($this->scanned ? 'clean' : 'not_scanned') : 'infected',
            'signature' => $this->signature,
        ];
    }
}
