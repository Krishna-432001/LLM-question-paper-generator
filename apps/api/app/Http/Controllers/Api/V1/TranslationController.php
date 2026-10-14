<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Jobs\DispatchTrackedJobAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Translation\TranslationPrompts;
use App\Domain\Translation\TranslationRequester;
use App\Domain\Translation\TranslationStore;
use App\Http\Controllers\Controller;
use App\Jobs\Translation\TranslateQuestions;
use App\Models\Exam;
use App\Models\Language;
use App\Models\Question;
use App\Models\QuestionTranslation;
use App\Models\Test;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Translating the bank, and the human side of it.
 *
 * Ordering a translation returns a job; the numbers say how much of an exam
 * can already be set in a language; the review queue shows a person every
 * translation the machine was not sure of, beside its source and with the
 * checks it failed — so the reviewer audits the machine's doubt rather than
 * re-translating from scratch.
 */
final class TranslationController extends Controller
{
    private const STATUSES = ['MACHINE', 'AI_VALIDATED', 'NEEDS_REVIEW', 'HUMAN_APPROVED', 'STALE', 'REJECTED'];

    public function __construct(
        private readonly TranslationStore $store,
        private readonly TranslationPrompts $prompts,
    ) {}

    public function store(
        Request $request,
        DispatchTrackedJobAction $dispatch,
        TranslationRequester $requester,
    ): JsonResponse {
        $this->authorizeEditor($request);

        $data = $request->validate([
            'language_id' => ['required', 'uuid', 'exists:languages,id'],
            'exam_id' => ['nullable', 'uuid'],
            'test_id' => ['nullable', 'uuid'],
            'question_ids' => ['nullable', 'array', 'max:'.TranslateQuestions::MAX_LIMIT],
            'question_ids.*' => ['uuid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.TranslateQuestions::MAX_LIMIT],
        ]);

        abort_if(
            empty($data['exam_id']) && empty($data['test_id']) && empty($data['question_ids']),
            422,
            'Say which questions to translate: an exam, a test, or a list of question ids.',
        );

        $language = Language::query()->findOrFail($data['language_id']);

        abort_unless($this->prompts->exists($language), 422, sprintf(
            "There is no translation prompt for %s yet. Seed an active prompt named '%s' first.",
            $language->name,
            $this->prompts->nameFor($language),
        ));

        // Resolved through their own models so a hidden exam or test is a 404,
        // exactly as it would be anywhere else.
        $exam = empty($data['exam_id']) ? null : Exam::query()->findOrFail($data['exam_id']);
        $test = empty($data['test_id']) ? null : Test::query()->findOrFail($data['test_id']);

        // A second click while the first run is still going gets that run.
        if ($exam !== null && $test === null && empty($data['question_ids'])) {
            $running = $requester->unfinishedJob($exam->getKey(), $language->getKey());

            if ($running !== null) {
                return $this->accepted($running->getKey(), $running->status->value, true);
            }
        }

        $context = app(TenantContext::class);

        $job = $dispatch->execute(
            type: 'translation',
            jobClass: TranslateQuestions::class,
            input: array_filter([
                'language_id' => $language->getKey(),
                'exam_id' => $exam?->getKey(),
                'test_id' => $test?->getKey(),
                'question_ids' => $data['question_ids'] ?? null,
                'limit' => $data['limit'] ?? null,
                'trigger' => 'admin',
            ], static fn ($v): bool => $v !== null && $v !== []),
            organizationId: $context->organizationId,
            userId: $context->userId,
            subjectType: $exam !== null ? Exam::class : ($test !== null ? Test::class : null),
            subjectId: $exam?->getKey() ?? $test?->getKey(),
        );

        return $this->accepted($job->getKey(), $job->status->value, false);
    }

    /** How much of the approved bank is readable in a language. */
    public function stats(Request $request, TranslationRequester $requester): JsonResponse
    {
        $this->authorizeEditor($request);

        $data = $request->validate([
            'language_id' => ['required', 'uuid', 'exists:languages,id'],
            'exam_id' => ['nullable', 'uuid'],
        ]);

        $language = Language::query()->findOrFail($data['language_id']);
        $examId = $data['exam_id'] ?? null;

        if ($examId !== null) {
            Exam::query()->findOrFail($examId);
        }

        $approved = Question::query()
            ->approved()
            ->when($examId, fn ($q) => $q->whereHas('examMappings', fn ($m) => $m
                ->where('exam_id', $examId)
                ->where('is_active', true)));

        $total = (clone $approved)->count();
        $available = (clone $approved)->availableIn($language->getKey())->count();
        $mastered = (clone $approved)->where('master_language_id', $language->getKey())->count();
        $needing = ($examId !== null
            ? $this->store->needingTranslationForExam($examId, $language->getKey())
            : $this->store->needingTranslation($language->getKey()))->count();

        // Latest version per question only: a translation re-done twice is
        // one question, not three.
        $byStatus = DB::table('question_translations as qt')
            ->where('qt.language_id', $language->getKey())
            ->where('qt.is_master', false)
            ->whereIn('qt.question_id', (clone $approved)->select('questions.id'))
            ->whereRaw('qt.translation_version = (
                SELECT max(q2.translation_version) FROM question_translations q2
                WHERE q2.question_id = qt.question_id AND q2.language_id = qt.language_id AND NOT q2.is_master
            )')
            ->groupBy('qt.translation_status')
            ->selectRaw('qt.translation_status::text as status, count(*) as n')
            ->pluck('n', 'status');

        $running = $examId !== null ? $requester->unfinishedJob($examId, $language->getKey()) : null;

        return response()->json([
            'data' => [
                'language' => ['id' => $language->getKey(), 'code' => $language->code, 'name' => $language->name],
                'exam_id' => $examId,
                'approved' => $total,
                'available' => $available,
                'mastered_in_language' => $mastered,
                'translated_usable' => max(0, $available - $mastered),
                'needs_translation' => $needing,
                'coverage' => $total > 0 ? round($available / $total, 4) : 0.0,
                'by_status' => array_combine(
                    self::STATUSES,
                    array_map(static fn (string $s): int => (int) ($byStatus[$s] ?? 0), self::STATUSES),
                ),
                'has_prompt' => $this->prompts->exists($language),
                'running_job_id' => $running?->getKey(),
            ],
        ]);
    }

    /** Source and translation side by side, with what the checks found. */
    public function review(Request $request): JsonResponse
    {
        $this->authorizeEditor($request);

        $data = $request->validate([
            'language_id' => ['required', 'uuid', 'exists:languages,id'],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'exam_id' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $examId = $data['exam_id'] ?? null;

        // Only translations of questions this caller can see.
        $visible = Question::query()
            ->select('questions.id')
            ->when($examId, fn ($q) => $q->whereHas('examMappings', fn ($m) => $m
                ->where('exam_id', $examId)
                ->where('is_active', true)));

        $query = QuestionTranslation::query()
            ->where('language_id', $data['language_id'])
            ->where('is_master', false)
            ->where('translation_status', $data['status'] ?? 'NEEDS_REVIEW')
            ->whereIn('question_id', $visible);

        $total = (clone $query)->count();

        $rows = $query
            ->with(['question.options', 'question.master', 'language'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->offset((int) ($data['offset'] ?? 0))
            ->limit((int) ($data['limit'] ?? 25))
            ->get();

        $codes = Language::query()->pluck('code', 'id');

        return response()->json([
            'data' => $rows
                ->filter(fn (QuestionTranslation $t): bool => $t->question !== null)
                ->map(fn (QuestionTranslation $t): array => $this->shape($t, $codes->all()))
                ->values()
                ->all(),
            'meta' => ['total' => $total],
        ]);
    }

    /**
     * A person's verdict. Approval may carry corrections; either way the
     * decision, the reviewer and the fields they changed are recorded.
     */
    public function decide(Request $request, string $translationId, AuditRecorder $audit): JsonResponse
    {
        $this->authorizeEditor($request);

        $translation = QuestionTranslation::query()->findOrFail($translationId);

        // Through the question, so a hidden question's translation is a 404.
        $question = Question::query()->with(['options', 'master'])->findOrFail($translation->question_id);

        // A platform question's translation is read by every tenant, so
        // deciding it is platform curation, not an organisation's own edit.
        if ($question->isPlatformOwned()) {
            $this->authorizeCurator($request, 'Only a platform reviewer can approve or reject translations of shared questions.');
        }

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'edits' => ['nullable', 'array:question_text,options,explanation'],
            'edits.question_text' => ['sometimes', 'string', 'min:1', 'max:5000'],
            'edits.options' => ['sometimes', 'array:A,B,C,D'],
            'edits.options.*' => ['string', 'min:1', 'max:2000'],
            'edits.explanation' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        abort_if($translation->is_master, 422, 'This is the master text. Change it through question review, not translation review.');

        $edits = $data['edits'] ?? [];
        $decision = $data['decision'];

        abort_if($decision === 'reject' && $edits !== [], 422,
            'Corrections are saved with an approval. Reject without edits, or approve the corrected text.');

        $reviewer = $request->user();

        $translation = DB::transaction(function () use ($translation, $question, $edits, $decision, $data, $reviewer, $audit): QuestionTranslation {
            // The same lock a machine save takes, and the checks repeated
            // under it: otherwise a new version can be written between the
            // check and this decision, leaving an approved older version
            // behind a newer one under review.
            $this->store->lock($question->getKey(), $translation->language_id);
            $translation = $translation->fresh() ?? abort(404);

            abort_if($translation->translation_status === 'STALE', 409,
                'This translation is stale: its master question changed after it was made. Translate it again rather than approving the old text.');

            $latest = $this->store->latest($question->getKey(), $translation->language_id);

            abort_if($latest !== null && $latest->getKey() !== $translation->getKey(), 409,
                'A newer version of this translation exists. Review that one instead.');

            $from = (string) $translation->translation_status;
            $edited = [];

            if (isset($edits['question_text'])) {
                $translation->question_text = trim($edits['question_text']);
                $edited[] = 'question_text';
            }

            foreach ((array) ($edits['options'] ?? []) as $key => $text) {
                $translation->{'option_'.strtolower((string) $key)} = trim((string) $text);
                $edited[] = "options.{$key}";
            }

            if (array_key_exists('explanation', $edits)) {
                $translation->explanation = $edits['explanation'] === null ? null : trim($edits['explanation']);
                $edited[] = 'explanation';
            }

            $to = $decision === 'approve' ? 'HUMAN_APPROVED' : 'REJECTED';

            $report = (array) $translation->validation_report;
            $report['review'] = [
                'decision' => $decision,
                'reason' => $data['reason'] ?? null,
                'edited_fields' => $edited,
                'previous_status' => $from,
            ];

            $translation->forceFill([
                'translation_status' => $to,
                'review_status' => $decision === 'approve' ? 'approved' : 'rejected',
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'validation_report' => $report,
            ])->save();

            $audit->record('translation.'.($decision === 'approve' ? 'approved' : 'rejected'), $translation, [
                'from' => $from,
                'to' => $to,
                'question_id' => $translation->question_id,
                'language_id' => $translation->language_id,
                'edited_fields' => $edited,
                'reason' => $data['reason'] ?? null,
            ], $reviewer->getKey());

            return $translation->fresh(['question.options', 'question.master', 'language']);
        });

        return response()->json(['data' => $this->shape($translation, Language::query()->pluck('code', 'id')->all())]);
    }

    private function accepted(string $jobId, string $status, bool $reused): JsonResponse
    {
        return response()->json([
            'data' => [
                'job_id' => $jobId,
                'status' => $status,
                'poll_url' => "/api/v1/jobs/{$jobId}",
                'reused' => $reused,
            ],
        ], 202);
    }

    /**
     * @param  array<string, string>  $codes  language id → code
     * @return array<string, mixed>
     */
    private function shape(QuestionTranslation $t, array $codes): array
    {
        $question = $t->question;
        $master = $question?->master;
        $report = (array) $t->validation_report;

        return [
            'id' => $t->getKey(),
            'question_id' => $t->question_id,
            'language' => $codes[$t->language_id] ?? null,
            'source_language' => $codes[$t->source_language_id ?? ''] ?? ($codes[$question?->master_language_id ?? ''] ?? null),
            'status' => $t->translation_status,
            'translation_version' => $t->translation_version,
            'source_question_version' => $t->source_question_version,
            'question_version' => $question?->current_version,
            'quality_score' => $t->quality_score,
            'failures' => array_values((array) ($report['failures'] ?? [])),
            'validation_report' => $report,
            'correct_option' => $question?->correctOptionKey(),
            'source' => [
                'question_text' => $master?->question_text,
                'options' => $master?->optionMap() ?? [],
                'explanation' => $master?->explanation,
            ],
            'translation' => [
                'question_text' => $t->question_text,
                'options' => $t->optionMap(),
                'explanation' => $t->explanation,
            ],
            'reviewed_by' => $t->reviewed_by,
            'reviewed_at' => $t->reviewed_at,
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }

    private function authorizeEditor(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user !== null && ($user->isPlatformAdmin() || $user->can('questions.edit')),
            403,
            'You do not have permission to manage translations.',
        );
    }

    /** Platform content: a platform admin or a platform reviewer only. */
    private function authorizeCurator(Request $request, string $message): void
    {
        $user = $request->user();

        abort_unless(
            $user !== null && ($user->isPlatformAdmin() || $user->can('questions.approve.platform')),
            403,
            $message,
        );
    }
}
