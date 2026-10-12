<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Exams\CreateExamAction;
use App\Domain\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamPaper;
use App\Models\Syllabus;
use App\Models\SyllabusVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Writes to the exam catalogue. Reads stay in CatalogueController.
 *
 * Platform exams are shared with every tenant, so only a platform admin may
 * create or change one; an organization's own exams are managed by anyone
 * there holding exams.manage. RLS enforces the same rule underneath — this
 * layer exists so the refusal is a clear 403, not a write that matched no row.
 */
final class ExamController extends Controller
{
    public function show(string $examId): JsonResponse
    {
        return response()->json(['data' => $this->shape(Exam::query()->findOrFail($examId))]);
    }

    public function store(Request $request, CreateExamAction $action): JsonResponse
    {
        $this->authorizeManage($request, null);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'conducting_body' => ['sometimes', 'nullable', 'string', 'max:160'],
            'country' => ['sometimes', 'string', 'size:2'],
            'state' => ['sometimes', 'nullable', 'string', 'max:10'],
            'category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'level' => ['sometimes', 'nullable', 'string', 'max:40'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'platform' => ['sometimes', 'boolean'],
            'version_label' => ['sometimes', 'string', 'max:40'],
            'notification_date' => ['sometimes', 'nullable', 'date'],
            'exam_date' => ['sometimes', 'nullable', 'date'],
            'language_ids' => ['sometimes', 'array'],
            'language_ids.*' => ['uuid', 'exists:languages,id'],
            'default_language_id' => ['sometimes', 'uuid', 'in_array:language_ids.*'],
            'stage_name' => ['sometimes', 'string', 'max:120'],
            'paper' => ['required', 'array'],
            'paper.name' => ['sometimes', 'string', 'max:120'],
            'paper.duration_minutes' => ['required', 'integer', 'between:1,600'],
            'paper.total_questions' => ['required', 'integer', 'between:1,1000'],
            'paper.total_marks' => ['sometimes', 'numeric', 'between:1,10000'],
            'paper.negative_marking' => ['sometimes', 'numeric', 'between:0,5'],
            'subjects' => ['sometimes', 'array', 'max:20'],
            'subjects.*.name' => ['required', 'string', 'max:160'],
            'subjects.*.question_count' => ['sometimes', 'integer', 'between:0,1000'],
            'subjects.*.weight' => ['sometimes', 'numeric', 'between:0,1'],
        ]);

        $platform = $request->boolean('platform');

        if ($platform) {
            abort_unless($request->user()->isPlatformAdmin(), 403, 'Only a platform admin can create a platform exam.');
        }

        $data['code'] = strtoupper($data['code']);
        $this->assertCodeIsFree($data['code'], $platform);

        $exam = $action->execute($data, $platform);

        return response()->json(['data' => $this->shape($exam->fresh())], 201);
    }

    public function update(Request $request, string $examId, AuditRecorder $audit): JsonResponse
    {
        $exam = Exam::query()->findOrFail($examId);
        $this->authorizeManage($request, $exam);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'conducting_body' => ['sometimes', 'nullable', 'string', 'max:160'],
            'state' => ['sometimes', 'nullable', 'string', 'max:10'],
            'category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'level' => ['sometimes', 'nullable', 'string', 'max:40'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'notification_date' => ['sometimes', 'nullable', 'date'],
            'exam_date' => ['sometimes', 'nullable', 'date'],
        ]);

        DB::transaction(function () use ($exam, $data, $audit): void {
            $before = $exam->getAttributes();
            $exam->fill(collect($data)->except(['notification_date', 'exam_date'])->all())->save();
            $audit->recordChange('exam.updated', $exam, $before);

            // Dates belong to the sitting, which is the active version.
            $dates = collect($data)->only(['notification_date', 'exam_date'])->all();
            $version = $exam->activeVersion();

            if ($dates !== [] && $version !== null) {
                $versionBefore = $version->getAttributes();
                $version->fill($dates)->save();
                $audit->recordChange('exam_version.updated', $version, $versionBefore);
            }
        });

        return response()->json(['data' => $this->shape($exam->fresh())]);
    }

    public function updatePaper(Request $request, string $examId, string $paperId, AuditRecorder $audit): JsonResponse
    {
        $exam = Exam::query()->findOrFail($examId);
        $this->authorizeManage($request, $exam);

        $paper = ExamPaper::query()
            ->whereKey($paperId)
            ->whereHas('examStage.examVersion', fn ($q) => $q->where('exam_id', $exam->getKey()))
            ->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'duration_minutes' => ['sometimes', 'integer', 'between:1,600'],
            'total_questions' => ['sometimes', 'integer', 'between:1,1000'],
            'total_marks' => ['sometimes', 'numeric', 'between:1,10000'],
            'negative_marking' => ['sometimes', 'numeric', 'between:0,5'],
        ]);

        $before = $paper->getAttributes();
        $paper->fill($data)->save();
        $audit->recordChange('exam_paper.updated', $paper, $before);

        return response()->json(['data' => $this->shape($exam->fresh())]);
    }

    /**
     * Every syllabus version of the exam, and the unit/topic tree of the one
     * asked for (default: the active one, else the newest draft).
     */
    public function syllabus(Request $request, string $examId): JsonResponse
    {
        $exam = Exam::query()->findOrFail($examId);

        $versions = SyllabusVersion::query()
            ->whereIn('syllabus_id', Syllabus::query()->where('exam_id', $exam->getKey())->select('id'))
            ->orderByDesc('version_number')
            ->get();

        $selected = $request->filled('version_id')
            ? $versions->firstWhere('id', $request->string('version_id')->toString())
            : ($versions->firstWhere('status', 'active') ?? $versions->first());

        $units = $selected === null ? collect() : DB::table('exam_units as u')
            ->join('exam_subjects as s', 's.id', '=', 'u.exam_subject_id')
            ->where('u.syllabus_version_id', $selected->getKey())
            ->orderBy('s.sequence')->orderBy('u.sequence')
            ->select('u.id', 'u.name', 'u.code', 'u.weight', 'u.sequence', 's.id as subject_id', 's.name as subject')
            ->get();

        $topics = $selected === null ? collect() : DB::table('exam_topics as t')
            ->leftJoin('canonical_topics as c', 'c.id', '=', 't.canonical_topic_id')
            ->where('t.syllabus_version_id', $selected->getKey())
            ->orderBy('t.sequence')
            ->select('t.id', 't.exam_unit_id', 't.parent_id', 't.name', 't.weight', 't.estimated_minutes', 't.canonical_topic_id', 'c.slug as canonical_slug')
            ->get()
            ->groupBy('exam_unit_id');

        return response()->json([
            'data' => [
                'exam_id' => $exam->getKey(),
                'versions' => $versions->map(fn (SyllabusVersion $v) => [
                    'id' => $v->getKey(),
                    'version_number' => $v->version_number,
                    'status' => $v->status,
                    'effective_from' => $v->effective_from?->toDateString(),
                    'published_at' => $v->published_at?->toIso8601String(),
                ])->values()->all(),
                'selected_version_id' => $selected?->getKey(),
                'units' => $units->map(fn ($u) => [
                    'id' => $u->id,
                    'subject_id' => $u->subject_id,
                    'subject' => $u->subject,
                    'code' => $u->code,
                    'name' => $u->name,
                    'weight' => (float) $u->weight,
                    'topics' => ($topics[$u->id] ?? collect())->map(fn ($t) => [
                        'id' => $t->id,
                        'parent_id' => $t->parent_id,
                        'name' => $t->name,
                        'weight' => (float) $t->weight,
                        'estimated_minutes' => $t->estimated_minutes,
                        'canonical_topic_id' => $t->canonical_topic_id,
                        'canonical_slug' => $t->canonical_slug,
                    ])->values()->all(),
                ])->values()->all(),
            ],
        ]);
    }

    private function authorizeManage(Request $request, ?Exam $exam): void
    {
        $user = $request->user();

        abort_unless($user?->can('exams.manage'), 403, 'You cannot manage exams.');

        if ($exam !== null && $exam->isPlatformOwned()) {
            abort_unless($user->isPlatformAdmin(), 403, 'Only a platform admin can change a platform exam.');
        }
    }

    /**
     * Codes are unique per owner: one platform namespace, one per tenant.
     * Checked here so a clash is a 422 naming the field, not a 500 from the
     * partial unique index.
     */
    private function assertCodeIsFree(string $code, bool $platform): void
    {
        $taken = Exam::query()
            ->where('code', $code)
            ->when($platform, fn ($q) => $q->whereNull('organization_id'), fn ($q) => $q->whereNotNull('organization_id'))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => "An exam with code {$code} already exists."]);
        }
    }

    /** @return array<string, mixed> */
    private function shape(Exam $exam): array
    {
        $exam->load(['versions.stages.papers.subjects', 'versions.languages']);
        $version = $exam->activeVersion();
        $version?->load(['stages.papers.subjects', 'languages']);

        return [
            'id' => $exam->getKey(),
            'code' => $exam->code,
            'name' => $exam->name,
            'conducting_body' => $exam->conducting_body,
            'country' => $exam->country,
            'state' => $exam->state,
            'category' => $exam->category,
            'level' => $exam->level,
            'description' => $exam->description,
            'is_active' => $exam->is_active,
            'is_platform' => $exam->isPlatformOwned(),
            'active_version' => $version === null ? null : [
                'id' => $version->getKey(),
                'label' => $version->version_label,
                'notification_date' => $version->notification_date?->toDateString(),
                'exam_date' => $version->exam_date?->toDateString(),
                'languages' => $version->languages->map(fn ($l) => [
                    'id' => $l->getKey(),
                    'code' => $l->code,
                    'name' => $l->name,
                    'is_default' => (bool) $l->pivot->is_default,
                ])->values()->all(),
                'papers' => $version->stages->flatMap(fn ($stage) => $stage->papers->map(fn ($paper) => [
                    'id' => $paper->getKey(),
                    'stage' => $stage->name,
                    'name' => $paper->name,
                    'duration_minutes' => $paper->duration_minutes,
                    'total_questions' => $paper->total_questions,
                    'total_marks' => $paper->total_marks === null ? null : (float) $paper->total_marks,
                    'negative_marking' => (float) $paper->negative_marking,
                    'subjects' => $paper->subjects->sortBy('sequence')->map(fn ($s) => [
                        'id' => $s->getKey(),
                        'name' => $s->name,
                        'question_count' => $s->question_count,
                        'weight' => (float) $s->weight,
                    ])->values()->all(),
                ]))->values()->all(),
            ],
        ];
    }
}
