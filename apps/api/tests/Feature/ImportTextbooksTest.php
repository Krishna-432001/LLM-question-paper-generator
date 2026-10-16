<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Jobs\Ingestion\ProcessSource;
use App\Models\Book;
use App\Models\Language;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** books:import — a folder of downloaded textbooks registered in place. */
final class ImportTextbooksTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        config(['ingestion.antivirus.driver' => 'fake']);

        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/examforge-books-'.bin2hex(random_bytes(4));
        config(['filesystems.disks.textbooks.root' => $this->root]);
        Storage::forgetDisk('textbooks');

        $books = [
            // [class, medium, subject, term, status, file]
            ['9', 'English', 'Science', 'Term-Full', 'downloaded', 'books\Std-09\English\Std 09 - Science - Term-Full - English.pdf'],
            ['9', 'Tamil', 'Tamil', 'Term-Full', 'downloaded', 'books\Std-09\Tamil\Std 09 - Tamil - Term-Full - Tamil.pdf'],
            ['6', 'English', 'Mathematics', 'Term-II', 'downloaded - unofficial copy (tntextbooks.in)', 'books\Std-06\English\Std 06 - Mathematics - Term-II - unofficial copy.pdf'],
            // Not taken by the group4 preset: an English reader, a CBSE book,
            // a Tamil-medium duplicate of a subject, a book never published,
            // and a standard above 10.
            ['9', 'English', 'English', 'Term-Full', 'downloaded', 'books\Std-09\English\Std 09 - English - Term-Full - English.pdf'],
            ['6', 'Tamil', 'TAMIL(CBSE)', 'Term-I', 'downloaded', 'books\Std-06\Tamil\Std 06 - TAMIL(CBSE) - Term-I - Tamil.pdf'],
            ['9', 'Tamil', 'Science', 'Term-Full', 'downloaded', 'books\Std-09\Tamil\Std 09 - Science - Term-Full - Tamil.pdf'],
            ['7', 'English', 'VOL - II (MATHS)', 'Term-II', 'not on server', 'books\Std-07\English\missing.pdf'],
            ['11', 'English', 'History ', 'Term-Full', 'downloaded', 'books\Std-11\English\Std 11 - History - Term-Full - English.pdf'],
        ];

        $csv = "\u{FEFF}class,medium,subject,term,status,bytes,file,source_url\n";

        foreach ($books as $i => [$class, $medium, $subject, $term, $status, $file]) {
            if ($status !== 'not on server') {
                $path = $this->root.'/'.str_replace('\\', '/', $file);
                @mkdir(dirname($path), 0777, true);
                file_put_contents($path, "%PDF-1.4 book {$i}");
            }

            $csv .= sprintf("\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"10\",\"%s\",\"https://example.test/{$i}.pdf\"\n", $class, $medium, $subject, $term, $status, $file);
        }

        file_put_contents($this->root.'/index.csv', $csv);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($this->root);

        parent::tearDown();
    }

    public function test_the_group4_set_is_registered_in_place_with_standard_subject_and_medium(): void
    {
        $this->artisan('books:import')->assertSuccessful();

        $books = TenantContext::withoutTenancy(fn () => Source::query()->with('book')->orderBy('title')->get());

        $this->assertSame([
            'Std 6 Mathematics Term-II (English)',
            'Std 9 Science Term-Full (English)',
            'Std 9 Tamil Term-Full (Tamil)',
        ], $books->pluck('title')->all());

        $science = $books->firstWhere('title', 'Std 9 Science Term-Full (English)');
        $this->assertSame('textbooks', $science->storage_disk);
        $this->assertSame('books/Std-09/English/Std 09 - Science - Term-Full - English.pdf', $science->storage_path);
        $this->assertSame(['9', 'Science', 'Tamil Nadu State Board'], [$science->book->standard, $science->book->subject_name, $science->book->board]);
        $this->assertSame(Language::byCode('en')->getKey(), $science->language_id);
        $this->assertNull($science->organization_id, 'Books are platform content, shared by every paper.');

        $tamil = $books->firstWhere('title', 'Std 9 Tamil Term-Full (Tamil)');
        $this->assertSame(Language::byCode('ta')->getKey(), $tamil->language_id);

        // Read where it lies: nothing was copied or moved.
        $this->assertFileExists($this->root.'/books/Std-09/English/Std 09 - Science - Term-Full - English.pdf');
    }

    public function test_running_it_again_adds_nothing(): void
    {
        $this->artisan('books:import')->assertSuccessful();
        $this->artisan('books:import')->expectsOutputToContain('0 imported, 3 already there')->assertSuccessful();

        $this->assertSame(3, TenantContext::withoutTenancy(fn () => Book::query()->count()));
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->artisan('books:import', ['--dry-run' => true])
            ->expectsOutputToContain('would import  Std 9 Science Term-Full (English)')
            ->assertSuccessful();

        $this->assertSame(0, TenantContext::withoutTenancy(fn () => Source::query()->count()));
    }

    public function test_process_queues_each_new_book_to_be_read(): void
    {
        Queue::fake();

        $this->artisan('books:import', ['--process' => true])->assertSuccessful();

        Queue::assertPushed(ProcessSource::class, 3);
    }

    public function test_the_whole_folder_with_the_all_preset(): void
    {
        $this->artisan('books:import', ['--preset' => 'all', '--classes' => '6-12'])->assertSuccessful();

        // Every downloaded row; only the one never published is left out.
        $this->assertSame(7, TenantContext::withoutTenancy(fn () => Source::query()->count()));
    }
}
