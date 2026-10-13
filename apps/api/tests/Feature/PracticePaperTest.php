<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ingestion\Contracts\OcrEngine;
use App\Domain\Ingestion\Contracts\VirusScanner;
use App\Domain\Ingestion\DTOs\ExtractedPage;
use App\Domain\Jobs\JobStatus;
use App\Domain\PracticePapers\Contracts\PaperTextLayer;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Antivirus\FakeVirusScanner;
use App\Jobs\PracticePapers\OcrPracticePaper;
use App\Models\AiJob;
use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\Language;
use App\Models\OrganizationUser;
use App\Models\Question;
use App\Models\QuestionExamMapping;
use App\Models\Test;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\MakesTestData;
use Tests\TestCase;

/**
 * Upload a question paper, sit it against the clock, see the score.
 *
 * pdftotext and Tesseract are never needed: the text layer and the OCR engine
 * are replaced with stand-ins that return known pages, so what is tested is
 * everything after extraction — the scan-before-read order, the parse, the
 * paper order, the scoring of keyed and unkeyed questions, and who may see it.
 */
final class PracticePaperTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

    private const ENGLISH_PAPER = <<<'TXT'
    TNPSC GROUP IV - GENERAL STUDIES

    1. Which Article of the Constitution guarantees the Right to Equality?
    (A) Article 14 (B) Article 19 (C) Article 21 (D) Article 32

    2. Who is the head of the Union Executive in India?
    (A) The Prime Minister (B) The President (C) The Chief Justice (D) The Speaker

    3. Which planet is closest to the Sun?
    (A) Venus (B) Mercury (C) Mars (D) Earth

    4. Which gas do plants absorb during photosynthesis?
    (A) Oxygen (B) Nitrogen (C) Carbon dioxide (D) Helium

    5. Who wrote Silappathikaram?
    (A) Ilango Adigal (B) Kambar (C) Valluvar (D) Bharathi
    TXT;

    private const ANSWER_KEY_PAGE = "ANSWER KEY\n1 - A   2 - B   3 - B   4 - C   5 - A";

    private User $student;

    private Exam $exam;

    /** @var list<string> paths the stand-in text layer was asked to read */
    private array $readPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        Storage::fake('local');
        config(['ingestion.antivirus.driver' => 'fake']);
        $this->app->forgetInstance(VirusScanner::class);

        $this->student = $this->makeAdmin('paper-student@example.test', 'student', platformAdmin: false);
        $this->exam = $this->makeExam('PAPER_EXAM');
    }

    // ------------------------------------------------------------- stand-ins

    private function textLayer(string ...$pages): void
    {
        $record = function (string $path): void {
            $this->readPaths[] = str_replace('\\', '/', $path);
        };

        $this->app->instance(PaperTextLayer::class, new class(array_values($pages), $record) implements PaperTextLayer
        {
            /** @param  list<string>  $pages */
            public function __construct(private readonly array $pages, private readonly \Closure $record) {}

            public function isAvailable(): bool
            {
                return true;
            }

            public function readings(string $absolutePath): array
            {
                ($this->record)($absolutePath);

                return ['layout' => $this->pages];
            }
        });
    }

    private function ocrEngine(bool $available, string ...$pages): void
    {
        $this->app->instance(OcrEngine::class, new class($available, array_values($pages)) implements OcrEngine
        {
            /** @param  list<string>  $pages */
            public function __construct(private readonly bool $available, private readonly array $pages) {}

            public function name(): string
            {
                return 'stand-in';
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function extract(string $absolutePath, array $languageHints = ['en']): array
            {
                return array_map(
                    static fn (string $text, int $i): ExtractedPage => new ExtractedPage($i + 1, $text, 0.95, 'stand-in'),
                    $this->pages,
                    array_keys($this->pages),
                );
            }
        });
    }

    /** @param  array<string, mixed>  $fields */
    private function upload(array $fields = [], ?string $content = null, ?User $as = null): TestResponse
    {
        $file = UploadedFile::fake()->createWithContent(
            'paper.pdf',
            $content ?? '%PDF-1.7 practice paper '.bin2hex(random_bytes(6)),
        );

        return $this->actingAs($as ?? $this->student)->post(
            '/api/v1/practice-papers',
            ['file' => $file, 'title' => 'Group IV 2022'] + $fields,
            ['Accept' => 'application/json'],
        );
    }

    /** @return array<string, mixed> the attempt's paper */
    private function start(string $testId, ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->student)
            ->postJson("/api/v1/tests/{$testId}/attempt")
            ->assertCreated()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $attempt
     * @param  array<int, string|null>  $choices  sequence => option
     * @return array<string, mixed>
     */
    private function submit(array $attempt, array $choices): array
    {
        $answers = [];

        foreach ($attempt['questions'] as $question) {
            $answers[] = [
                'test_question_id' => $question['test_question_id'],
                'selected_option' => $choices[$question['sequence']] ?? null,
                'time_spent_seconds' => 10,
            ];
        }

        return $this->actingAs($this->student)
            ->postJson("/api/v1/attempts/{$attempt['attempt_id']}/submit", ['answers' => $answers])
            ->assertOk()
            ->json('data');
    }

    private function otherStudent(): User
    {
        return TenantContext::withoutTenancy(function (): User {
            $user = User::query()->create([
                'name' => 'Other', 'email' => 'other-paper@example.test', 'password' => 'secret-pass',
            ]);
            // Same organization, so tenant scope alone would let them through.
            $orgId = OrganizationUser::query()->where('user_id', $this->student->getKey())->value('organization_id');

            OrganizationUser::query()->create([
                'organization_id' => $orgId, 'user_id' => $user->getKey(), 'status' => 'active', 'is_default' => true,
            ]);
            app(PermissionRegistrar::class)->setPermissionsTeamId($orgId);
            $user->assignRole('student');

            return $user;
        });
    }

    // ----------------------------------------------------------------- tests

    public function test_an_english_paper_with_a_key_becomes_a_timed_test_in_paper_order_and_is_scored(): void
    {
        $this->textLayer(self::ENGLISH_PAPER, self::ANSWER_KEY_PAGE);

        $report = $this->upload(['duration_minutes' => 7])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Group IV 2022')
            ->assertJsonPath('data.parsed', 5)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('data.keyed', 5)
            ->assertJsonPath('data.pages', 2)
            ->assertJsonPath('data.languages_detected', ['en'])
            ->assertJsonPath('data.duration_minutes', 7)
            ->json('data');

        $this->assertSame([], $report['warnings']);

        // Read only after the scan promoted it out of quarantine.
        $this->assertCount(1, $this->readPaths);
        $this->assertStringContainsString('/sources/', $this->readPaths[0]);
        $this->assertStringNotContainsString('/quarantine/', $this->readPaths[0]);

        $attempt = $this->start($report['test_id']);

        // The printed order, not a selection engine's order.
        $this->assertSame([1, 2, 3, 4, 5], array_column($attempt['questions'], 'sequence'));
        $this->assertSame(
            'Which Article of the Constitution guarantees the Right to Equality?',
            $attempt['questions'][0]['question_text'],
        );
        $this->assertSame('Who wrote Silappathikaram?', $attempt['questions'][4]['question_text']);
        $this->assertSame('Carbon dioxide', $attempt['questions'][3]['options']['C']);
        $this->assertArrayNotHasKey('correct_option', $attempt['questions'][0]);
        $this->assertNotNull($attempt['expires_at'], 'A practice paper is timed.');

        // Right on 1-3, wrong on 4, 5 left blank.
        $result = $this->submit($attempt, [1 => 'A', 2 => 'B', 3 => 'B', 4 => 'A']);

        $this->assertSame(3, $result['correct']);
        $this->assertSame(1, $result['wrong']);
        $this->assertEquals(3.0, $result['final_score']);

        $this->actingAs($this->student)
            ->getJson("/api/v1/attempts/{$attempt['attempt_id']}")
            ->assertJsonPath('data.result.unattempted', 1);

        $solutions = $this->actingAs($this->student)
            ->getJson("/api/v1/results/{$attempt['attempt_id']}/solutions")
            ->assertOk()
            ->json('data');

        $this->assertSame(['A', 'B', 'B', 'C', 'A'], array_column($solutions, 'correct_option'));
        $this->assertSame('Group IV 2022 — Q1', $solutions[0]['sources'][0]['citation']);

        // Private, and never in reach of a generated paper.
        TenantContext::withoutTenancy(function () use ($report): void {
            $questions = Question::query()->where('origin', 'previous_paper')->get();
            $orgId = OrganizationUser::query()->where('user_id', $this->student->getKey())->value('organization_id');

            $this->assertCount(5, $questions);
            $this->assertTrue($questions->every(fn (Question $q): bool => $q->organization_id === $orgId));
            $this->assertTrue($questions->every(fn (Question $q): bool => $q->status === 'APPROVED'));
            $this->assertSame(0, QuestionExamMapping::query()->count());
            $this->assertSame('custom', Test::query()->findOrFail($report['test_id'])->type);
            $this->assertNotNull(AuditLog::query()->where('action', 'practice_paper.created')->first());
        });

        $this->actingAs($this->student)
            ->getJson('/api/v1/practice-papers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.test_id', $report['test_id'])
            ->assertJsonPath('data.0.question_count', 5)
            ->assertJsonPath('data.0.keyed', 5)
            ->assertJsonPath('data.0.attempts', 1)
            ->assertJsonPath('data.0.best_score', fn ($score): bool => (float) $score === 3.0);
    }

    public function test_the_time_limit_defaults_to_a_minute_a_question(): void
    {
        $this->textLayer(self::ENGLISH_PAPER);

        $this->upload()->assertCreated()->assertJsonPath('data.duration_minutes', 5);
    }

    public function test_tamil_labelled_options_are_read_and_the_test_is_in_tamil(): void
    {
        $this->textLayer(<<<'TXT'
        1. தமிழ் மொழியின் தொன்மையை உணர்த்தும் நூல் எது?
        அ) தொல்காப்பியம்
        ஆ) திருக்குறள்
        இ) சிலப்பதிகாரம்
        ஈ) மணிமேகலை

        2. இந்தியாவின் தேசிய விலங்கு எது?
        அ) சிங்கம்
        ஆ) புலி
        இ) யானை
        ஈ) மயில்
        TXT);

        $report = $this->upload()
            ->assertCreated()
            ->assertJsonPath('data.parsed', 2)
            ->assertJsonPath('data.languages_detected', ['ta'])
            ->assertJsonPath('data.language', 'ta')
            ->json('data');

        $attempt = $this->start($report['test_id']);

        $this->assertSame('தமிழ் மொழியின் தொன்மையை உணர்த்தும் நூல் எது?', $attempt['questions'][0]['question_text']);
        $this->assertSame(
            ['A' => 'தொல்காப்பியம்', 'B' => 'திருக்குறள்', 'C' => 'சிலப்பதிகாரம்', 'D' => 'மணிமேகலை'],
            $attempt['questions'][0]['options'],
        );

        $this->assertSame(
            Language::byCode('ta')->getKey(),
            TenantContext::withoutTenancy(fn () => Test::query()->findOrFail($report['test_id'])->language_id),
        );
    }

    public function test_a_bilingual_block_is_stored_as_two_translations_and_sat_in_the_chosen_language(): void
    {
        $this->textLayer(<<<'TXT'
        1. Which is the smallest planet?
        சூரியக் குடும்பத்தின் மிகச்சிறிய கோள் எது?
        (A) Mercury (B) Mars (C) Venus (D) Earth
        (A) புதன் (B) செவ்வாய் (C) வெள்ளி (D) பூமி

        2. In which year did India become a Republic?
        இந்தியா எந்த ஆண்டு குடியரசானது?
        (A) 1947 (B) 1950 (C) 1952 (D) 1962
        TXT);

        $report = $this->upload(['language' => 'ta'])
            ->assertCreated()
            ->assertJsonPath('data.parsed', 2)
            ->assertJsonPath('data.languages_detected', ['en', 'ta'])
            ->assertJsonPath('data.language', 'ta')
            ->json('data');

        TenantContext::withoutTenancy(function (): void {
            $question = Question::query()->where('origin', 'previous_paper')->with('translations')->oldest('created_at')->get()
                ->first(fn (Question $q): bool => $q->translations->contains('question_text', 'Which is the smallest planet?'));

            $this->assertNotNull($question);
            $this->assertCount(2, $question->translations);

            $english = $question->translations->firstWhere('language_id', Language::byCode('en')->getKey());
            $tamil = $question->translations->firstWhere('language_id', Language::byCode('ta')->getKey());

            $this->assertTrue($english->is_master);
            $this->assertSame(Language::byCode('en')->getKey(), $question->master_language_id);
            $this->assertFalse($tamil->is_master);
            $this->assertSame('HUMAN_APPROVED', $tamil->translation_status);
            $this->assertSame('சூரியக் குடும்பத்தின் மிகச்சிறிய கோள் எது?', $tamil->question_text);
            $this->assertSame('புதன்', $tamil->option_a);
        });

        $attempt = $this->start($report['test_id']);

        $this->assertSame('சூரியக் குடும்பத்தின் மிகச்சிறிய கோள் எது?', $attempt['questions'][0]['question_text']);
        $this->assertSame('பூமி', $attempt['questions'][0]['options']['D']);
        $this->assertSame('இந்தியா எந்த ஆண்டு குடியரசானது?', $attempt['questions'][1]['question_text']);
    }

    public function test_without_an_answer_key_the_paper_is_sittable_and_nothing_is_marked_wrong(): void
    {
        $this->textLayer(self::ENGLISH_PAPER);

        $report = $this->upload()
            ->assertCreated()
            ->assertJsonPath('data.keyed', 0)
            ->json('data');

        $this->assertStringContainsString('No answer key', implode(' ', $report['warnings']));

        // The commit-time trigger, run now: a printed paper with no key yet is
        // allowed to have no correct option.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $attempt = $this->start($report['test_id']);
        $result = $this->submit($attempt, [1 => 'A', 2 => 'C', 3 => 'B', 4 => 'D', 5 => 'A']);

        $this->assertSame(0, $result['correct']);
        $this->assertSame(0, $result['wrong'], 'An unkeyed question is unscored, never wrong.');
        $this->assertEquals(0.0, $result['final_score']);

        $this->actingAs($this->student)
            ->getJson("/api/v1/attempts/{$attempt['attempt_id']}")
            ->assertJsonPath('data.result.attempted', 0)
            ->assertJsonPath('data.result.unattempted', 0);

        $this->actingAs($this->student)
            ->getJson("/api/v1/practice-papers/attempts/{$attempt['attempt_id']}/scoring")
            ->assertOk()
            ->assertJsonPath('data.unscored', 5)
            ->assertJsonPath('data.scored', 0);

        // The choices themselves are kept, unmarked.
        $answers = DB::table('test_answers')->where('test_attempt_id', $attempt['attempt_id'])->get();
        $this->assertCount(5, $answers);
        $this->assertTrue($answers->every(fn ($a): bool => $a->is_correct === null && $a->selected_option !== null));
    }

    public function test_a_bank_question_still_needs_exactly_one_correct_option(): void
    {
        $question = $this->makeQuestion(1, 'APPROVED');

        TenantContext::withoutTenancy(
            fn () => DB::table('question_options')->where('question_id', $question->getKey())->update(['is_correct' => false])
        );

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('must have exactly one correct option');

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_a_key_added_later_scores_new_attempts_and_leaves_earlier_ones_alone(): void
    {
        // Question 3 prints only three options, so it is skipped and the
        // paper's question 4 becomes the test's third question.
        $this->textLayer(<<<'TXT'
        1. Which Article of the Constitution guarantees the Right to Equality?
        (A) Article 14 (B) Article 19 (C) Article 21 (D) Article 32
        2. Who is the head of the Union Executive in India?
        (A) The Prime Minister (B) The President (C) The Chief Justice (D) The Speaker
        3. This question lost an option in printing.
        (A) One (B) Two (C) Three
        4. Which gas do plants absorb during photosynthesis?
        (A) Oxygen (B) Nitrogen (C) Carbon dioxide (D) Helium
        5. Who wrote Silappathikaram?
        (A) Ilango Adigal (B) Kambar (C) Valluvar (D) Bharathi
        TXT);

        $report = $this->upload()
            ->assertCreated()
            ->assertJsonPath('data.parsed', 4)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.keyed', 0)
            ->json('data');

        $this->assertStringContainsString('numbers 3', implode(' ', $report['warnings']));
        $testId = $report['test_id'];

        $first = $this->start($testId);
        $this->submit($first, [1 => 'A', 2 => 'B', 3 => 'C', 4 => 'A']);

        $this->actingAs($this->student)
            ->postJson("/api/v1/practice-papers/{$testId}/answer-key", ['key' => '1-A 2-B 3-C 4-C 5-A'])
            ->assertOk()
            ->assertJsonPath('data.updated', 4)
            ->assertJsonPath('data.unmatched', [3])
            ->assertJsonPath('data.keyed', 4)
            ->assertJsonPath('data.rescored', false)
            ->assertJsonPath('data.message', fn (string $m): bool => str_contains($m, 'not re-scored'));

        // A correction sent as a map: question 5 is actually B.
        $this->actingAs($this->student)
            ->postJson("/api/v1/practice-papers/{$testId}/answer-key", ['key' => ['5' => 'b']])
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        // The earlier attempt keeps the result it was given.
        $this->actingAs($this->student)
            ->getJson("/api/v1/attempts/{$first['attempt_id']}")
            ->assertJsonPath('data.result.correct', 0);
        $this->actingAs($this->student)
            ->getJson("/api/v1/practice-papers/attempts/{$first['attempt_id']}/scoring")
            ->assertJsonPath('data.unscored', 4);

        // A new one is marked: sequence 3 is the paper's question 4 (key C).
        $second = $this->start($testId);
        $result = $this->submit($second, [1 => 'A', 2 => 'B', 3 => 'C', 4 => 'A']);

        $this->assertSame(3, $result['correct']);
        $this->assertSame(1, $result['wrong'], 'Question 5 was corrected to B.');

        $this->actingAs($this->student)
            ->getJson('/api/v1/practice-papers')
            ->assertJsonPath('data.0.keyed', 4)
            ->assertJsonPath('data.0.attempts', 2);

        $this->assertNotNull(TenantContext::withoutTenancy(
            fn () => AuditLog::query()->where('action', 'practice_paper.answer_key_set')->first()
        ));

        $this->actingAs($this->student)
            ->postJson("/api/v1/practice-papers/{$testId}/answer-key", ['key' => 'no answers here'])
            ->assertStatus(422);
    }

    public function test_a_scanned_paper_is_read_by_ocr_on_the_queue(): void
    {
        Queue::fake();
        $this->textLayer('', '');
        $this->ocrEngine(true, self::ENGLISH_PAPER, self::ANSWER_KEY_PAGE);

        $jobId = $this->upload()
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->json('data.job_id');

        Queue::assertPushedOn('ocr', OcrPracticePaper::class);

        $job = TenantContext::withoutTenancy(function () use ($jobId): AiJob {
            (new OcrPracticePaper($jobId))->handle();

            return AiJob::query()->findOrFail($jobId);
        });

        $this->assertSame(JobStatus::Completed, $job->status);
        $this->assertSame('ocr', $job->type);
        $this->assertSame(5, $job->output['parsed']);
        $this->assertSame(5, $job->output['keyed']);
        $this->assertTrue($job->output['ocr']);

        $testId = $this->actingAs($this->student)
            ->getJson("/api/v1/jobs/{$jobId}")
            ->assertOk()
            ->json('data.output.test_id');

        $attempt = $this->start($testId);
        $this->assertCount(5, $attempt['questions']);
    }

    public function test_a_scan_with_no_ocr_engine_is_refused_with_a_reason(): void
    {
        $this->textLayer('', '');
        $this->ocrEngine(false);

        $this->upload()
            ->assertStatus(422)
            ->assertJsonPath('detail', fn (string $d): bool => str_contains($d, 'no text layer'));

        $this->assertSame(0, TenantContext::withoutTenancy(fn () => Test::query()->count()));
    }

    public function test_a_pdf_with_text_but_no_questions_is_refused(): void
    {
        $this->textLayer(str_repeat('This is a circular about office hours, not a question paper. ', 10));

        $this->upload()
            ->assertStatus(422)
            ->assertJsonPath('detail', fn (string $d): bool => str_contains($d, 'No multiple-choice questions'));
    }

    public function test_an_infected_upload_is_refused_before_it_is_read(): void
    {
        $this->textLayer(self::ENGLISH_PAPER);

        $this->upload(content: '%PDF-1.7 '.FakeVirusScanner::MARKER)
            ->assertStatus(422)
            ->assertJsonPath('title', 'File failed the malware scan');

        $this->assertSame([], $this->readPaths, 'Nothing may read a quarantined file.');
        $this->assertSame(0, TenantContext::withoutTenancy(fn () => Test::query()->count()));
    }

    public function test_only_pdfs_are_accepted(): void
    {
        $this->actingAs($this->student)
            ->post('/api/v1/practice-papers', [
                'file' => UploadedFile::fake()->createWithContent('paper.txt', 'plain text'),
                'title' => 'Not a PDF',
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_another_student_cannot_see_or_use_the_paper(): void
    {
        $this->textLayer(self::ENGLISH_PAPER);
        $testId = $this->upload()->assertCreated()->json('data.test_id');
        $attempt = $this->start($testId);
        $this->submit($attempt, [1 => 'A']);

        $other = $this->otherStudent();

        $this->actingAs($other)->getJson('/api/v1/practice-papers')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($other)
            ->postJson("/api/v1/practice-papers/{$testId}/answer-key", ['key' => '1-B'])
            ->assertNotFound();
        $this->actingAs($other)->getJson("/api/v1/tests/{$testId}")->assertNotFound();
        $this->actingAs($other)->postJson("/api/v1/tests/{$testId}/attempt")->assertNotFound();
        $this->actingAs($other)
            ->getJson("/api/v1/practice-papers/attempts/{$attempt['attempt_id']}/scoring")
            ->assertNotFound();
        $this->actingAs($other)->postJson('/api/v1/practice-papers/not-a-uuid/answer-key', ['key' => '1-A'])->assertNotFound();

        // A platform admin may fix the key on anyone's paper.
        $admin = $this->makeAdmin('platform@example.test');
        $this->actingAs($admin)
            ->postJson("/api/v1/practice-papers/{$testId}/answer-key", ['key' => '1-A'])
            ->assertOk()
            ->assertJsonPath('data.updated', 1);
    }

    public function test_a_named_exam_must_be_one_that_exists(): void
    {
        $this->textLayer(self::ENGLISH_PAPER);

        $this->upload(['exam_id' => '0190f3a2-0000-7000-8000-000000000000'])
            ->assertStatus(422)
            ->assertJsonPath('detail', 'That exam does not exist or is not active.');
    }
}
