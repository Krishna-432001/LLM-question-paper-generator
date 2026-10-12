<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Every model call the platform has made, and what it cost.
 *
 * The ledger has been written since the gateway existed and has never been
 * readable. That matters more here than in most systems: this is the only
 * place that answers "why did last month cost what it did", and the answer is
 * usually one operation quietly running on the wrong model tier.
 *
 * The prompt snapshot is deliberately not returned in the list. It is large,
 * it contains whole textbook passages, and paging through it would make the
 * screen useless — it is available per row instead.
 */
final class AiHistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $invocations = DB::table('ai_invocations as i')
            ->leftJoin('ai_models as m', 'm.id', '=', 'i.ai_model_id')
            ->when($request->filled('operation'), fn ($q) => $q->where('i.operation', $request->string('operation')))
            ->when($request->boolean('errors_only'), fn ($q) => $q->whereNotNull('i.error'))
            ->when($request->filled('since'), fn ($q) => $q->where('i.created_at', '>=', $request->date('since')))
            ->orderByDesc('i.created_at')
            ->limit(min(200, $request->integer('limit', 50)))
            ->get([
                'i.id', 'i.operation', 'i.input_tokens', 'i.output_tokens',
                'i.cached_input_tokens', 'i.cost_inr', 'i.latency_ms',
                'i.cache_hit', 'i.degraded', 'i.finish_reason', 'i.error',
                'i.created_at', 'm.model_key', 'm.provider', 'm.tier',
            ]);

        return response()->json([
            'data' => $invocations->map(fn (object $row): array => [
                'id' => $row->id,
                'operation' => $row->operation,
                'model' => $row->model_key,
                'provider' => $row->provider,
                'tier' => $row->tier,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'cached_input_tokens' => (int) $row->cached_input_tokens,
                'cost_inr' => round((float) $row->cost_inr, 4),
                'latency_ms' => (int) $row->latency_ms,
                'cache_hit' => (bool) $row->cache_hit,
                // A degraded call fell back to a cheaper tier because the
                // primary was unavailable, which is a quality event, not just
                // a cost one.
                'degraded' => (bool) $row->degraded,
                'error' => $row->error,
                'created_at' => $row->created_at,
            ])->all(),
            'meta' => $this->summary(),
        ]);
    }

    /**
     * Where the money actually goes, by operation.
     *
     * Grouped rather than listed because the useful question is never "what
     * did call #4,182 cost" but "which operation is eating the budget".
     *
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        $byOperation = DB::table('ai_invocations')
            ->selectRaw(<<<'SQL'
                operation,
                count(*) AS calls,
                coalesce(sum(cost_inr), 0) AS cost,
                coalesce(sum(input_tokens), 0) AS input_tokens,
                coalesce(sum(output_tokens), 0) AS output_tokens,
                coalesce(round(avg(latency_ms)), 0) AS avg_latency_ms,
                count(*) FILTER (WHERE cache_hit) AS cache_hits,
                count(*) FILTER (WHERE error IS NOT NULL) AS errors
            SQL)
            ->groupBy('operation')
            ->orderByDesc('cost')
            ->get();

        $totals = DB::table('ai_invocations')
            ->selectRaw(<<<'SQL'
                count(*) AS calls,
                coalesce(sum(cost_inr), 0) AS cost,
                count(*) FILTER (WHERE cache_hit) AS cache_hits,
                count(*) FILTER (WHERE error IS NOT NULL) AS errors
            SQL)
            ->first();

        $calls = (int) ($totals->calls ?? 0);

        return [
            'total_calls' => $calls,
            'total_cost_inr' => round((float) ($totals->cost ?? 0), 2),
            // The cache is the cheapest optimisation available, so how often
            // it actually fires is worth stating plainly.
            'cache_hit_rate' => $calls > 0
                ? round(((int) $totals->cache_hits) / $calls, 3)
                : 0.0,
            'errors' => (int) ($totals->errors ?? 0),
            'by_operation' => $byOperation->map(fn (object $row): array => [
                'operation' => $row->operation,
                'calls' => (int) $row->calls,
                'cost_inr' => round((float) $row->cost, 2),
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'avg_latency_ms' => (int) $row->avg_latency_ms,
                'cache_hits' => (int) $row->cache_hits,
                'errors' => (int) $row->errors,
            ])->all(),
        ];
    }

    /**
     * One call in full, including the prompt that produced it.
     *
     * This is the only way to answer "why did the model say that", and it is
     * why the snapshot is stored at all.
     */
    public function show(string $invocationId): JsonResponse
    {
        $row = DB::table('ai_invocations as i')
            ->leftJoin('ai_models as m', 'm.id', '=', 'i.ai_model_id')
            ->where('i.id', $invocationId)
            ->first(['i.*', 'm.model_key', 'm.provider']);

        abort_if($row === null, 404, 'No such invocation.');

        return response()->json([
            'data' => [
                'id' => $row->id,
                'operation' => $row->operation,
                'model' => $row->model_key,
                'provider' => $row->provider,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'cost_inr' => round((float) $row->cost_inr, 4),
                'latency_ms' => (int) $row->latency_ms,
                'error' => $row->error,
                'request' => json_decode((string) $row->request_snapshot, true),
                'created_at' => $row->created_at,
            ],
        ]);
    }
}
