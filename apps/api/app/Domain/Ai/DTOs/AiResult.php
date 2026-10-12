<?php

declare(strict_types=1);

namespace App\Domain\Ai\DTOs;

use App\Models\AiInvocation;

final class AiResult
{
    /** @param  array<string, mixed>  $data  schema-validated model output */
    public function __construct(
        public readonly array $data,
        public readonly AiInvocation $invocation,
        public readonly string $promptVersionId,
    ) {}

    public function costInr(): float
    {
        return (float) $this->invocation->cost_inr;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }
}
