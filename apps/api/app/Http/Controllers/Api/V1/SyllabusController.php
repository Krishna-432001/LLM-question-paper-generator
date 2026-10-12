<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Jobs\DispatchTrackedJobAction;
use App\Domain\Ingestion\SourceScanner;
use App\Domain\Ingestion\TextNormalizer;
use App\Domain\Syllabus\SyllabusEditor;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Topics\LessonTopicMapper;
use App\Http\Controllers\Controller;
use App\Infrastructure\Ocr\PopplerTextExtractor;
use App\Jobs\Ingestion\ProcessSource;
use App\Models\BookLesson;
use App\Models\CanonicalTopic;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\ExamTopic;
use App\Models\ExamUnit;
use App\Models\Source;
use App\Models\SyllabusVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Syllabus versions and the lesson → topic links that tie books to them.
 *
 * Every write goes through SyllabusEditor, which refuses to change anything
 * but a draft. This controller's job is resolving which exam a version
 * belongs to — syllabus tables carry no tenant column, so access is decided
 * by whether the caller can see, and manage, the exam.
 */
final class SyllabusController extends Controller
{
    public function __construct(private readonly SyllabusEditor $editor) {}

    public function canonicalTopics(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $topics = CanonicalTopic::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'ilike', "%{$q}%")
                ->orWhere('slug', 'ilike', "%{$q}%")))
            ->when($request->filled('domain'), fn ($query) => $query->where('domain', $request->string('domain')))
            ->orderBy('name')
            ->limit(min(200, max(1, (int) $request->query('limit', 50))))
            ->get(['id', 'slug', 'name', 'domain']);

        return response()->json(['data' => $topics]);
    }

    public function createDraft(Request $request, string $examId): JsonResponse
    {
        $exam = Exam::query()->findOrFail($examId);
        $this->authorizeManage($request, $exam);

        $data = $request->validate(['from_version_id' => ['sometimes', 'nullable', 'uuid']]);
        $draft = $this->editor->createDraft($exam, $data['from_version_id'] ?? null);

        return response()->json(['data' => $this->versionShape($draft)], 201);
    }

    public function discard(Request $request, string $versionId): JsonResponse
    {
        [$version] = $this->resolve($request, $versionId);
        $this->editor->discard($version);

        return response()->json(['data' => $this->versionShape($version->fresh())]);
    }

    public function storeUnit(Request $request, string $versionId): JsonResponse
    {
        [$version, $exam] = $this->resolve($request, $versionId);

        $data = $request->validate([
            'exam_subject_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:200'],
            'code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'weight' => ['sometimes', 'numeric', 'between:0,1'],
        ]);

        $this->assertSubjectOf($exam, $data['exam_subject_id']);

        return response()->json(['data' => $this->editor->addUnit($version, $data)], 201);
    }

    public function updateUnit(Request $request, string $versionId, string $unitId): JsonResponse
    {
        [$version, $exam] = $this->resolve($request, $versionId);
        $unit = ExamUnit::query()->where('syllabus_version_id', $version->getKey())->findOrFail($unitId);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'weight' => ['sometimes', 'numeric', 'between:0,1'],
            'sequence' => ['sometimes', 'integer', 'min:0'],
            'exam_subject_id' => ['sometimes', 'uuid'],
        ]);

        if (isset($data['exam_subject_id'])) {
            $this->assertSubjectOf($exam, $data['exam_subject_id']);
        }

        return response()->json(['data' => $this->editor->updateUnit($unit, $data)]);
    }

    public function destroyUnit(Request $request, string $versionId, string $unitId): JsonResponse
    {
        [$version] = $this->resolve($request, $versionId);
        $this->editor->removeUnit(ExamUnit::query()->where('syllabus_version_id', $version->getKey())->findOrFail($unitId));

        return response()->json(null, 204);
    }

    public function storeTopic(Request $request, string $versionId, string $unitId): JsonResponse
    {
        [$version] = $this->resolve($request, $versionId);
        $unit = ExamUnit::query()->where('syllabus_version_id', $version->getKey())->findOrFail($unitId);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'canonical_topic_id' => ['sometimes', 'nullable', 'uuid', 'exists:canonical_topics,id'],
            'weight' => ['sometimes', 'numeric', 'between:0,1'],
            'estimated_minutes' => ['sometimes', 'integer', 'between:5,600'],
        ]);

        return response()->json(['data' => $this->editor->addTopic($unit, $data)], 201);
    }

    public function updateTopic(Request $request, string $versionId, string $topicId): JsonResponse
    {
        [$version] = $this->resolve($request, $versionId);
        $topic = ExamTopic::query()->where('syllabus_version_id', $version->getKey())->findOrFail($topicId);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'canonical_topic_id' => ['sometimes', 'nullable', 'uuid', 'exists:canonical_topics,id'],
            'weight' => ['sometimes', 'numeric', 'between:0,1'],
            'estimated_minutes' => ['sometimes', 'integer', 'between:5,600'],
            'sequence' => ['sometimes', 'integer', 'min:0'],
        ]);

        return response()->json(['data' => $this->editor->updateTopic($topic, $data)]);
    }

    public function destroyTopic(Request $request, string $versionId, string $topicId): JsonResponse
    {
        [$version] = $this->resolve($request, $versionId);
        $this->editor->removeTopic(ExamTopic::query()->where('syllabus_version_id', $version->getKey())->findOrFail($topicId));

        return response()->json(null, 204);
    }

    /**
     * Pasted text, or the text of an uploaded syllabus PDF. Deterministic, so
     * it answers inline; nothing here calls a model.
     */
    public function import(
        Request $request,
        string $versionId,
        PopplerTextExtractor $extractor,
        TextNormalizer $normalizer,
        SourceScanner $scanner,
    ): JsonResponse {
        [$version, $exam] = $this->resolve($request, $versionId);

        $data = $request->validate([
            'exam_subject_id' => ['required', 'uuid'],
            'text' => ['required_without:source_id', 'nullable', 'string', 'max:200000'],
            'source_id' => ['required_without:text', 'nullable', 'uuid'],
            'replace' => ['sometimes', 'boolean'],
        ]);

        $subject = $this->assertSubjectOf($exam, $data['exam_subject_id']);
        $text = (string) ($data['text'] ?? '');

        if ($text === '' && isset($data['source_id'])) {
            $source = Source::query()->findOrFail($data['source_id']);
            $scanner->ensureScanned($source);
            $source->refresh();

            abort_unless($extractor->isAvailable(), 422, 'pdftotext is not installed, so a PDF cannot be read. Paste the syllabus text instead.');

            $disk = Storage::disk($source->storage_disk);
            $pages = $extractor->extract($disk->path($source->storage_path));
            $text = implode("\n", array_map(fn ($p) => $normalizer->normalizePage($p->text), $pages));
            $version->forceFill(['source_id' => $source->getKey()])->save();
        }

        $result = $this->editor->importText($version, $subject, $text, (bool) ($data['replace'] ?? false));

        return response()->json(['data' => $result], 201);
    }

    public function diff(Request $request, string $versionId): JsonResponse
    {
        [$version, $exam] = $this->resolve($request, $versionId, manage: false);

        $against = $request->filled('against')
            ? $this->editor->versionsOf($exam)->firstWhere('id', $request->string('against')->toString())
            : $this->editor->versionsOf($exam)->firstWhere('status', 'active');

        abort_if($against === null, 404, 'There is no version to compare against.');

        return response()->json(['data' => $this->editor->diff($against, $version)]);
    }

    public function publish(Request $request, string $versionId): JsonResponse
    {
        [$version, $exam] = $this->resolve($request, $versionId);

        // A platform syllabus changes every tenant's plans and papers, so it
        // takes the publish permission, not just manage.
        if ($exam->isPlatformOwned()) {
            abort_unless($request->user()->can('syllabi.publish'), 403, 'Publishing a platform syllabus needs syllabi.publish.');
        }

        $published = $this->editor->publish($version, app(TenantContext::class)->userId);

        return response()->json(['data' => $this->versionShape($published)]);
    }

    // ------------------------------------------------ lesson → topic links

    public function lessonTopics(string $sourceId): JsonResponse
    {
        $source = Source::query()->findOrFail($sourceId);

        $rows = DB::table('book_lessons as l')
            ->join('book_chapters as c', 'c.id', '=', 'l.book_chapter_id')
            ->join('books as b', 'b.id', '=', 'c.book_id')
            ->leftJoin('book_topic_mappings as m', function ($join): void {
                // The mapping that wins: human first, then the most confident.
                $join->on('m.book_lesson_id', '=', 'l.id')->whereRaw('m.id = (
                    SELECT id FROM book_topic_mappings x WHERE x.book_lesson_id = l.id
                    ORDER BY CASE WHEN x.mapped_by = \'human\' THEN 0 ELSE 1 END, x.confidence DESC LIMIT 1)');
            })
            ->leftJoin('canonical_topics as t', 't.id', '=', 'm.canonical_topic_id')
            ->where('b.source_id', $source->getKey())
            ->orderBy('c.sequence')->orderBy('l.sequence')
            ->select('l.id as lesson_id', 'l.title as lesson', 'c.title as chapter',
                'm.canonical_topic_id', 't.name as topic', 't.slug as topic_slug', 'm.confidence', 'm.mapped_by')
            ->get()
            ->map(fn ($r) => [
                'lesson_id' => $r->lesson_id,
                'chapter' => $r->chapter,
                'lesson' => $r->lesson,
                'canonical_topic_id' => $r->canonical_topic_id,
                'topic' => $r->topic,
                'topic_slug' => $r->topic_slug,
                'confidence' => $r->confidence === null ? null : (float) $r->confidence,
                'mapped_by' => $r->mapped_by,
                'needs_review' => $r->mapped_by !== null && $r->mapped_by !== 'human'
                    && (float) $r->confidence < LessonTopicMapper::REVIEW_THRESHOLD,
            ]);

        return response()->json([
            'data' => $rows,
            'meta' => [
                'lessons' => $rows->count(),
                'mapped' => $rows->whereNotNull('canonical_topic_id')->count(),
                'needs_review' => $rows->where('needs_review', true)->count(),
            ],
        ]);
    }

    public function assignLessonTopic(Request $request, string $sourceId, string $lessonId, LessonTopicMapper $mapper): JsonResponse
    {
        $source = Source::query()->findOrFail($sourceId);

        // A platform book's lessons file platform questions; only a platform
        // admin may re-file those.
        abort_if($source->isPlatformOwned() && ! $request->user()->isPlatformAdmin(), 403, 'Only a platform admin can re-file a platform book.');

        $lesson = BookLesson::query()
            ->whereKey($lessonId)
            ->whereHas('chapter.book', fn ($q) => $q->where('source_id', $source->getKey()))
            ->firstOrFail();

        $data = $request->validate([
            'canonical_topic_id' => ['present', 'nullable', 'uuid', 'exists:canonical_topics,id'],
        ]);

        $tagged = $mapper->assignByHand($lesson, $data['canonical_topic_id'], app(TenantContext::class)->userId);

        return response()->json(['data' => [
            'lesson_id' => $lesson->getKey(),
            'canonical_topic_id' => $data['canonical_topic_id'],
            'questions_refiled' => $tagged,
        ]]);
    }

    /** Re-runs only the map stage, as a job: it may call a model. */
    public function remapTopics(string $sourceId, DispatchTrackedJobAction $dispatch): JsonResponse
    {
        $source = Source::query()->findOrFail($sourceId);
        abort_unless($source->stageStatus('chunk') === 'completed', 409, 'Finish processing the book before mapping it.');

        // Cleared rather than marked: ProcessSource re-runs any stage that is
        // not recorded as completed, and skips the rest.
        $state = $source->pipeline_state ?? [];
        unset($state['map']);
        $source->forceFill(['pipeline_state' => $state])->save();

        $job = $dispatch->execute(
            type: 'ingest',
            jobClass: ProcessSource::class,
            input: ['source_id' => $source->getKey()],
            organizationId: $source->organization_id,
            userId: app(TenantContext::class)->userId,
            subjectType: Source::class,
            subjectId: $source->getKey(),
        );

        return response()->json(['data' => [
            'job_id' => $job->getKey(),
            'status' => $job->status->value,
            'poll_url' => "/api/v1/jobs/{$job->getKey()}",
        ]], 202);
    }

    // ------------------------------------------------------------ helpers

    /** @return array{0: SyllabusVersion, 1: Exam} */
    private function resolve(Request $request, string $versionId, bool $manage = true): array
    {
        $version = SyllabusVersion::query()->with('syllabus')->findOrFail($versionId);

        // The exam's tenant scope decides visibility: a version of an exam the
        // caller cannot see is a 404, exactly like the exam itself.
        $exam = Exam::query()->findOrFail($version->syllabus->exam_id);

        if ($manage) {
            $this->authorizeManage($request, $exam);
        }

        return [$version, $exam];
    }

    private function authorizeManage(Request $request, Exam $exam): void
    {
        $user = $request->user();
        abort_unless($user?->can('syllabi.manage'), 403, 'You cannot edit syllabi.');

        if ($exam->isPlatformOwned()) {
            abort_unless($user->isPlatformAdmin(), 403, 'Only a platform admin can edit a platform syllabus.');
        }
    }

    private function assertSubjectOf(Exam $exam, string $subjectId): ExamSubject
    {
        return ExamSubject::query()
            ->whereKey($subjectId)
            ->whereHas('examPaper.examStage.examVersion', fn ($q) => $q->where('exam_id', $exam->getKey()))
            ->firstOr(fn () => abort(422, 'That subject does not belong to this exam.'));
    }

    /** @return array<string, mixed> */
    private function versionShape(SyllabusVersion $version): array
    {
        return [
            'id' => $version->getKey(),
            'version_number' => $version->version_number,
            'status' => $version->status,
            'effective_from' => $version->effective_from?->toDateString(),
            'effective_to' => $version->effective_to?->toDateString(),
            'published_at' => $version->published_at?->toIso8601String(),
        ];
    }
}
