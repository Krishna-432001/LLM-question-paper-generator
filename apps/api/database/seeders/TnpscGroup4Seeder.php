<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Tenancy\TenantContext;
use App\Models\CanonicalTopic;
use App\Models\Exam;
use App\Models\ExamBlueprint;
use App\Models\ExamPaper;
use App\Models\ExamStage;
use App\Models\ExamSubject;
use App\Models\ExamTopic;
use App\Models\ExamUnit;
use App\Models\ExamVersion;
use App\Models\Language;
use App\Models\Syllabus;
use App\Models\SyllabusVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The MVP exam. Platform-owned (organization_id IS NULL) so every tenant
 * inherits it on day one.
 *
 * Structure is abridged for the seed — the full syllabus arrives via the
 * Phase 3 syllabus-extraction pipeline, which writes the same tables.
 */
final class TnpscGroup4Seeder extends Seeder
{
    /**
     * subject => [unit => [topics]], in the notification's unit order.
     *
     * Current Events is not a scored unit in Group IV — its questions are
     * spread across the six General Studies units — but its topics already
     * have questions and targets filed under them, so it is kept (last, and
     * weighted zero by TnpscBlueprintSeeder) rather than deleted.
     */
    private const SYLLABUS = [
        'General Studies' => [
            'Unit I — General Science' => [
                'Scientific Knowledge and Scientific Temper',
                'Physics: Force, Motion and Energy',
                'Chemistry: Elements and Compounds',
                'Botany: Plant Kingdom',
                'Zoology: Human Physiology',
            ],
            'Unit II — Geography' => [
                'Physical Features and Climate',
                'Natural Resources and Agriculture',
                'Transport and Communication',
                'Disaster Management',
            ],
            'Unit III — History, Culture of India and Indian National Movement' => [
                'Indus Valley Civilization',
                'Mauryan and Gupta Empires',
                'Indian National Movement',
                'Art, Culture and Heritage',
            ],
            'Unit IV — Indian Polity' => [
                'Constitution of India',
                'Fundamental Rights and Duties',
                'Union and State Executive',
                'Local Self Government',
            ],
            'Unit V — Indian Economy and Development Administration in Tamil Nadu' => [
                'Nature of Indian Economy and Planning',
                'Social Justice and Welfare Schemes',
                'Education and Health in Tamil Nadu',
                'e-Governance in Tamil Nadu',
            ],
            'Unit VI — History, Culture, Heritage and Socio-Political Movements of Tamil Nadu' => [
                'History of Tamil Society and Archaeological Discoveries',
                'Thirukkural and Tamil Literature',
                'Role of Tamil Nadu in the Freedom Struggle',
                'Socio-Political Movements in Tamil Nadu',
            ],
            'Current Events (not separately scored)' => [
                'Political Science and Governance',
                'Geography and Environment',
                'Economics and Development',
                'Awards, Sports and Books',
            ],
        ],
        'Aptitude and Mental Ability' => [
            'Unit VII — Aptitude' => [
                'Simplification and Percentage',
                'Ratio, Proportion and Averages',
                'Time, Work and Distance',
                'Logical Reasoning and Series',
            ],
        ],
    ];

    /**
     * Earlier seeds used these names. Units are upserted by name, so without
     * renaming first a re-seed would create a second unit beside the old one
     * and leave every topic, target and mapping attached to the stale copy.
     */
    private const RENAMED_UNITS = [
        'Unit II — Current Events' => 'Current Events (not separately scored)',
        'Unit III — Geography of India' => 'Unit II — Geography',
        'Unit IV — History and Culture of India' => 'Unit III — History, Culture of India and Indian National Movement',
        'Unit V — Indian Polity' => 'Unit IV — Indian Polity',
        'Unit VI — Tamil Nadu Development Administration' => 'Unit V — Indian Economy and Development Administration in Tamil Nadu',
    ];

    public function run(): void
    {
        // This seeder writes platform-owned rows (organization_id IS NULL),
        // which RLS forbids to any tenant context. Wrapping here rather than
        // relying on DatabaseSeeder keeps the seeder safe to call on its own.
        TenantContext::withoutTenancy(fn () => $this->seedExam());
    }

    private function seedExam(): void
    {
        $exam = Exam::query()->updateOrCreate(
            ['code' => 'TNPSC_G4', 'organization_id' => null],
            [
                'name' => 'TNPSC Group IV',
                'conducting_body' => 'Tamil Nadu Public Service Commission',
                'country' => 'IN',
                'state' => 'TN',
                'category' => 'state_govt',
                'level' => '10th',
                'description' => 'Combined Civil Services Examination IV — Group IV Services.',
                'is_active' => true,
            ],
        );

        $version = ExamVersion::query()->updateOrCreate(
            ['exam_id' => $exam->getKey(), 'version_label' => '2026'],
            ['status' => 'active', 'notification_date' => '2026-01-15'],
        );

        foreach (['en', 'ta'] as $index => $code) {
            $language = Language::byCode($code);

            if ($language !== null) {
                $version->languages()->syncWithoutDetaching([
                    $language->getKey() => ['is_default' => $code === 'ta'],
                ]);
            }
        }

        $stage = ExamStage::query()->updateOrCreate(
            ['exam_version_id' => $version->getKey(), 'sequence' => 1],
            ['name' => 'Written Examination', 'is_qualifying' => false],
        );

        $paper = ExamPaper::query()->updateOrCreate(
            ['exam_stage_id' => $stage->getKey(), 'sequence' => 1],
            [
                'name' => 'Paper I',
                'duration_minutes' => 180,
                'total_questions' => 200,
                'total_marks' => 300,
                'negative_marking' => 0,
                'question_type' => 'mcq4',
            ],
        );

        $syllabus = Syllabus::query()->updateOrCreate(
            ['exam_id' => $exam->getKey(), 'name' => 'TNPSC Group IV Syllabus'],
            [],
        );

        $syllabusVersion = SyllabusVersion::query()->updateOrCreate(
            ['syllabus_id' => $syllabus->getKey(), 'version_number' => 1],
            [
                'exam_version_id' => $version->getKey(),
                'effective_from' => '2026-01-15',
                'status' => 'active',
                'published_at' => now(),
            ],
        );

        // Paper I is 200 questions: Part A (Tamil eligibility-cum-scoring, 100)
        // plus these two. Part A is not modelled here yet.
        $subjectWeights = ['General Studies' => 0.75, 'Aptitude and Mental Ability' => 0.25];
        $subjectCounts = ['General Studies' => 75, 'Aptitude and Mental Ability' => 25];
        $sequence = 0;

        foreach (self::RENAMED_UNITS as $from => $to) {
            ExamUnit::query()
                ->where('syllabus_version_id', $syllabusVersion->getKey())
                ->where('name', $from)
                ->update(['name' => $to]);
        }

        foreach (self::SYLLABUS as $subjectName => $units) {
            $subject = ExamSubject::query()->updateOrCreate(
                ['exam_paper_id' => $paper->getKey(), 'name' => $subjectName],
                [
                    'weight' => $subjectWeights[$subjectName],
                    'question_count' => $subjectCounts[$subjectName],
                    'sequence' => $sequence++,
                ],
            );

            $unitSequence = 0;

            foreach ($units as $unitName => $topics) {
                $unit = ExamUnit::query()->updateOrCreate(
                    [
                        'syllabus_version_id' => $syllabusVersion->getKey(),
                        'exam_subject_id' => $subject->getKey(),
                        'name' => $unitName,
                    ],
                    [
                        'weight' => round($subjectWeights[$subjectName] / count($units), 4),
                        'sequence' => $unitSequence++,
                    ],
                );

                $topicSequence = 0;

                foreach ($topics as $topicName) {
                    $canonical = $this->canonicalTopic($topicName, $subjectName);
                    $path = Str::slug($unitName, '_').'.'.Str::slug($topicName, '_');

                    ExamTopic::query()->updateOrCreate(
                        [
                            'syllabus_version_id' => $syllabusVersion->getKey(),
                            'exam_unit_id' => $unit->getKey(),
                            'name' => $topicName,
                        ],
                        [
                            'canonical_topic_id' => $canonical->getKey(),
                            'path' => $path,
                            'depth' => 1,
                            'is_leaf' => true,
                            'weight' => round(1 / (count($topics) * count($units)), 4),
                            'estimated_minutes' => 45,
                            'sequence' => $topicSequence++,
                        ],
                    );
                }
            }
        }

        ExamBlueprint::query()->updateOrCreate(
            ['exam_paper_id' => $paper->getKey(), 'name' => 'TNPSC Group IV — Standard Pattern'],
            [
                'syllabus_version_id' => $syllabusVersion->getKey(),
                'total_questions' => 200,
                'duration_minutes' => 180,
                'difficulty_mix' => ['easy' => 0.40, 'medium' => 0.40, 'hard' => 0.20],
                'is_default' => true,
            ],
        );
    }

    /**
     * Canonical topics are the cross-exam join point: mapping a new exam's
     * syllabus onto these nodes is what lets it inherit the question bank.
     */
    private function canonicalTopic(string $name, string $subject): CanonicalTopic
    {
        $domain = str_contains($subject, 'Aptitude') ? 'aptitude' : 'general_studies';
        $slug = Str::slug($name);

        return CanonicalTopic::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'domain' => $domain,
                'path' => $domain.'.'.Str::slug($name, '_'),
                'depth' => 2,
            ],
        );
    }
}
