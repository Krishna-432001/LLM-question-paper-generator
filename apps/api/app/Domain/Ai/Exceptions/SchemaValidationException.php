<?php

declare(strict_types=1);

namespace App\Domain\Ai\Exceptions;

use RuntimeException;

class SchemaValidationException extends RuntimeException
{
    /** @param  array<string, mixed>  $errors */
    public function __construct(string $message, public readonly array $errors)
    {
        parent::__construct($message);
    }
}
