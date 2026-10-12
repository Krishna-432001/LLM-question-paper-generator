<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The state of the whole system on one screen.
 *
 * Deliberately built from a handful of grouped counts rather than a count per
 * card: a dashboard that issues twenty queries becomes the slowest page in the
 * application and then gets loaded on every navigation.
 *
 * The numbers chosen are the ones an operator acts on. "Total questions" is
 * vanity; "questions waiting for review" is a decision about what to do next,
 * and "pages that failed to read" is a book that will silently produce nothing.
 */
final class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'books' => $this->books(),
                'questions' => $this->questions(),
                'pyqs' => $this->pyqs(),
                'documents' => $this->documents(),
                'ai' => $this->ai(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function books(): array
    {
        $byStatus = DB::table('sources')
            ->selectRaw('status, count(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $pages = DB::table('book_pages')
            ->selectRaw('count(*) AS total, count(*) FILTER (WHERE needs_review) AS needs_review')
            ->first();

        return [
            'total' => (int) $byStatus->sum(),
            'ready' => (int) $byStatus->get('ready', 0),
            // Anything not ready is a book that cannot be generated from, which
            // is the only reason to show this at all.
            'processing' => (int) $byStatus->except(['ready', 'failed'])->sum(),
            'failed' => (int) $byStatus->get('failed', 0),
            'pages' => (int) ($pages->total ?? 0),
            'pages_needing_review' => (int) ($pages->needs_review ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function questions(): array
    {
        $byStatus = DB::table('questions')
            ->selectRaw('status, count(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $rejections = DB::table('question_rejections')
            ->selectRaw('code, count(*) AS total')
            ->groupBy('code')
            ->orderByDesc('total')
            ->limit(5)
            ->pluck('total', 'code');

        return [
            'total' => (int) $byStatus->sum(),
            'approved' => (int) $byStatus->get('APPROVED', 0),
            'pending_review' => (int) $byStatus->only(['AI_VALIDATED', 'PENDING_REVIEW'])->sum(),
            'rejected' => (int) $byStatus->get('REJECTED', 0),
            'revision_required' => (int) $byStatus->get('REVISION_REQUIRED', 0),
            // Why questions are being thrown away is the single most useful
            // signal about generation quality, and it is otherwise buried.
            'top_rejection_reasons' => $rejections,
        ];
    }

    /** @return array<string, mixed> */
    private function pyqs(): array
    {
        $papers = DB::table('previous_year_papers')
            ->selectRaw('count(*) AS total, min(year) AS earliest, max(year) AS latest')
            ->first();

        $questions = DB::table('previous_year_questions')
            ->selectRaw('count(*) AS total, count(*) FILTER (WHERE canonical_topic_id IS NULL) AS unmapped')
            ->first();

        return [
            'papers' => (int) ($papers->total ?? 0),
            'years' => $papers->earliest === null
                ? null
                : "{$papers->earliest}–{$papers->latest}",
            'questions' => (int) ($questions->total ?? 0),
            // Unmapped PYQs contribute nothing to the analysis, so this is the
            // gap between "entered" and "useful".
            'unmapped' => (int) ($questions->unmapped ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function documents(): array
    {
        $byFormat = DB::table('generated_documents')
            ->where('status', 'ready')
            ->selectRaw('format, count(*) AS total')
            ->groupBy('format')
            ->pluck('total', 'format');

        return [
            'total' => (int) $byFormat->sum(),
            'by_format' => $byFormat,
            'downloads' => (int) DB::table('generated_documents')->sum('download_count'),
        ];
    }

    /** @return array<string, mixed> */
    private function ai(): array
    {
        $spend = DB::table('ai_invocations')
            ->selectRaw('coalesce(sum(cost_inr), 0) AS total, count(*) AS calls')
            ->first();

        $today = DB::table('ai_invocations')
            ->whereDate('created_at', today())
            ->selectRaw('coalesce(sum(cost_inr), 0) AS total')
            ->first();

        $jobs = DB::table('ai_jobs')
            ->selectRaw('status, count(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'calls' => (int) ($spend->calls ?? 0),
            'spend_inr' => round((float) ($spend->total ?? 0), 2),
            'spend_today_inr' => round((float) ($today->total ?? 0), 2),
            'daily_budget_inr' => (float) config('ai.daily_budget_inr', 0),
            'driver' => (string) config('ai.driver'),
            'jobs_running' => (int) $jobs->only(['PROCESSING', 'VALIDATING'])->sum(),
            'jobs_failed' => (int) $jobs->get('FAILED', 0),
        ];
    }
}
