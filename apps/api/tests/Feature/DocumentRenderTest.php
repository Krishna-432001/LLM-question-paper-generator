<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Documents\RenderDocumentAction;
use App\Actions\Tests\AssembleTestAction;
use App\Domain\Tenancy\TenantContext;
use App\Models\Exam;
use App\Models\GeneratedDocument;
use App\Models\Language;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Question;
use App\Models\QuestionExamMapping;
use App\Models\QuestionOption;
use App\Models\QuestionSource;
use App\Models\QuestionTranslation;
use App\Models\Test;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DocumentRenderTest extends TestCase
{
    use RefreshDatabase;

    private Exam $exam;

    private Language $english;

    private Organization $org;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        Storage::fake('local');
        config(['pdf.driver' => 'fake', 'pdf.disk' => 'local']);

        $this->english = Language::byCode('en');

        TenantContext::withoutTenancy(function (): void {
            $this->exam = Exam::query()->create([
                'organization_id' => null, 'code' => 'DOC_EXAM',
                'name' => 'Document Exam', 'is_active' => true,
            ]);

            $this->student = User::query()->create([
                'name' => 'Doc', 'email' => 'doc@example.test', 'password' => 'secret-pass-1',
            ]);

            $this->org = Organization::query()->create([
                'name' => 'Doc', 'slug' => 'doc-org', 'type' => 'individual',
                'owner_user_id' => $this->student->getKey(),
            ]);

            OrganizationUser::query()->create([
                'organization_id' => $this->org->getKey(), 'user_id' => $this->student->getKey(),
                'status' => 'active', 'is_default' => true,
            ]);
        });
    }

    public function test_it_renders_a_paper_and_its_key_from_the_same_data(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $test = $this->makeTest();
            $render = app(RenderDocumentAction::class);

            $paper = $render->execute($test, 'question_paper');
            $key = $render->execute($test, 'answer_key');

            foreach ([$paper, $key] as $document) {
                $this->assertSame('ready', $document->status);
                $this->assertGreaterThan(0, $document->file_size_bytes);
                Storage::disk('local')->assertExists($document->storage_path);
                $this->assertStringStartsWith(
                    '%PDF-',
                    Storage::disk('local')->get($document->storage_path),
                );
            }

            // The key is built from question_options — the language-neutral
            // truth — so it can never disagree with the paper.
            $answers = collect($key->render_payload['answers']);
            $this->assertCount(6, $answers);
            $this->assertTrue($answers->every(fn (array $a): bool => $a['answer'] === 'B'));

            // Paper and key are distinguishable documents but share the test.
            $this->assertNotSame($paper->document_code, $key->document_code);
            $this->assertNotSame($paper->content_hash, $key->content_hash);
        });
    }

    public function test_rendering_twice_reuses_the_stored_file(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $test = $this->makeTest();
            $render = app(RenderDocumentAction::class);

            $first = $render->execute($test, 'question_paper');
            $second = $render->execute($test, 'question_paper');

            // A download must never re-render. Identical inputs resolve to the
            // same stored object, which is what makes downloads free.
            $this->assertSame($first->getKey(), $second->getKey());
            $this->assertSame(1, GeneratedDocument::query()->where('type', 'question_paper')->count());
        });
    }

    public function test_explanations_carry_the_citation(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $test = $this->makeTest(withSources: true);

            $document = app(RenderDocumentAction::class)->execute($test, 'explanations');

            $items = collect($document->render_payload['items']);

            $this->assertNotNull($items->first()['citation']);
            $this->assertNotNull($items->first()['quote']);
        });
    }

    public function test_a_paper_can_be_downloaded_over_http(): void
    {
        $test = TenantContext::withoutTenancy(fn () => $this->makeTest());

        $documents = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/tests/{$test->getKey()}/documents", [
                'types' => ['question_paper', 'answer_key'],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertCount(2, $documents);

        $response = $this->actingAs($this->student, 'sanctum')
            ->get($documents[0]['download_url']);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_document_type_a_subject_cannot_produce_is_refused(): void
    {
        $test = TenantContext::withoutTenancy(fn () => $this->makeTest());

        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/tests/{$test->getKey()}/documents", [
                'types' => ['study_plan'],
            ])
            ->assertStatus(422);
    }

    private function makeTest(bool $withSources = false): Test
    {
        for ($i = 0; $i < 6; $i++) {
            $question = Question::query()->create([
                'organization_id' => null,
                'master_language_id' => $this->english->getKey(),
                'difficulty' => 'easy',
                'status' => 'APPROVED',
                'origin' => 'human_authored',
                'quality_score' => 80 + $i,
            ]);

            foreach (['A' => false, 'B' => true, 'C' => false, 'D' => false] as $key => $correct) {
                QuestionOption::query()->create([
                    'question_id' => $question->getKey(),
                    'option_key' => $key,
                    'position' => ord($key) - 64,
                    'is_correct' => $correct,
                ]);
            }

            QuestionTranslation::query()->create([
                'question_id' => $question->getKey(),
                'language_id' => $this->english->getKey(),
                'is_master' => true,
                'question_text' => sprintf(
                    'Which %s organ regulates %s in the human body during marker%d?',
                    ['cardiac', 'renal', 'hepatic', 'neural', 'pulmonary', 'lymphatic'][$i],
                    ['circulation', 'filtration', 'metabolism', 'signalling', 'respiration', 'immunity'][$i],
                    $i,
                ),
                'option_a' => "Alpha organ {$i}", 'option_b' => "Beta organ {$i}",
                'option_c' => "Gamma organ {$i}", 'option_d' => "Delta organ {$i}",
                'explanation' => "Explanation for question {$i} about human physiology.",
                'translation_status' => 'HUMAN_APPROVED',
            ]);

            QuestionExamMapping::query()->create([
                'question_id' => $question->getKey(),
                'exam_id' => $this->exam->getKey(),
                'is_active' => true,
            ]);

            if ($withSources) {
                QuestionSource::query()->create([
                    'question_id' => $question->getKey(),
                    'source_id' => $this->fakeSourceId(),
                    'page_start' => 42,
                    'page_end' => 43,
                    'supporting_quote' => 'The heart pumps blood to all parts of the body.',
                    'is_primary' => true,
                ]);
            }
        }

        return app(AssembleTestAction::class)->execute([
            'organization_id' => $this->org->getKey(),
            'user_id' => $this->student->getKey(),
            'exam_id' => $this->exam->getKey(),
            'language_id' => $this->english->getKey(),
            'type' => 'daily',
            'question_count' => 6,
            'difficulty_mix' => ['easy' => 1.0],
            'title' => 'Daily Test 1',
            'duration_minutes' => 30,
        ]);
    }

    private function fakeSourceId(): string
    {
        static $id = null;

        return $id ??= \App\Models\Source::query()->create([
            'organization_id' => null,
            'kind' => 'textbook',
            'title' => '6th Science',
            'language_id' => $this->english->getKey(),
            'rights_basis' => 'govt_open',
            'storage_path' => 'quarantine/x/y.pdf',
            'original_filename' => 'y.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 10,
            'content_sha256' => hash('sha256', 'doc-src'),
        ])->getKey();
    }
}
