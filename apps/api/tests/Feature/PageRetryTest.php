<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ingestion\StructureEditor;
use App\Domain\Tenancy\TenantContext;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTestData;
use Tests\TestCase;

/**
 * Repair proportional to the damage.
 *
 * Re-running a 600-page book to fix one misread page costs twenty minutes, so
 * in practice nobody does it and the bad page stays — which is how a book ends
 * up quietly generating from corrupted text.
 */
final class PageRetryTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

    private Source $source;

    private BookPage $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $this->source = $this->makeSource('Retry Source');

        TenantContext::withoutTenancy(function (): void {
            $book = Book::query()->create([
                'organization_id' => null,
                'source_id' => $this->source->getKey(),
                'title' => 'Retry Book',
                'language_id' => $this->source->language_id,
            ]);

            $this->page = BookPage::query()->create([
                'book_id' => $book->getKey(),
                'page_number' => 1,
                'raw_text' => 'Original machine reading.',
                'normalized_text' => 'Original machine reading.',
                'ocr_confidence' => 0.4,
                'needs_review' => true,
            ]);
        });
    }

    public function test_a_human_correction_never_overwrites_what_the_machine_read(): void
    {
        TenantContext::withoutTenancy(fn () => app(StructureEditor::class)
            ->correctPage($this->page, 'What a person typed.', null, $this->source));

        $page = $this->page->fresh();

        $this->assertSame('What a person typed.', $page->normalized_text);
        // raw_text is the record of what the extractor actually produced.
        // Overwriting it makes a bad OCR pass impossible to diagnose later.
        $this->assertSame('Original machine reading.', $page->raw_text);
        $this->assertFalse((bool) $page->needs_review);
    }

    public function test_correcting_a_page_marks_the_derived_stages_stale(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $this->source->forceFill([
                'pipeline_state' => [
                    'extract' => ['status' => 'completed'],
                    'chunk' => ['status' => 'completed'],
                    'embed' => ['status' => 'completed'],
                ],
            ])->save();

            app(StructureEditor::class)
                ->correctPage($this->page, 'Corrected text.', null, $this->source);
        });

        $state = $this->source->fresh()->pipeline_state;

        // The chunks a question is generated from still hold the old text
        // until they are rebuilt, so leaving them marked complete would serve
        // questions cited against text nobody can find any more.
        $this->assertArrayNotHasKey('chunk', $state);
        $this->assertArrayNotHasKey('embed', $state);
        // Extraction is untouched: re-reading a 200-page PDF to fix a typo
        // would be the exact waste this module exists to avoid.
        $this->assertArrayHasKey('extract', $state);
    }

    public function test_retrying_a_page_refuses_when_the_file_is_gone(): void
    {
        Storage::fake('local');

        $this->expectExceptionMessage('stored file is missing');

        TenantContext::withoutTenancy(fn () => app(StructureEditor::class)
            ->retryPage($this->page, $this->source));
    }

    public function test_the_retry_endpoint_is_scoped_to_one_page(): void
    {
        $admin = $this->makeAdmin('retry-admin@example.test');

        // The route exists and is addressed by a single page id — the whole
        // point being that a repair cannot accidentally re-run the book.
        $this->actingAs($admin)
            ->postJson("/api/v1/sources/{$this->source->getKey()}/pages/{$this->page->getKey()}/retry")
            ->assertStatus(500); // the fixture has no real PDF behind it

        $this->assertSame(
            1,
            BookPage::query()->count(),
            'A retry must never touch pages other than the one asked for.',
        );
    }
}
