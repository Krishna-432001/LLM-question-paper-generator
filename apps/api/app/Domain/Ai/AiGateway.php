<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\LlmClient;
use App\Domain\Ai\DTOs\AiResult;
use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\DTOs\LlmResponse;
use App\Domain\Ai\Exceptions\SchemaValidationException;
use App\Models\AiInvocation;
use App\Models\AiJob;
use App\Models\AiModel;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single choke point for every model call in the platform.
 *
 * Nothing else may talk to a provider. Everything that must happen on every
 * call — prompt resolution, caching, budget checks, schema validation, cost
 * accounting, tracing — happens here exactly once, so it cannot be forgotten
 * at a call site.
 */
final class AiGateway
{
    public function __construct(
        private readonly LlmClient $client,
        private readonly PromptRegistry $prompts,
        private readonly SchemaValidator $schema,
        private readonly ResponseCache $cache,
        private readonly BudgetGuard $budget,
    ) {}

    /**
     * @param  array<string, mixed>  $variables  rendered into the prompt template
     * @param  array<string, mixed>  $context    job/tenant refs for tracing and cost attribution
     */
    public function structured(
        string $promptName,
        array $variables,
        array $context = [],
        ?string $pinnedVersionId = null,
    ): AiResult {
        $version = $this->prompts->resolve($promptName, $pinnedVersionId);
        $version->loadMissing('aiModel');

        $request = new LlmRequest(
            operation: $promptName,
            modelKey: $version->aiModel->model_key,
            systemPrompt: $this->prompts->render($version->system_prompt, $variables),
            userPrompt: $this->prompts->render($version->user_prompt, $variables),
            outputSchema: $version->output_schema,
            params: $version->params,
        );

        $this->budget->assertGlobalBreakerClosed();

        $cached = $this->cache->get($request);

        if ($cached !== null) {
            return $this->record($request, $cached->asCacheHit(), $version->getKey(), $context);
        }

        $response = $this->callWithValidation($request);

        $this->cache->put($request, $response);

        return $this->record($request, $response, $version->getKey(), $context);
    }

    /**
     * One repair attempt on schema failure: cheaper and more reliable than a
     * blind retry, because the validator's own error is fed back to the model.
     */
    private function callWithValidation(LlmRequest $request): LlmResponse
    {
        try {
            $response = $this->client->complete($request);
            $this->schema->assertValid($response->data, $request->outputSchema);

            return $response;
        } catch (SchemaValidationException $e) {
            Log::warning('ai.schema_repair', [
                'operation' => $request->operation,
                'errors' => $e->errors,
            ]);

            $repaired = $this->client->complete($request->withRepairHint($e->getMessage()));
            $this->schema->assertValid($repaired->data, $request->outputSchema);

            return $repaired;
        }
    }

    /** @param  array<string, mixed>  $context */
    private function record(
        LlmRequest $request,
        LlmResponse $response,
        string $promptVersionId,
        array $context,
    ): AiResult {
        $model = AiModel::byKey($response->modelKey);

        $costInr = $model?->costInr(
            $response->inputTokens,
            $response->outputTokens,
            $response->cachedInputTokens,
        ) ?? 0.0;

        $invocation = AiInvocation::query()->create([
            'ai_job_id' => $context['ai_job_id'] ?? null,
            'organization_id' => $context['organization_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'prompt_version_id' => $promptVersionId,
            'ai_model_id' => $model?->getKey(),
            'operation' => $request->operation,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cached_input_tokens' => $response->cachedInputTokens,
            'cost_inr' => $costInr,
            'latency_ms' => $response->latencyMs,
            'cache_hit' => $response->cacheHit,
            'degraded' => $response->degraded,
            'finish_reason' => $response->finishReason,
            'request_hash' => $request->hash(),
            'response_hash' => $response->responseHash(),
            'request_snapshot' => config('ai.store_snapshots')
                ? ['system' => $request->systemPrompt, 'user' => $request->userPrompt]
                : null,
        ]);

        $this->rollUpToJob($context, $response, $costInr);

        return new AiResult($response->data, $invocation, $promptVersionId);
    }

    /** @param  array<string, mixed>  $context */
    private function rollUpToJob(array $context, LlmResponse $response, float $costInr): void
    {
        if (! isset($context['ai_job_id'])) {
            return;
        }

        try {
            AiJob::query()->find($context['ai_job_id'])?->recordUsage(
                $response->inputTokens,
                $response->outputTokens,
                $costInr,
            );
        } catch (Throwable $e) {
            // Accounting must never fail the work it is accounting for.
            Log::error('ai.usage_rollup_failed', ['error' => $e->getMessage()]);
        }
    }
}
