<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Contracts;

interface EmbeddingProvider
{
    /**
     * @param  list<string>  $texts
     * @return list<list<float>>  one vector per input, same order
     */
    public function embed(array $texts): array;

    public function modelKey(): string;

    public function dimensions(): int;
}
