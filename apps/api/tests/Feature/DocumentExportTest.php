<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Documents\RenderDocumentAction;
use App\Domain\Tenancy\TenantContext;
use App\Models\Exam;
use App\Models\GeneratedDocument;
use App\Models\Language;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionTranslation;
use App\Models\Test as ExamTest;
use App\Models\TestQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use ZipArchive;

/**
 * The same paper has to leave as a PDF for printing, a DOCX for editing, an
 * XLSX for bulk review and JSON for the student site to import.
 *
 * The JSON one is the one another system parses rather than a person reads, so
 * it gets the most attention here: a bundle that disagrees with the printed
 * paper would mis-score real candidates and is close to invisible.
 */
final class DocumentExportTest extends TestCase
{
    use RefreshDatabase;

    private ExamTest $test;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $english = Language::byCode('en');

        TenantContext::withoutTenancy(function () use ($english): void {
            $exam = Exam::query()->create([
                'organization_id' => null, 'code' => 'EXPORT_EXAM',
                'name' => 'Export Exam', 'is_active' => true,
            ]);

            $this->test = ExamTest::query()->create([
                'organization_id' => null,
                'exam_id' => $exam->getKey(),
                'type' => 'model',
                'title' => 'Export Paper',
                'language_id' => $english->getKey(),
                'question_count' => 3,
                'total_marks' => 3,
                'duration_minutes' => 30,
                'negative_marking' => 0,
                'status' => 'ready',
            ]);

            foreach (range(1, 3) as $i) {
                $question = Question::query()->create([
                    'organization_id' => null,
                    'master_language_id' => $english->getKey(),
                    'difficulty' => 'medium',
                    'status' => 'APPROVED',
                    'origin' => 'ai_generated',
                    'current_version' => 1,
                ]);

                // The correct option differs per question, so an exporter that
                // hardcoded or mis-indexed the key would be caught.
                $correct = ['A', 'C', 'D'][$i - 1];

                foreach (['A', 'B', 'C', 'D'] as $position => $key) {
                    QuestionOption::query()->create([
                        'question_id' => $question->getKey(),
                        'option_key' => $key,
                        'position' => $position + 1,
                        'is_correct' => $key === $correct,
                    ]);
                }

                QuestionTranslation::query()->create([
                    'question_id' => $question->getKey(),
                    'language_id' => $english->getKey(),
                    'is_master' => true,
                    // Tamil in one stem: the export path must not mangle it.
                    'question_text' => "Question {$i} about தமிழ் language marker{$i}?",
                    'option_a' => "Alpha {$i}", 'option_b' => "Beta {$i}",
                    'option_c' => "Gamma {$i}", 'option_d' => "Delta {$i}",
                    'translation_status' => 'HUMAN_APPROVED',
                ]);

                TestQuestion::query()->create([
                    'test_id' => $this->test->getKey(),
                    'question_id' => $question->getKey(),
                    'question_version' => 1,
                    'sequence' => $i,
                    'marks' => 1,
                ]);
            }
        });
    }

    private function export(string $format): GeneratedDocument
    {
        return TenantContext::withoutTenancy(fn () => app(RenderDocumentAction::class)
            ->execute($this->test->fresh(['questions']), 'question_paper', null, $format));
    }

    private function contents(GeneratedDocument $document): string
    {
        return Storage::disk($document->storage_disk)->get($document->storage_path);
    }

    public function test_the_json_bundle_carries_questions_and_matching_answers(): void
    {
        $document = $this->export('json');
        $data = json_decode($this->contents($document), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('json', $document->format);
        $this->assertSame('1.0', $data['schema_version']);
        $this->assertCount(3, $data['questions']);
        $this->assertCount(3, $data['answers']);

        // The keys must be the real per-question answers, in order — not a
        // constant, and not shifted by one.
        $this->assertSame(
            ['A', 'C', 'D'],
            array_column($data['answers'], 'answer'),
        );
    }

    public function test_the_json_bundle_is_readable_as_tamil_rather_than_escaped(): void
    {
        $raw = $this->contents($this->export('json'));

        // Escaped output is valid JSON but nobody can eyeball it, and every
        // consumer has to decode before it can be inspected at all.
        $this->assertMatchesRegularExpression('/[\x{0B80}-\x{0BFF}]/u', $raw);
        $this->assertStringNotContainsString('\u0b', $raw);
    }

    public function test_a_pdf_paper_does_not_leak_its_answers(): void
    {
        $document = $this->export('pdf');

        // The printed paper is composed without the key. If the bundle path
        // ever became the default, candidates would be handed the answers.
        $this->assertArrayNotHasKey('answers', $document->render_payload);
    }

    public function test_the_xlsx_is_a_grid_a_spreadsheet_can_open(): void
    {
        $document = $this->export('xlsx');

        $path = tempnam(sys_get_temp_dir(), 'ef-test-').'.xlsx';
        file_put_contents($path, $this->contents($document));

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();

            $this->assertSame(
                ['#', 'Question', 'A', 'B', 'C', 'D', 'Answer', 'Marks'],
                $sheet->rangeToArray('A1:H1')[0],
            );

            // Header plus one row per question.
            $this->assertSame(4, $sheet->getHighestRow());
            $this->assertSame('C', $sheet->getCell('G3')->getValue());
        } finally {
            @unlink($path);
        }
    }

    public function test_the_docx_is_a_real_ooxml_package(): void
    {
        $document = $this->export('docx');

        $path = tempnam(sys_get_temp_dir(), 'ef-test-').'.docx';
        file_put_contents($path, $this->contents($document));

        try {
            $zip = new ZipArchive;

            $this->assertTrue($zip->open($path) === true, 'DOCX must open as a zip package.');

            $xml = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            $this->assertStringContainsString('Export Paper', $xml);
            $this->assertMatchesRegularExpression('/[\x{0B80}-\x{0BFF}]/u', $xml);
        } finally {
            @unlink($path);
        }
    }

    public function test_each_format_is_stored_separately_rather_than_colliding(): void
    {
        $pdf = $this->export('pdf');
        $json = $this->export('json');

        // Without the format in the content hash these would share a row, and
        // one format would be served as the other.
        $this->assertNotSame($pdf->getKey(), $json->getKey());
        $this->assertNotSame($pdf->content_hash, $json->content_hash);
        $this->assertStringEndsWith('.json', $json->storage_path);
        $this->assertStringEndsWith('.pdf', $pdf->storage_path);
    }

    public function test_re_exporting_the_same_paper_reuses_the_stored_file(): void
    {
        $first = $this->export('json');
        $second = $this->export('json');

        // Re-serving must never re-render: it is what makes downloads free.
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, GeneratedDocument::query()->where('format', 'json')->count());
    }

    public function test_an_unknown_format_is_refused_at_the_api(): void
    {
        $admin = TenantContext::withoutTenancy(function () {
            $user = \App\Models\User::query()->create([
                'name' => 'Admin', 'email' => 'export-admin@example.test',
                'password' => 'secret-pass', 'is_platform_admin' => true,
            ]);

            // Without a membership the tenant never resolves, and the request
            // is refused at the middleware long before validation runs.
            $org = \App\Models\Organization::query()->create([
                'name' => 'Export Org', 'slug' => 'export-org',
                'type' => 'individual', 'owner_user_id' => $user->getKey(),
            ]);

            \App\Models\OrganizationUser::query()->create([
                'organization_id' => $org->getKey(),
                'user_id' => $user->getKey(),
                'status' => 'active', 'is_default' => true,
            ]);

            return $user;
        });

        $this->actingAs($admin)
            ->postJson("/api/v1/tests/{$this->test->getKey()}/documents", [
                'types' => ['question_paper'],
                'format' => 'pptx',
            ])
            ->assertStatus(422);
    }
}
