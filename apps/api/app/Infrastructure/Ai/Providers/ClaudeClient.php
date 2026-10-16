<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use App\Domain\Ai\Contracts\LlmClient;
use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\DTOs\LlmResponse;
use App\Domain\Ai\Exceptions\SchemaValidationException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Anthropic provider adapter.
 *
 * The only class in the codebase allowed to import the provider SDK. Everything
 * else goes through AiGateway, so caching, budget checks, schema validation and
 * cost accounting cannot be skipped at a call site.
 */
final class ClaudeClient implements LlmClient
{
    /**
     * Models that removed sampling parameters. Sending temperature/top_p/top_k
     * to these returns a 400, so the gateway's prompt params are filtered
     * rather than forwarded blindly.
     */
    private const NO_SAMPLING_PARAMS = [
        'claude-opus-5', 'claude-fable-5', 'claude-mythos-5',
        'claude-sonnet-5', 'claude-opus-4-8', 'claude-opus-4-7',
        'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5',
        'claude-fable-5-1', 'claude-mythos-5-1',
    ];

    /** Effort levels, least thinking first. */
    private const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public function __construct(private readonly Client $client) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        $startedAt = microtime(true);

        try {
            $message = $this->client->messages->create(...$this->requestFor($request));
        } catch (APIException $e) {
            throw new RuntimeException(
                "Anthropic API call failed for {$request->operation}: {$e->getMessage()}",
                previous: $e,
            );
        }

        // A safety classifier can decline the request: HTTP 200, empty or
        // partial content, stop_reason 'refusal'. Never index content blindly.
        if ($message->stopReason === 'refusal') {
            Log::warning('ai.refusal', [
                'operation' => $request->operation,
                'model' => $message->model,
                'category' => $message->stopDetails?->category ?? null,
            ]);

            throw new RuntimeException(
                "Model declined the request for {$request->operation}."
            );
        }

        $text = $this->extractText($message->content);

        if ($text === '') {
            throw new SchemaValidationException(
                "Model returned no text for {$request->operation}.", []
            );
        }

        return new LlmResponse(
            data: $this->decode($text, $request),
            modelKey: $message->model,
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
            cachedInputTokens: $message->usage->cacheReadInputTokens ?? 0,
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
            finishReason: $message->stopReason,
            rawText: $text,
        );
    }

    /** The Claude model this request really calls: the tier's mapped model, else the key itself. */
    public function modelKey(LlmRequest $request): string
    {
        $map = (array) config('ai.providers.claude.model_map', []);

        return (string) ($map[$request->modelKey] ?? $request->modelKey);
    }

    /**
     * The Messages API arguments for one request, named as the SDK takes them.
     *
     * @return array<string, mixed>
     */
    public function requestFor(LlmRequest $request): array
    {
        $model = $this->modelKey($request);

        return [
            'maxTokens' => (int) ($request->params['max_tokens'] ?? 16000),
            'messages' => [['role' => 'user', 'content' => $request->userPrompt]],
            'model' => $model,
            'outputConfig' => $this->outputConfig($request),
            'system' => $this->system($request),
            'temperature' => $this->temperature($request, $model),
        ];
    }

    /**
     * Structured outputs: the response is constrained to the prompt version's
     * JSON Schema, which is why the gateway can treat validation failures as
     * exceptional rather than routine.
     *
     * @return array<string, mixed>
     */
    private function outputConfig(LlmRequest $request): array
    {
        $config = [
            'format' => ['type' => 'json_schema', 'schema' => $request->outputSchema],
        ];

        if (isset($request->params['effort'])) {
            $config['effort'] = $this->cappedEffort((string) $request->params['effort']);
        }

        return $config;
    }

    /** A prompt's effort, lowered to the configured ceiling when it asks for more. */
    private function cappedEffort(string $effort): string
    {
        $cap = config('ai.providers.claude.max_effort');
        $asked = array_search($effort, self::EFFORTS, true);
        $ceiling = is_string($cap) ? array_search($cap, self::EFFORTS, true) : false;

        return $asked !== false && $ceiling !== false && $asked > $ceiling ? $cap : $effort;
    }

    /**
     * Prompt caching on the stable system prefix. The system prompt is
     * identical across every call for a given prompt version, so this is the
     * highest-value cache breakpoint in the platform.
     *
     * @return list<array<string, mixed>>
     */
    private function system(LlmRequest $request): array
    {
        return [[
            'type' => 'text',
            'text' => $request->systemPrompt,
            'cache_control' => ['type' => 'ephemeral'],
        ]];
    }

    /**
     * Null on models that removed sampling parameters — sending a value there
     * is a 400, and a prompt version carried over from an older model will
     * still have temperature in its params.
     */
    private function temperature(LlmRequest $request, string $model): ?float
    {
        if (in_array($model, self::NO_SAMPLING_PARAMS, true)) {
            return null;
        }

        return isset($request->params['temperature'])
            ? (float) $request->params['temperature']
            : null;
    }

    /** @param  list<mixed>  $content */
    private function extractText(array $content): string
    {
        $text = '';

        foreach ($content as $block) {
            if (($block->type ?? null) === 'text') {
                $text .= $block->text;
            }
        }

        return trim($text);
    }

    /** @return array<string, mixed> */
    private function decode(string $text, LlmRequest $request): array
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($text, true, 64, JSON_THROW_ON_ERROR);

            return $data;
        } catch (\JsonException $e) {
            throw new SchemaValidationException(
                "Model output for {$request->operation} was not valid JSON: {$e->getMessage()}",
                [],
            );
        }
    }
}
