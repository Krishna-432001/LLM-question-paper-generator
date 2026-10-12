<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ingestion\StructureEditor;
use App\Domain\Tenancy\TenantContext;
use App\Models\Book;
use App\Models\BookChapter;
use App\Models\BookLesson;
use App\Models\BookPage;
use App\Models\Language;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Question;
use App\Models\QuestionSource;
use App\Models\Source;
use App\Models\SourceChunk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correcting what the machine read.
 *
 * The behaviour that matters is not that a title can be changed — it is that
 * changing one invalidates everything derived from it, and that it does NOT
 * silently rewrite the citation on a question already approved and printed.
 */
final class StructureEditorTest extends TestCase
{
    use RefreshDatabase;

    private Source $source;

    private Book $book;

    private BookChapter $chapter;

    private BookLesson $lesson;

    private User $admin;

    private Language $english;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->english = Language::byCode('en');

        TenantContext::withoutTenancy(function (): void {
            $this->admin = User::query()->create([
                'name' => 'Editor', 'email' => 'structure-admin@example.test',
                'password' => 'secret-pass', 'is_platform_admin' => true,
            ]);

            $org = Organization::query()->create([
                'name' => 'Editor Org', 'slug' => 'structure-org', 'type' => 'individual',
                'owner_user_id' => $this->admin->getKey(),
            ]);

            OrganizationUser::query()->create([
                'organization_id' => $org->getKey(),
                'user_id' => $this->admin->getKey(),
                'status' => 'active', 'is_default' => true,
            ]);

            $this->source = Source::query()->create([
                'organization_id' => $org->getKey(),
                'kind' => 'textbook', 'title' => 'Science Reader',
                'language_id' => $this->english->getKey(),
                'rights_basis' => 'govt_open', 'status' => 'ready',
                'storage_disk' => 'local', 'storage_path' => 'sources/s.pdf',
                'original_filename' => 's.pdf', 'file_size_bytes' => 2048,
                'content_sha256' => str_repeat('d', 64), 'mime_type' => 'application/pdf',
                // Every stage complete: this is what an edit has to undo.
                'pipeline_state' => array_fill_keys(Source::STAGES, ['status' => 'completed']),
            ]);

            $this->book = Book::query()->create([
                'organization_id' => $org->getKey(),
                'source_id' => $this->source->getKey(),
                'title' => 'Science Reader',
                'language_id' => $this->english->getKey(),
            ]);

            BookPage::query()->create([
                'book_id' => $this->book->getKey(),
                'page_number' => 1,
                'raw_text' => 'Th3 h3art pumps bl00d.',
                'normalized_text' => 'Th3 h3art pumps bl00d.',
                'ocr_confidence' => 0.42,
                'needs_review' => true,
            ]);

            $this->chapter = BookChapter::query()->create([
                'book_id' => $this->book->getKey(),
                'number' => '1', 'title' => 'Teh Human Body',
                'page_start' => 1, 'page_end' => 4, 'sequence' => 1,
            ]);

            $this->lesson = BookLesson::query()->create([
                'book_chapter_id' => $this->chapter->getKey(),
                'number' => '1.1', 'title' => 'Crculatory Sytem',
                'page_start' => 1, 'page_end' => 2, 'sequence' => 1,
            ]);
        });
    }

    public function test_the_tree_reports_how_much_usable_material_each_lesson_has(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $this->makeChunk('Prose about the heart.', false);
            $this->makeChunk('More prose about vessels.', false);
            $this->makeChunk('1. Answer the following.', true);
        });

        $data = $this->actingAs($this->admin)
            ->getJson("/api/v1/sources/{$this->source->getKey()}/structure")
            ->assertOk()
            ->json('data');

        $lesson = $data['chapters'][0]['lessons'][0];

        // Exercises are excluded from generation, so the usable count is the
        // one that decides whether this lesson can produce questions at all.
        $this->assertSame(2, $lesson['usable_chunks']);
        $this->assertSame(3, $lesson['total_chunks']);
        $this->assertFalse($data['needs_reprocess']);
    }

    public function test_editing_a_lesson_marks_the_derived_stages_stale(): void
    {
        $this->actingAs($this->admin)
            ->patchJson(
                "/api/v1/sources/{$this->source->getKey()}/lessons/{$this->lesson->getKey()}",
                ['title' => 'Circulatory System'],
            )
            ->assertOk();

        $source = TenantContext::withoutTenancy(fn () => $this->source->fresh());

        $this->assertSame('Circulatory System', $this->lesson->fresh()->title);

        // Chunks and embeddings describe the old tree, so they must be rebuilt.
        $this->assertNull($source->stageStatus('chunk'));
        $this->assertNull($source->stageStatus('embed'));
        // Extraction is upstream of the edit and must NOT be thrown away —
        // re-running OCR on a 200-page book because a title was fixed is the
        // kind of waste that stops people correcting anything.
        $this->assertSame('completed', $source->stageStatus('extract'));

        // isReady() is now false, so generation refuses until the rebuild runs.
        $this->assertFalse($source->isReady());
    }

    public function test_correcting_a_page_keeps_the_raw_text_the_machine_read(): void
    {
        $page = TenantContext::withoutTenancy(
            fn () => BookPage::query()->where('book_id', $this->book->getKey())->firstOrFail(),
        );

        $this->actingAs($this->admin)
            ->patchJson(
                "/api/v1/sources/{$this->source->getKey()}/pages/{$page->getKey()}",
                ['text' => 'The heart pumps blood.'],
            )
            ->assertOk()
            ->assertJsonPath('data.needs_review', false);

        $fresh = TenantContext::withoutTenancy(fn () => $page->fresh());

        $this->assertSame('The heart pumps blood.', $fresh->normalized_text);
        // The original stays, so a bad OCR pass is still diagnosable later.
        $this->assertSame('Th3 h3art pumps bl00d.', $fresh->raw_text);
        $this->assertSame($this->admin->getKey(), $fresh->reviewed_by);
    }

    public function test_a_citation_survives_the_chunks_it_came_from_being_rebuilt(): void
    {
        $citation = TenantContext::withoutTenancy(function (): QuestionSource {
            $chunk = $this->makeChunk('The heart pumps blood.', false);

            $question = Question::query()->create([
                'organization_id' => $this->source->organization_id,
                'master_language_id' => $this->english->getKey(),
                'difficulty' => 'easy', 'status' => 'APPROVED',
                'origin' => 'ai_generated', 'quality_score' => 90,
            ]);

            return QuestionSource::query()->create([
                'question_id' => $question->getKey(),
                'source_id' => $this->source->getKey(),
                'source_chunk_id' => $chunk->getKey(),
                'breadcrumb' => $chunk->breadcrumb,
                'page_start' => 1, 'page_end' => 2,
                'is_primary' => true,
            ]);
        });

        $before = $citation->citation();
        $this->assertStringContainsString('Crculatory Sytem', $before);

        // Re-chunking deletes every chunk for the source; the FK is
        // ON DELETE SET NULL, so the citation loses its live source.
        TenantContext::withoutTenancy(function (): void {
            SourceChunk::query()->where('source_id', $this->source->getKey())->delete();
        });

        $after = TenantContext::withoutTenancy(fn () => $citation->fresh());

        $this->assertNull($after->source_chunk_id);
        $this->assertSame(
            $before,
            $after->citation(),
            'A printed citation must not degrade to "Unknown source" when the book is re-chunked.',
        );
    }

    public function test_a_section_cannot_end_before_it_begins(): void
    {
        $this->actingAs($this->admin)
            ->patchJson(
                "/api/v1/sources/{$this->source->getKey()}/lessons/{$this->lesson->getKey()}",
                ['page_start' => 9, 'page_end' => 3],
            )
            ->assertStatus(422);
    }

    public function test_a_lesson_cannot_be_moved_into_another_book(): void
    {
        $foreignChapter = TenantContext::withoutTenancy(function (): BookChapter {
            // books.source_id is unique - one book per upload - so a second
            // book needs a second source.
            $otherSource = Source::query()->create([
                'organization_id' => $this->source->organization_id,
                'kind' => 'textbook', 'title' => 'Another Reader',
                'language_id' => $this->english->getKey(),
                'rights_basis' => 'govt_open', 'status' => 'ready',
                'storage_disk' => 'local', 'storage_path' => 'sources/other.pdf',
                'original_filename' => 'other.pdf', 'file_size_bytes' => 2048,
                'content_sha256' => str_repeat('e', 64), 'mime_type' => 'application/pdf',
            ]);

            $otherBook = Book::query()->create([
                'organization_id' => $this->source->organization_id,
                'source_id' => $otherSource->getKey(),
                'title' => 'Another Reader',
                'language_id' => $this->english->getKey(),
            ]);

            return BookChapter::query()->create([
                'book_id' => $otherBook->getKey(),
                'number' => '1', 'title' => 'Elsewhere',
                'page_start' => 1, 'page_end' => 2, 'sequence' => 1,
            ]);
        });

        $this->expectExceptionMessage('cannot be moved into a different book');

        TenantContext::withoutTenancy(function () use ($foreignChapter): void {
            app(StructureEditor::class)->updateLesson(
                $this->lesson,
                ['book_chapter_id' => $foreignChapter->getKey()],
                $this->source,
            );
        });
    }

    private function makeChunk(string $text, bool $isExercise): SourceChunk
    {
        return SourceChunk::query()->create([
            'source_id' => $this->source->getKey(),
            'book_id' => $this->book->getKey(),
            'book_lesson_id' => $this->lesson->getKey(),
            'chunk_index' => SourceChunk::query()->where('source_id', $this->source->getKey())->count(),
            'chunk_type' => 'prose',
            'breadcrumb' => 'Science Reader › Teh Human Body › Crculatory Sytem › pp.1-2',
            'text' => $text,
            'token_count' => 40,
            'page_start' => 1,
            'page_end' => 2,
            'language_id' => $this->english->getKey(),
            'is_exercise' => $isExercise,
            'content_hash' => hash('sha256', $text),
        ]);
    }
}
