<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\Language;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CatalogueController extends Controller
{
    public function languages(): JsonResponse
    {
        $languages = Language::query()->active()->orderBy('sort_order')->get();

        return response()->json([
            'data' => $languages->map(fn (Language $l) => [
                'id' => $l->getKey(),
                'code' => $l->code,
                'name' => $l->name,
                'native_name' => $l->native_name,
                'script' => $l->script,
            ])->all(),
        ]);
    }

    /**
     * Exams the caller can see: platform exams plus their own organization's.
     * The tenant scope on the model is what enforces that — this method never
     * filters by organization itself.
     */
    public function exams(Request $request): JsonResponse
    {
        // Managers need to see switched-off exams to switch them back on.
        $includeInactive = $request->boolean('include_inactive') && $request->user()?->can('exams.manage');

        $exams = Exam::query()
            ->when(! $includeInactive, fn ($q) => $q->active())
            ->when($request->filled('state'), fn ($q) => $q->where('state', $request->string('state')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $exams->map(fn (Exam $e) => [
                'id' => $e->getKey(),
                'code' => $e->code,
                'name' => $e->name,
                'conducting_body' => $e->conducting_body,
                'state' => $e->state,
                'category' => $e->category,
                'level' => $e->level,
                'is_active' => $e->is_active,
                'is_platform' => $e->isPlatformOwned(),
            ])->all(),
        ]);
    }
}
