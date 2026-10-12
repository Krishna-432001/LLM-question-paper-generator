<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Providers;

use App\Domain\Ai\Contracts\LlmClient;
use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\DTOs\LlmResponse;
use App\Domain\Ai\Exceptions\SchemaValidationException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * OpenAI provider, spoken to over plain HTTP.
 *
 * No vendor SDK: the surface actually used here is one endpoint and a fixed
 * request shape, and a direct call keeps the dependency list — and the blast
 * radius of an SDK upgrade — at zero. Everything else the platform needs from
 * a model call (tracing, costing, budget, caching, schema validation) already
 * lives in AiGateway, which is the only caller of this class.
 */
final class OpenAiClient implements LlmClient
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /**
     * @param  array<string, string>  $modelMap  registry key => OpenAI model id
     */
    public function __construct(
        private readonly Http $http,
        private readonly string $apiKey,
        private readonly string $defaultModel,
        private readonly array $modelMap = [],
        private readonly int $timeout = 180,
    ) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        if ($this->apiKey === '') {
            throw new RuntimeException(
                'OPENAI_API_KEY is not set, so no question can be generated. '
                .'Set it in apps/api/.env, or switch AI_DRIVER back to fake.'
            );
        }

        $startedAt = microtime(true);
        $model = $this->modelKey($request);

        try {
            $response = $this->http
                ->withToken($this->apiKey)
                ->timeout($this->timeout)
                // Retry only the failures that are actually transient. A 400
                // from a malformed schema would never succeed on a retry and
                // would just triple the latency before failing.
                ->retry(2, 1000, fn (Throwable $e, $req): bool => $this->isTransient($e), throw: false)
                ->post(self::ENDPOINT, $this->payload($request, $model));
        } catch (Throwable $e) {
            throw new RuntimeException(
                "OpenAI call failed for {$request->operation}: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();

            throw new RuntimeException(
                "OpenAI returned {$response->status()} for {$request->operation}: "
                .mb_substr((string) $error, 0, 400)
            );
        }

        $choice = $response->json('choices.0') ?? [];

        // A safety refusal arrives as HTTP 200 with a `refusal` string in place
        // of content. Reading `content` blindly would hand an empty string to
        // the JSON decoder and report a schema error for a policy decision.
        if (! empty($choice['message']['refusal'])) {
            Log::warning('ai.refusal', [
                'operation' => $request->operation,
                'model' => $model,
                'refusal' => mb_substr((string) $choice['message']['refusal'], 0, 200),
            ]);

            throw new RuntimeException("Model declined the request for {$request->operation}.");
        }

        $text = (string) ($choice['message']['content'] ?? '');

        if (trim($text) === '') {
            throw new SchemaValidationException(
                "Model returned no text for {$request->operation}.", []
            );
        }

        $usage = $response->json('usage') ?? [];

        return new LlmResponse(
            data: $this->decode($text, $request),
            modelKey: (string) ($response->json('model') ?? $model),
            inputTokens: (int) ($usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
            // Cached prompt tokens are billed at a discount, so they are
            // reported separately or the cost ledger overstates every call.
            cachedInputTokens: (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0),
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
            finishReason: (string) ($choice['finish_reason'] ?? 'stop'),
            rawText: $text,
        );
    }

    /**
     * Prompt versions name a model in the platform's own registry, which is
     * provider-agnostic. Map it to an OpenAI id, and fall back to the
     * configured default rather than sending an Anthropic model name.
     */
    public function modelKey(LlmRequest $request): string
    {
        $key = $request->modelKey;

        if (isset($this->modelMap[$key])) {
            return $this->modelMap[$key];
        }

        return str_starts_with($key, 'gpt-') || str_starts_with($key, 'o')
            ? $key
            : $this->defaultModel;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(LlmRequest $request, string $model): array
    {
        $payload = [
            'model' => $model,
            'messages' => array_values(array_filter([
                $request->systemPrompt !== ''
                    ? ['role' => 'system', 'content' => $request->systemPrompt]
                    : null,
                ['role' => 'user', 'content' => $request->userPrompt],
            ])),
            // Structured outputs: the reply is constrained to the prompt
            // version's schema, which is what lets the gateway treat a
            // validation failure as exceptional rather than routine.
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => preg_replace('/[^a-zA-Z0-9_]/', '_', $request->operation) ?: 'response',
                    'strict' => true,
                    'schema' => $this->strictSchema($request->outputSchema),
                ],
            ],
        ];

        if (isset($request->params['max_tokens'])) {
            $payload['max_completion_tokens'] = (int) $request->params['max_tokens'];
        }

        // Reasoning models reject sampling parameters outright, so temperature
        // is sent only for the models that accept it.
        if (isset($request->params['temperature']) && ! $this->isReasoningModel($model)) {
            $payload['temperature'] = (float) $request->params['temperature'];
        }

        if (isset($request->params['effort']) && $this->isReasoningModel($model)) {
            $payload['reasoning_effort'] = (string) $request->params['effort'];
        }

        return $payload;
    }

    /**
     * Rewrites a schema to satisfy OpenAI strict mode.
     *
     * Strict mode demands that every property of every object appears in
     * `required` and that `additionalProperties` is false. Our schemas mark
     * genuinely optional fields — `subtopic`, say — by omitting them from
     * `required`, which strict mode rejects outright with a 400.
     *
     * An optional field becomes a required nullable one: the model must return
     * the key, and may return null. That is the same contract in practice, and
     * the downstream DTOs already treat null and absent identically.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function strictSchema(array $schema): array
    {
        $type = $schema['type'] ?? null;
        $type = is_array($type) ? ($type[0] ?? null) : $type;

        if ($type === 'object' && isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $name => $definition) {
                $schema['properties'][$name] = $this->strictSchema((array) $definition);
            }

            $schema['required'] = array_keys($schema['properties']);
            $schema['additionalProperties'] = false;
        }

        if ($type === 'array' && isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->strictSchema($schema['items']);
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function decode(string $text, LlmRequest $request): array
    {
        try {
            $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SchemaValidationException(
                "Model returned invalid JSON for {$request->operation}: {$e->getMessage()}",
                [],
            );
        }

        if (! is_array($decoded)) {
            throw new SchemaValidationException(
                "Model returned a non-object for {$request->operation}.", []
            );
        }

        return $decoded;
    }

    private function isReasoningModel(string $model): bool
    {
        return (bool) preg_match('/^(o\d|gpt-5)/i', $model);
    }

    private function isTransient(Throwable $e): bool
    {
        return $e instanceof \Illuminate\Http\Client\ConnectionException;
    }
}
