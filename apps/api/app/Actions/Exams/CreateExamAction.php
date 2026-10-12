<?php

declare(strict_types=1);

namespace App\Actions\Exams;

use App\Domain\Audit\AuditRecorder;
use App\Models\Exam;
use App\Models\ExamPaper;
use App\Models\ExamStage;
use App\Models\ExamSubject;
use App\Models\ExamVersion;
use App\Models\Syllabus;
use App\Models\SyllabusVersion;
use Illuminate\Support\Facades\DB;

/**
 * An exam with everything downstream needs to treat it as real.
 *
 * A bare exams row is not usable: selection reads the paper's question count
 * and duration, the syllabus engine needs a syllabus to attach units to, and
 * PDFs need a language. So creation builds the whole spine in one transaction
 * — exam, active version, one written stage, its paper and subjects, and an
 * empty draft syllabus — rather than leaving an operator to discover which
 * missing row is why nothing works.
 */
final class CreateExamAction
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated ExamController::store payload
     */
    public function execute(array $data, bool $platformOwned): Exam
    {
        return DB::transaction(function () use ($data, $platformOwned): Exam {
            $exam = new Exam([
                'code' => $data['code'],
                'name' => $data['name'],
                'conducting_body' => $data['conducting_body'] ?? null,
                'country' => $data['country'] ?? 'IN',
                'state' => $data['state'] ?? null,
                'category' => $data['category'] ?? null,
                'level' => $data['level'] ?? null,
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            if ($platformOwned) {
                $exam->asPlatformOwned();
            }

            $exam->save();

            $version = ExamVersion::query()->create([
                'exam_id' => $exam->getKey(),
                'version_label' => $data['version_label'] ?? (string) now()->year,
                'notification_date' => $data['notification_date'] ?? null,
                'exam_date' => $data['exam_date'] ?? null,
                'status' => 'active',
            ]);

            $languageIds = $data['language_ids'] ?? [];
            $default = $data['default_language_id'] ?? ($languageIds[0] ?? null);

            foreach ($languageIds as $languageId) {
                $version->languages()->attach($languageId, ['is_default' => $languageId === $default]);
            }

            $stage = ExamStage::query()->create([
                'exam_version_id' => $version->getKey(),
                'name' => $data['stage_name'] ?? 'Written Examination',
                'sequence' => 1,
                'is_qualifying' => false,
            ]);

            $paperData = $data['paper'];

            $paper = ExamPaper::query()->create([
                'exam_stage_id' => $stage->getKey(),
                'name' => $paperData['name'] ?? 'Paper I',
                'sequence' => 1,
                'duration_minutes' => $paperData['duration_minutes'],
                'total_questions' => $paperData['total_questions'],
                'total_marks' => $paperData['total_marks'] ?? $paperData['total_questions'],
                'negative_marking' => $paperData['negative_marking'] ?? 0,
                'question_type' => 'mcq4',
            ]);

            $this->createSubjects($paper, $data['subjects'] ?? []);

            $syllabus = Syllabus::query()->create([
                'exam_id' => $exam->getKey(),
                'name' => "{$exam->name} Syllabus",
            ]);

            // Draft, not active: an empty syllabus that claimed to be live
            // would let plans and selection run against zero topics.
            SyllabusVersion::query()->create([
                'syllabus_id' => $syllabus->getKey(),
                'exam_version_id' => $version->getKey(),
                'version_number' => 1,
                'effective_from' => $data['notification_date'] ?? now()->toDateString(),
                'status' => 'draft',
            ]);

            $this->audit->record('exam.created', $exam, [
                'code' => $exam->code,
                'name' => $exam->name,
                'platform' => $platformOwned,
                'total_questions' => $paper->total_questions,
            ]);

            return $exam;
        });
    }

    /**
     * Weights default to each subject's share of the paper's questions, which
     * is what the notification's question counts mean.
     *
     * @param  list<array<string, mixed>>  $subjects
     */
    private function createSubjects(ExamPaper $paper, array $subjects): void
    {
        $total = array_sum(array_map(static fn (array $s): int => (int) ($s['question_count'] ?? 0), $subjects));

        foreach (array_values($subjects) as $sequence => $subject) {
            $count = (int) ($subject['question_count'] ?? 0);

            ExamSubject::query()->create([
                'exam_paper_id' => $paper->getKey(),
                'name' => $subject['name'],
                'question_count' => $count ?: null,
                'weight' => $subject['weight'] ?? ($total > 0 ? round($count / $total, 4) : 0),
                'sequence' => $sequence,
            ]);
        }
    }
}
