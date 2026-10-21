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
        // Part A, from the official scheme (revised 27.01.2022): three
        // sections, 100 questions in Tamil. The scheme sets no per-section
        // count, so TnpscBlueprintSeeder splits them evenly.
        'Part A — Tamil Eligibility-cum-Scoring Test' => [
            'Part A I — இலக்கணம் · Tamil Grammar' => [
                'Tamil Grammar: Word Meaning and Matching',
                'Tamil Grammar: Word Splitting, Joining and Root Words',
                'Tamil Grammar: Error Correction and Sentence Types',
                'Tamil Grammar: Antonyms and English Equivalents',
                'Tamil Proverbs, Similes and Prosody',
            ],
            'Part A II — இலக்கியம் · Tamil Literature' => [
                'Thirukkural: Chapters and Couplets',
                'Tamil Ethical Literature',
                'Sangam Literature',
                'Tamil Epics and Kambaramayanam',
                'Tamil Devotional and Minor Literature',
                'Folk Songs, Siddhar Songs and Religious Pioneers',
            ],
            'Part A III — தமிழ் அறிஞர்களும் தமிழ்த் தொண்டும் · Tamil Scholars and Contributions' => [
                'Modern Tamil Poets',
                'Tamil Prose, Letters and Short Stories',
                'Tamil Scholars and Their Service to Tamil',
                'Social Reformers and Women of Tamil Nadu',
                'Antiquity of Tamil, Arts and Heritage',
            ],
        ],
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
                'World History',
                'Art, Culture and Heritage',
                'Medieval India',
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
     * Words State Board lessons are titled with, per canonical topic. The
     * lesson mapper matches these against a lesson's own title only, so
     * "Plants and Photosynthesis" files under botany without a model call.
     * Kept to lesson-title vocabulary: a body-text match needs the topic's
     * own distinctive phrases.
     */
    private const ALIASES = [
        'Scientific Knowledge and Scientific Temper' => ['scientific method', 'scientists', 'inventions', 'measurement', 'measurements'],
        'Physics: Force, Motion and Energy' => ['force', 'motion', 'energy', 'light', 'sound', 'heat', 'electricity', 'magnetism', 'gravitation', 'pressure', 'work and energy', 'simple machines', 'electric current', 'optics', 'ஒளி', 'ஒலி', 'வெப்பம்', 'மின்னியல்', 'விசை', 'இயக்கம்'],
        'Chemistry: Elements and Compounds' => ['matter', 'atoms', 'molecules', 'acids', 'bases', 'salts', 'chemical reactions', 'metals', 'periodic table', 'states of matter', 'water and its states', 'mixtures', 'carbon', 'polymers', 'fibres', 'பருப்பொருள்', 'அமிலங்கள்', 'தனிமங்கள்'],
        'Botany: Plant Kingdom' => ['plants', 'plant', 'photosynthesis', 'plant cell', 'plant tissues', 'transpiration', 'seeds', 'flowers', 'leaf', 'தாவரங்கள்', 'ஒளிச்சேர்க்கை'],
        'Zoology: Human Physiology' => ['human body', 'human skeleton', 'skeleton', 'digestive system', 'respiration', 'circulation', 'nervous system', 'blood', 'heart', 'animals', 'cell', 'tissues', 'health and hygiene', 'diseases', 'microorganisms', 'heredity', 'nutrition', 'மனித உடல்', 'விலங்குகள்', 'நோய்கள்'],
        'Physical Features and Climate' => ['solar system', 'universe', 'earth', 'planets', 'atmosphere', 'climate', 'weather', 'landforms', 'rocks', 'soil', 'rivers', 'oceans', 'continents', 'monsoon', 'indian geography', 'physical features', 'lithosphere', 'hydrosphere', 'புவி', 'சூரியக் குடும்பம்', 'காலநிலை'],
        'Natural Resources and Agriculture' => ['resources', 'natural resources', 'agriculture', 'forests', 'minerals', 'crops', 'irrigation', 'water resources', 'வேளாண்மை', 'வளங்கள்'],
        'Transport and Communication' => ['transport', 'communication', 'population', 'settlements', 'industries', 'trade', 'போக்குவரத்து'],
        'Disaster Management' => ['disasters', 'disaster management', 'floods', 'earthquakes', 'cyclones', 'பேரிடர்'],
        'Indus Valley Civilization' => ['indus valley', 'harappa', 'harappan civilization', 'early man', 'stone age', 'prehistoric', 'சிந்துவெளி'],
        'Mauryan and Gupta Empires' => ['mauryas', 'guptas', 'ancient india', 'vedic period', 'jainism', 'buddhism', 'empires', 'kingdoms'],
        'World History' => ['world war', 'league of nations', 'united nations', 'cold war', 'russian revolution', 'fascism', 'nazism', 'உலக வரலாறு', 'உலகப் போர்'],
        'Indian National Movement' => ['national movement', 'freedom struggle', 'british rule', 'independence', 'colonialism', 'revolt of 1857', 'விடுதலைப் போராட்டம்'],
        'Art, Culture and Heritage' => ['art', 'architecture', 'culture', 'heritage', 'temples', 'festivals', 'பண்பாடு'],
        'Medieval India' => ['medieval india', 'delhi sultanate', 'delhi sultans', 'mughals', 'mughal empire', 'marathas', 'vijayanagar', 'bahmani', 'rajputs', 'இடைக்கால இந்தியா', 'டெல்லி சுல்தான்கள்', 'முகலாயர்'],
        'Constitution of India' => ['constitution', 'preamble', 'democracy', 'citizenship', 'அரசியலமைப்பு', 'மக்களாட்சி'],
        'Fundamental Rights and Duties' => ['rights', 'fundamental rights', 'duties', 'human rights', 'உரிமைகள்'],
        'Union and State Executive' => ['parliament', 'president', 'prime minister', 'state government', 'judiciary', 'legislature', 'executive', 'elections', 'தேர்தல்'],
        'Local Self Government' => ['panchayat', 'panchayat raj', 'local government', 'local bodies', 'municipality', 'உள்ளாட்சி'],
        'Nature of Indian Economy and Planning' => ['economy', 'economics', 'planning', 'money', 'banking', 'taxation', 'budget', 'five year plans', 'பொருளாதாரம்'],
        'Social Justice and Welfare Schemes' => ['welfare schemes', 'social justice', 'reservation'],
        'Education and Health in Tamil Nadu' => ['education', 'health', 'public health'],
        'e-Governance in Tamil Nadu' => ['e governance', 'digital india'],
        'History of Tamil Society and Archaeological Discoveries' => ['tamil society', 'sangam age', 'keezhadi', 'archaeology', 'சங்க காலம்', 'கீழடி'],
        'Thirukkural and Tamil Literature' => ['thirukkural', 'திருக்குறள்'],
        'Role of Tamil Nadu in the Freedom Struggle' => ['freedom struggle in tamil nadu', 'vellore mutiny', 'poligars'],
        'Socio-Political Movements in Tamil Nadu' => ['dravidian movement', 'self respect movement', 'social reformers', 'periyar', 'justice party', 'சுயமரியாதை'],
        'Tamil Grammar: Word Meaning and Matching' => ['இலக்கணம்', 'grammar'],
        'Sangam Literature' => ['சங்க இலக்கியம்', 'புறநானூறு', 'அகநானூறு'],
        'Tamil Epics and Kambaramayanam' => ['சிலப்பதிகாரம்', 'மணிமேகலை', 'கம்பராமாயணம்'],
        'Modern Tamil Poets' => ['பாரதியார்', 'பாரதிதாசன்', 'கவிதை'],
        'Tamil Scholars and Their Service to Tamil' => ['தமிழ் அறிஞர்கள்', 'தமிழ்த் தொண்டு'],
        'Simplification and Percentage' => ['simplification', 'percentage', 'fractions', 'decimals', 'numbers'],
        'Ratio, Proportion and Averages' => ['ratio', 'proportion', 'averages', 'profit and loss', 'interest'],
        'Time, Work and Distance' => ['time and work', 'time and distance', 'speed'],
        'Logical Reasoning and Series' => ['reasoning', 'series', 'puzzles', 'number series'],
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

        // Paper I is 200 questions: Part A (Tamil, 100), General Studies (75)
        // and Aptitude (25). Weights are shares of the whole paper.
        $partA = 'Part A — Tamil Eligibility-cum-Scoring Test';
        $subjectWeights = [$partA => 0.50, 'General Studies' => 0.375, 'Aptitude and Mental Ability' => 0.125];
        $subjectCounts = [$partA => 100, 'General Studies' => 75, 'Aptitude and Mental Ability' => 25];
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
        $domain = match (true) {
            str_contains($subject, 'Aptitude') => 'aptitude',
            str_contains($subject, 'Tamil') => 'language',
            default => 'general_studies',
        };
        $slug = Str::slug($name);

        return CanonicalTopic::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'domain' => $domain,
                'path' => $domain.'.'.Str::slug($name, '_'),
                'depth' => 2,
                'aliases' => self::ALIASES[$name] ?? [],
            ],
        );
    }
}
