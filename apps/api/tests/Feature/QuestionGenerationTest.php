<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\Contracts\LlmClient;
use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\DTOs\LlmResponse;
use App\Domain\Jobs\JobStatus;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\Generation\GenerateQuestions;
use App\Jobs\Ingestion\ProcessSource;
use App\Models\AiJob;
use App\Models\Book;
use App\Models\Language;
use App\Models\Question;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Engine 1, generation half: retrieve → generate → validate → dedupe → score.
 *
 * Runs entirely offline. The fixtures include one deliberately mis-keyed
 * question so the independent re-solve (V8) is exercised, not just asserted to
 * exist.
 */
final class QuestionGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\AiModelSeeder::class);
        $this->seed(\Database\Seeders\PromptSeeder::class);

        Storage::fake('local');
        config([
            'ingestion.ocr_driver' => 'fake',
            'embeddings.driver' => 'fake',
            'ai.driver' => 'fake',
            'ai.cache_enabled' => false,
        ]);
    }

    public function test_it_generates_validates_and_stores_cited_questions(): void
    {
        $source = $this->ingestedSource();

        $result = TenantContext::withoutTenancy(function () use ($source): AiJob {
            $job = AiJob::query()->create([
                'type' => 'generation', 'queue' => 'generate',
                'subject_type' => Source::class, 'subject_id' => $source->getKey(),
                'input' => [
                    'source_id' => $source->getKey(),
                    'language_id' => Language::byCode('en')->getKey(),
                    'target_count' => 5,
                ],
            ]);

            (new GenerateQuestions($job->getKey()))->handle();

            return $job->fresh();
        });

        $this->assertSame(JobStatus::Completed, $result->status);
        $this->assertGreaterThan(0, $result->output['raw_generated']);

        $questions = TenantContext::withoutTenancy(
            fn () => Question::query()->with(['options', 'master', 'sources'])->get()
        );

        $kept = $questions->where('status', '!=', 'REJECTED');
        $this->assertGreaterThan(0, $kept->count(), 'no questions survived the gauntlet');

        $question = $kept->first();

        // The question row itself carries no text — all renderings live in
        // question_translations, including the master language.
        $this->assertNull($question->getAttribute('question_text'));
        $this->assertNotNull($question->master);
        $this->assertTrue($question->master->is_master);

        // Exactly one correct option, stored language-neutrally.
        $this->assertSame(1, $question->options->where('is_correct', true)->count());
        $this->assertNotNull($question->correctOptionKey());

        // Every generated question must cite a real source chunk.
        $this->assertGreaterThan(0, $question->sources->count(), 'question has no citation');
        $this->assertNotNull($question->sources->first()->source_chunk_id);

        $this->assertNotNull($question->quality_score);
        $this->assertGreaterThan(0, DB::table('question_embeddings')->count());
    }

    public function test_the_independent_resolve_rejects_a_miskeyed_question(): void
    {
        $source = $this->ingestedSource();

        TenantContext::withoutTenancy(function () use ($source): void {
            $job = AiJob::query()->create([
                'type' => 'generation', 'queue' => 'generate',
                'subject_type' => Source::class, 'subject_id' => $source->getKey(),
                'input' => [
                    'source_id' => $source->getKey(),
                    'language_id' => Language::byCode('en')->getKey(),
                    'target_count' => 5,
                ],
            ]);

            (new GenerateQuestions($job->getKey()))->handle();
        });

        // Fixture Q5 claims B while the verifier independently chose A.
        // A confidently wrong answer key is the failure students never forgive.
        $mismatch = TenantContext::withoutTenancy(
            fn () => DB::table('question_rejections')->where('code', 'ANSWER_MISMATCH')->count()
        );

        $this->assertGreaterThan(0, $mismatch, 'V8 did not catch the mis-keyed question');

        // Rejected questions are retained, never deleted: they tune the
        // overgeneration factor and expose bad source pages.
        $rejected = TenantContext::withoutTenancy(
            fn () => Question::query()->where('status', 'REJECTED')->count()
        );

        $this->assertGreaterThan(0, $rejected);
    }

    public function test_it_refuses_to_generate_without_sufficient_source_coverage(): void
    {
        $source = $this->ingestedSource();

        // Strip the retrievable material: the guard must refuse rather than
        // let the model invent content for an uncovered topic.
        TenantContext::withoutTenancy(fn () => DB::table('source_chunks')
            ->where('source_id', $source->getKey())
            ->delete());

        $job = TenantContext::withoutTenancy(function () use ($source): AiJob {
            $job = AiJob::query()->create([
                'type' => 'generation', 'queue' => 'generate',
                'subject_type' => Source::class, 'subject_id' => $source->getKey(),
                'input' => [
                    'source_id' => $source->getKey(),
                    'language_id' => Language::byCode('en')->getKey(),
                    'target_count' => 5,
                ],
            ]);

            (new GenerateQuestions($job->getKey()))->handle();

            return $job->fresh();
        });

        $this->assertSame(JobStatus::Completed, $job->status);
        $this->assertSame(0, $job->output['raw_generated']);
        $this->assertNotEmpty($job->output['coverage_skipped']);

        $this->assertSame(0, TenantContext::withoutTenancy(fn () => Question::query()->count()));
    }

    public function test_the_prompt_asks_for_the_language_the_questions_are_filed_in(): void
    {
        // Record what generation asks for; the fake still answers.
        $prompts = new \ArrayObject;
        $this->app->instance(LlmClient::class, new class($this->app->make(LlmClient::class), $prompts) implements LlmClient
        {
            public function __construct(private readonly LlmClient $inner, private readonly \ArrayObject $prompts) {}

            public function complete(LlmRequest $request): LlmResponse
            {
                $this->prompts[] = [$request->operation, $request->userPrompt];

                return $this->inner->complete($request);
            }

            public function modelKey(LlmRequest $request): string
            {
                return $this->inner->modelKey($request);
            }
        });

        $source = $this->ingestedSource();

        TenantContext::withoutTenancy(function () use ($source): void {
            $job = AiJob::query()->create([
                'type' => 'generation', 'queue' => 'generate',
                'subject_type' => Source::class, 'subject_id' => $source->getKey(),
                'input' => [
                    'source_id' => $source->getKey(),
                    'language_id' => Language::byCode('ta')->getKey(),
                    'target_count' => 2,
                ],
            ]);

            (new GenerateQuestions($job->getKey()))->handle();
        });

        $generation = array_values(array_filter(
            $prompts->getArrayCopy(),
            static fn (array $p): bool => str_contains($p[1], 'multiple-choice questions for'),
        ));

        $this->assertNotEmpty($generation, 'no generation prompt was sent');
        $this->assertStringContainsString('Language: Tamil.', $generation[0][1]);
    }

    private function ingestedSource(): Source
    {
        return TenantContext::withoutTenancy(function (): Source {
            $language = Language::byCode('en');
            Storage::disk('local')->put('quarantine/ab/book.pdf', '%PDF-1.7 fake');

            $source = Source::query()->create([
                'organization_id' => null,
                'kind' => 'textbook',
                'title' => '6th Standard Science',
                'language_id' => $language->getKey(),
                'rights_basis' => 'govt_open',
                'storage_disk' => 'local',
                'storage_path' => 'quarantine/ab/book.pdf',
                'original_filename' => 'science6.pdf',
                'mime_type' => 'application/octet-stream',
                'file_size_bytes' => 14,
                'content_sha256' => hash('sha256', 'science6'),
            ]);

            Book::query()->create([
                'source_id' => $source->getKey(),
                'organization_id' => null,
                'standard' => '6',
                'subject_name' => 'Science',
                'medium_language_id' => $language->getKey(),
            ]);

            $ingest = AiJob::query()->create([
                'type' => 'ingest', 'queue' => 'ingest',
                'subject_type' => Source::class, 'subject_id' => $source->getKey(),
            ]);

            (new ProcessSource($ingest->getKey()))->handle();

            return $source->fresh();
        });
    }
}
