<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Exceptions\SchemaValidationException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

/**
 * Raw model text is never trusted. Output must parse as JSON and satisfy the
 * prompt version's JSON Schema; anything else is rejected (with one repair
 * attempt) rather than stored.
 */
final class SchemaValidator
{
    private Validator $validator;

    public function __construct()
    {
        $this->validator = new Validator;
        $this->validator->setMaxErrors(10);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $schema
     *
     * @throws SchemaValidationException
     */
    public function assertValid(array $data, array $schema): void
    {
        $result = $this->validator->validate(
            Helper::toJSON($data),
            Helper::toJSON($schema),
        );

        if ($result->isValid()) {
            return;
        }

        $errors = (new ErrorFormatter)->formatOutput($result->error(), 'basic');

        throw new SchemaValidationException(
            'LLM output failed schema validation: '.json_encode($errors, JSON_THROW_ON_ERROR),
            $errors,
        );
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     *
     * @throws SchemaValidationException
     */
    public function decodeAndValidate(string $rawText, array $schema): array
    {
        $json = $this->extractJson($rawText);

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SchemaValidationException('LLM output was not valid JSON: '.$e->getMessage(), []);
        }

        $this->assertValid($data, $schema);

        return $data;
    }

    /**
     * Models occasionally wrap JSON in prose or a fenced block despite
     * instructions. Recovering the object is cheaper than a retry.
     */
    private function extractJson(string $text): string
    {
        $text = trim($text);

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $m) === 1) {
            return trim($m[1]);
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return $text;
    }
}
