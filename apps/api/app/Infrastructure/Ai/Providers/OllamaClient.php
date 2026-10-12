<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Providers;

use App\Domain\Ai\Contracts\LlmClient;
use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\DTOs\LlmResponse;
use App\Domain\Ai\Exceptions\SchemaValidationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * A local model served by Ollama (https://ollama.com), over its HTTP API.
 *
 * Everything stays on this machine: no API key, no per-token cost, no data
 * leaving the network. Ollama's structured outputs constrain the reply to the
 * prompt version's JSON Schema, so the gateway's schema validation and every
 * downstream check work exactly as with a hosted provider.
 *
 * Local models are slower and weaker than hosted frontier models. The
 * validation gauntlet (answer re-solve, grounding, dedup) is what keeps their
 * mistakes out of the bank, so it is not relaxed for this driver.
 */
final class OllamaClient implements LlmClient
{
    /**
     * @param  array<string, string>  $modelMap  registry key => Ollama model tag
     */
    public function __construct(
        private readonly Http $http,
        private readonly string $baseUrl,
        private readonly string $defaultModel,
        private readonly array $modelMap = [],
        private readonly int $timeout = 600,
        private readonly int $contextWindow = 16384,
        private readonly ?string $keepAlive = '30m',
    ) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        $startedAt = microtime(true);
        $model = $this->modelKey($request);

        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->post(rtrim($this->baseUrl, '/').'/api/chat', $this->payload($request, $model));
        } catch (ConnectionException $e) {
            throw new RuntimeException(
                "Ollama is not reachable at {$this->baseUrl}. Start it with `ollama serve` "
                ."(and `ollama pull {$model}` once), or switch AI_DRIVER back to fake.",
                previous: $e,
            );
        } catch (Throwable $e) {
            throw new RuntimeException("Ollama call failed for {$request->operation}: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            $error = (string) ($response->json('error') ?? $response->body());

            // The commonest first-run failure, worth a precise message.
            if (str_contains($error, 'not found') || $response->status() === 404) {
                throw new RuntimeException("Ollama has no model \"{$model}\". Run: ollama pull {$model}");
            }

            throw new RuntimeException(
                "Ollama returned {$response->status()} for {$request->operation}: ".mb_substr($error, 0, 400)
            );
        }

        $text = (string) ($response->json('message.content') ?? '');

        if (trim($text) === '') {
            throw new SchemaValidationException("Model returned no text for {$request->operation}.", []);
        }

        return new LlmResponse(
            data: $this->decode($text, $request),
            modelKey: (string) ($response->json('model') ?? $model),
            inputTokens: (int) ($response->json('prompt_eval_count') ?? 0),
            outputTokens: (int) ($response->json('eval_count') ?? 0),
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
            finishReason: (string) ($response->json('done_reason') ?? 'stop'),
            rawText: $text,
        );
    }

    /**
     * Prompt versions name models from the platform registry (hosted model
     * names). Each maps to a local tag; anything unmapped runs on the default.
     */
    public function modelKey(LlmRequest $request): string
    {
        return $this->modelMap[$request->modelKey] ?? $this->defaultModel;
    }

    /** @return array<string, mixed> */
    private function payload(LlmRequest $request, string $model): array
    {
        $options = [
            'num_ctx' => $this->contextWindow,
            // Low temperature by default: these are exam questions and
            // classifications, where consistency beats variety.
            'temperature' => (float) ($request->params['temperature'] ?? 0.2),
        ];

        if (isset($request->params['max_tokens'])) {
            $options['num_predict'] = (int) $request->params['max_tokens'];
        }

        $payload = [
            'model' => $model,
            'stream' => false,
            'messages' => array_values(array_filter([
                $request->systemPrompt !== '' ? ['role' => 'system', 'content' => $request->systemPrompt] : null,
                ['role' => 'user', 'content' => $request->userPrompt],
            ])),
            // Structured outputs: the reply is constrained to this schema.
            'format' => $request->outputSchema,
            'options' => $options,
        ];

        if ($this->keepAlive !== null) {
            // Keeps the model loaded between the many calls of one job,
            // instead of reloading gigabytes of weights for each batch.
            $payload['keep_alive'] = $this->keepAlive;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function decode(string $text, LlmRequest $request): array
    {
        try {
            $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SchemaValidationException("Model returned invalid JSON for {$request->operation}: {$e->getMessage()}", []);
        }

        if (! is_array($decoded)) {
            throw new SchemaValidationException("Model returned a non-object for {$request->operation}.", []);
        }

        return $decoded;
    }
}
