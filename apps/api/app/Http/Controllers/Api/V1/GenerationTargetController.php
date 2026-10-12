<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Questions\DTOs\GenerationPlan;
use App\Domain\Questions\TargetPlanner;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\QuestionGenerationTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * How many approved questions each topic is supposed to hold, and how far
 * short it currently falls.
 *
 * This is the content backlog. Without it an operator guesses which topic is
 * thin and generates against a hunch; with it the shortfall is a number.
 */
final class GenerationTargetController extends Controller
{
    public function __construct(private readonly TargetPlanner $planner) {}

    public function index(Request $request): JsonResponse
    {
        $targets = QuestionGenerationTarget::query()
            ->with('canonicalTopic:id,name,slug')
            ->when($request->filled('exam_id'), fn ($q) => $q->where('exam_id', $request->string('exam_id')))
            ->orderByDesc('priority')
            ->get();

        return response()->json([
            'data' => $targets->map(fn (QuestionGenerationTarget $t): array => [
                'id' => $t->getKey(),
                'exam_id' => $t->exam_id,
                'canonical_topic_id' => $t->canonical_topic_id,
                'topic' => $t->canonicalTopic?->name,
                'language_id' => $t->language_id,
                'difficulty' => $t->difficulty,
                'target_count' => $t->target_count,
                'priority' => $t->priority,
                'is_active' => $t->is_active,
            ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'exam_id' => ['required', 'uuid', 'exists:exams,id'],
            'language_id' => ['required', 'uuid', 'exists:languages,id'],
            'canonical_topic_id' => ['sometimes', 'nullable', 'uuid', 'exists:canonical_topics,id'],
            'difficulty' => ['sometimes', 'nullable', 'in:easy,medium,hard'],
            'target_count' => ['required', 'integer', 'between:1,5000'],
            'priority' => ['sometimes', 'integer', 'between:1,10'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Upsert on the cell: two rows for the same topic would each report a
        // deficit against the same questions and double every order placed.
        $target = QuestionGenerationTarget::query()->updateOrCreate(
            [
                'exam_id' => $validated['exam_id'],
                'canonical_topic_id' => $validated['canonical_topic_id'] ?? null,
                'language_id' => $validated['language_id'],
                'difficulty' => $validated['difficulty'] ?? null,
            ],
            [
                'organization_id' => app(TenantContext::class)->organizationId,
                'target_count' => $validated['target_count'],
                'priority' => $validated['priority'] ?? 5,
                'is_active' => $validated['is_active'] ?? true,
            ],
        );

        // A target decides how much gets generated, and therefore how much is
        // spent. Changing one quietly is exactly the kind of thing that has to
        // be attributable.
        app(AuditRecorder::class)->record('generation_target.set', $target, $validated);

        return response()->json(['data' => ['id' => $target->getKey()]], 201);
    }

    public function destroy(string $targetId): JsonResponse
    {
        $target = QuestionGenerationTarget::query()->findOrFail($targetId);

        // Recorded before the delete: afterwards there is nothing left to
        // describe what was removed.
        app(AuditRecorder::class)->record('generation_target.deleted', $target, [
            'exam_id' => $target->exam_id,
            'canonical_topic_id' => $target->canonical_topic_id,
            'target_count' => $target->target_count,
        ]);

        $target->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * The backlog: every configured target with its current shortfall,
     * largest first.
     */
    public function backlog(Request $request, string $examId): JsonResponse
    {
        $plans = $this->planner->planExam(
            $examId,
            countPending: $request->boolean('count_pending', true),
        );

        return response()->json([
            'data' => $plans->map(fn (GenerationPlan $p): array => $p->toArray())->all(),
            'meta' => [
                'targets' => $plans->count(),
                'total_deficit' => $plans->sum(fn (GenerationPlan $p): int => $p->deficit),
                'satisfied' => $plans->filter(fn (GenerationPlan $p): bool => $p->isSatisfied())->count(),
                // Stated because it changes the numbers: questions awaiting
                // review are treated as already holding their slot unless the
                // caller asks otherwise.
                'counts_pending_as_held' => $request->boolean('count_pending', true),
            ],
        ]);
    }
}
