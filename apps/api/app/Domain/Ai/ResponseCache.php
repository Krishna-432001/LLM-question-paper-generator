<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\DTOs\LlmResponse;
use Illuminate\Support\Facades\DB;

/**
 * Cost control, layer 1: don't call the model twice for the same input.
 *
 * Keyed on the full request fingerprint (model + prompts + schema + params),
 * so a retried job, a duplicate upload or two tenants asking for the same
 * translation all resolve to one paid call.
 */
final class ResponseCache
{
    public function get(LlmRequest $request): ?LlmResponse
    {
        if (! config('ai.cache_enabled')) {
            return null;
        }

        $row = DB::table('ai_response_cache')
            ->where('request_hash', $request->hash())
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();

        if ($row === null) {
            return null;
        }

        DB::table('ai_response_cache')
            ->where('request_hash', $request->hash())
            ->update(['hit_count' => DB::raw('hit_count + 1')]);

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $row->response, true, 64, JSON_THROW_ON_ERROR);

        return new LlmResponse(
            data: $data,
            modelKey: $row->model_key,
            inputTokens: 0,
            outputTokens: 0,
            cacheHit: true,
        );
    }

    public function put(LlmRequest $request, LlmResponse $response): void
    {
        if (! config('ai.cache_enabled')) {
            return;
        }

        DB::table('ai_response_cache')->upsert([[
            'request_hash' => $request->hash(),
            'model_key' => $response->modelKey,
            'operation' => $request->operation,
            'response' => json_encode($response->data, JSON_THROW_ON_ERROR),
            'hit_count' => 0,
            'created_at' => now(),
            'expires_at' => now()->addDays((int) config('ai.cache_ttl_days', 90)),
        ]], ['request_hash'], ['response', 'model_key', 'expires_at']);
    }
}
