<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Pyq\ParsePaperAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Pyq\PyqAnalyzer;
use App\Domain\Pyq\TopicMapper;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\PreviousYearPaper;
use App\Models\PreviousYearQuestion;
use App\Models\Source;
use App\Models\TopicFrequency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Previous-year papers and the analysis derived from them.
 *
 * Papers are entered rather than generated, so the write paths here are
 * deliberately plain. The value is in the analysis: it is the only evidence
 * the platform has about what an exam actually asks, and it feeds the
 * prediction weight SelectionEngine applies to every paper it assembles.
 */
final class PyqController extends Controller
{
    public function __construct(private readonly PyqAnalyzer $analyzer) {}

    public function index(Request $request): JsonResponse
    {
        $papers = PreviousYearPaper::query()
            ->when($request->filled('exam_id'), fn ($q) => $q->where('exam_id', $request->string('exam_id')))
            ->when($request->filled('year'), fn ($q) => $q->where('year', $request->integer('year')))
            ->withCount('questions')
            ->orderByDesc('year')
            ->paginate(min(100, $request->integer('per_page', 25)));

        return response()->json([
            'data' => collect($papers->items())->map($this->shape(...))->all(),
            'meta' => [
                'total' => $papers->total(),
                'per_page' => $papers->perPage(),
                'current_page' => $papers->currentPage(),
                'last_page' => $papers->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'exam_id' => ['required', 'uuid', 'exists:exams,id'],
            'year' => ['required', 'integer', 'between:1950,2100'],
            'paper_name' => ['required', 'string', 'max:200'],
            'session' => ['sometimes', 'nullable', 'string', 'max:60'],
            'language_id' => ['sometimes', 'nullable', 'uuid', 'exists:languages,id'],
            'source_id' => ['sometimes', 'nullable', 'uuid', 'exists:sources,id'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $context = app(TenantContext::class);

        // updateOrCreate on the natural key: re-uploading the 2023 paper must
        // correct it, not create a second copy that doubles every frequency
        // count derived from it.
        $paper = PreviousYearPaper::query()->updateOrCreate(
            [
                'exam_id' => $validated['exam_id'],
                'year' => $validated['year'],
                'paper_name' => $validated['paper_name'],
                'session' => $validated['session'] ?? null,
            ],
            [
                'organization_id' => $context->organizationId,
                'language_id' => $validated['language_id'] ?? null,
                'source_id' => $validated['source_id'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'uploaded',
            ],
        );

        return response()->json(['data' => $this->shape($paper)], 201);
    }

    public function show(string $paperId): JsonResponse
    {
        $paper = PreviousYearPaper::query()->withCount('questions')->findOrFail($paperId);

        return response()->json([
            'data' => $this->shape($paper) + [
                'questions' => $paper->questions()
                    ->orderBy('question_number')
                    ->get()
                    ->map(fn (PreviousYearQuestion $q): array => [
                        'id' => $q->getKey(),
                        'question_number' => $q->question_number,
                        'question_text' => $q->question_text,
                        'options' => $q->optionMap(),
                        'correct_option' => $q->correct_option,
                        'canonical_topic_id' => $q->canonical_topic_id,
                        'difficulty' => $q->difficulty,
                        'question_type' => $q->question_type,
                        'mapping_source' => $q->mapping_source,
                    ])->all(),
            ],
        ]);
    }

    /**
     * Bulk-enters the questions of one paper.
     *
     * Whole-paper replacement rather than append: a partially re-entered paper
     * would leave a mix of old and new numbering, and every frequency count
     * downstream would be wrong in a way nothing would surface.
     */
    public function storeQuestions(Request $request, string $paperId): JsonResponse
    {
        $paper = PreviousYearPaper::query()->findOrFail($paperId);

        $validated = $request->validate([
            'questions' => ['required', 'array', 'min:1', 'max:500'],
            'questions.*.question_number' => ['required', 'integer', 'min:1'],
            'questions.*.question_text' => ['required', 'string', 'max:2000'],
            'questions.*.option_a' => ['sometimes', 'nullable', 'string', 'max:500'],
            'questions.*.option_b' => ['sometimes', 'nullable', 'string', 'max:500'],
            'questions.*.option_c' => ['sometimes', 'nullable', 'string', 'max:500'],
            'questions.*.option_d' => ['sometimes', 'nullable', 'string', 'max:500'],
            'questions.*.correct_option' => ['sometimes', 'nullable', 'in:A,B,C,D'],
            'questions.*.canonical_topic_id' => ['sometimes', 'nullable', 'uuid', 'exists:canonical_topics,id'],
            'questions.*.difficulty' => ['sometimes', 'nullable', 'in:easy,medium,hard'],
            'questions.*.question_type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'questions.*.marks' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $context = app(TenantContext::class);

        DB::transaction(function () use ($paper, $validated, $context): void {
            $paper->questions()->delete();

            foreach ($validated['questions'] as $row) {
                PreviousYearQuestion::query()->create([
                    'organization_id' => $paper->organization_id ?? $context->organizationId,
                    'paper_id' => $paper->getKey(),
                    'question_number' => $row['question_number'],
                    'question_text' => $row['question_text'],
                    'option_a' => $row['option_a'] ?? null,
                    'option_b' => $row['option_b'] ?? null,
                    'option_c' => $row['option_c'] ?? null,
                    'option_d' => $row['option_d'] ?? null,
                    'correct_option' => $row['correct_option'] ?? null,
                    'canonical_topic_id' => $row['canonical_topic_id'] ?? null,
                    'difficulty' => $row['difficulty'] ?? null,
                    'question_type' => $row['question_type'] ?? 'mcq4',
                    'marks' => $row['marks'] ?? 1,
                    'question_hash' => PreviousYearQuestion::hashFor($row['question_text']),
                    'mapping_source' => isset($row['canonical_topic_id']) ? 'human' : 'unmapped',
                ]);
            }

            $paper->forceFill([
                'total_questions' => count($validated['questions']),
                'parsed_questions' => count($validated['questions']),
                'status' => 'parsed',
            ])->save();
        });

        return response()->json([
            'data' => $this->shape($paper->fresh()->loadCount('questions')),
        ], 201);
    }

    /**
     * Reads the paper out of an uploaded PDF.
     *
     * Synchronous: extracting text from a 4-page question paper is a second of
     * work, unlike a 600-page textbook, and making the admin poll a job for it
     * would be ceremony without benefit.
     */
    public function parse(Request $request, string $paperId, ParsePaperAction $action): JsonResponse
    {
        $paper = PreviousYearPaper::query()->findOrFail($paperId);

        $validated = $request->validate([
            'source_id' => ['required', 'uuid', 'exists:sources,id'],
        ]);

        $result = $action->execute($paper, Source::query()->findOrFail($validated['source_id']));

        // Parsing replaces every question on the paper, so what a run produced
        // — and how much it silently skipped — has to be recoverable.
        app(AuditRecorder::class)->record('pyq.parsed', $paper, $result + [
            'source_id' => $validated['source_id'],
        ]);

        return response()->json([
            'data' => $this->shape($paper->fresh()) + $result,
        ]);
    }

    /**
     * Files this paper's questions under canonical topics.
     *
     * Separate from parsing on purpose: parsing is deterministic and free,
     * mapping costs model calls, and re-reading a paper should not silently
     * re-spend on classification that was already done and reviewed.
     */
    public function map(string $paperId, TopicMapper $mapper): JsonResponse
    {
        $paper = PreviousYearPaper::query()->findOrFail($paperId);

        $result = $mapper->mapPaper($paper->getKey());

        if ($result['mapped'] > 0) {
            $paper->forceFill(['status' => 'mapped'])->save();
        }

        // Mapping is what makes a paper count towards the analysis, and the
        // model's share of it is the part a human may later want to re-check.
        app(AuditRecorder::class)->record('pyq.mapped', $paper, $result);

        return response()->json([
            'data' => $result + [
                'paper_id' => $paper->getKey(),
                // Surfaced rather than buried: an unmapped question is invisible
                // to frequency analysis, and a low-confidence one should be
                // looked at before any weight is placed on it.
                'note' => $result['unmapped'] > 0 || $result['review'] > 0
                    ? 'Unmapped questions contribute nothing to analysis; '
                        .'low-confidence mappings are flagged for review.'
                    : null,
            ],
        ]);
    }

    /** Rebuilds the frequency table for the paper's exam. */
    public function analyze(string $paperId): JsonResponse
    {
        $paper = PreviousYearPaper::query()->findOrFail($paperId);

        return response()->json([
            'data' => $this->analyzer->rebuild($paper->exam_id) + ['exam_id' => $paper->exam_id],
        ]);
    }

    /**
     * The analysis itself: which topics this exam actually favours.
     */
    public function analysis(Request $request, string $examId): JsonResponse
    {
        $rows = TopicFrequency::query()
            ->with('canonicalTopic:id,name,slug,domain')
            ->where('exam_id', $examId)
            ->when($request->filled('trend'), fn ($q) => $q->where('trend', $request->string('trend')))
            ->orderByDesc('frequency_score')
            ->limit(min(200, $request->integer('limit', 50)))
            ->get();

        return response()->json([
            'data' => $rows->map(fn (TopicFrequency $row): array => [
                'canonical_topic_id' => $row->canonical_topic_id,
                'topic' => $row->canonicalTopic?->name,
                'domain' => $row->canonicalTopic?->domain,
                'total_asked' => $row->total_asked,
                'years_asked' => $row->years_asked,
                'years_covered' => $row->years_covered,
                'first_asked_year' => $row->first_asked_year,
                'last_asked_year' => $row->last_asked_year,
                'frequency_score' => $row->frequency_score,
                'trend' => $row->trend,
                'difficulty_mix' => $row->difficulty_mix,
                'type_mix' => $row->type_mix,
            ])->all(),
            'meta' => [
                'topics' => $rows->count(),
                'computed_at' => $rows->max('computed_at')?->toIso8601String(),
                // Said plainly wherever the analysis is served: this describes
                // the past. It is never a promise about the next paper.
                'disclaimer' => 'Frequency describes past papers. It is guidance for '
                    .'prioritisation, not a prediction of what will be asked.',
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function shape(PreviousYearPaper $paper): array
    {
        return [
            'id' => $paper->getKey(),
            'exam_id' => $paper->exam_id,
            'year' => $paper->year,
            'session' => $paper->session,
            'paper_name' => $paper->paper_name,
            'total_questions' => $paper->total_questions,
            'parsed_questions' => $paper->parsed_questions,
            'question_count' => $paper->questions_count ?? null,
            'status' => $paper->status,
            'created_at' => $paper->created_at?->toIso8601String(),
        ];
    }
}
