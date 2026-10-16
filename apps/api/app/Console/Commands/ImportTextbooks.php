<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Jobs\DispatchTrackedJobAction;
use App\Actions\Sources\CreateSourceAction;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\Ingestion\ProcessSource;
use App\Models\Language;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Registers a folder of downloaded Tamil Nadu textbooks as books, read where
 * they lie — several gigabytes copied into storage would fill the drive.
 *
 * Reads the folder's index.csv (class, medium, subject, term, status, bytes,
 * file, source_url; file relative to the folder). Each book gets its standard,
 * subject and medium, so its questions are filed and shown by them.
 *
 * The group4 preset is the low-cost set for TNPSC Group IV: Std 6–10, the
 * Tamil language books for Part A, and the English-medium Mathematics,
 * Science and Social Science books for Part B — each subject read once, in
 * one medium, and translated on demand rather than generated twice.
 */
final class ImportTextbooks extends Command
{
    protected $signature = 'books:import
        {index? : The folder\'s index.csv (default: index.csv in TEXTBOOKS_ROOT)}
        {--preset=group4 : group4, or "all" for every downloaded book}
        {--classes=6-10 : Standards to take, e.g. 6-10 or 9,10}
        {--process : Also queue each new book to be read (needs the queue worker)}
        {--dry-run : List what would be imported, change nothing}';

    protected $description = 'Register downloaded TN textbooks as books, read in place, with standard and subject';

    public function handle(CreateSourceAction $create, DispatchTrackedJobAction $dispatch): int
    {
        $root = rtrim(str_replace('\\', '/', Storage::disk('textbooks')->path('')), '/');
        $index = str_replace('\\', '/', (string) ($this->argument('index') ?? $root.'/index.csv'));

        if (! is_file($index)) {
            $this->error("No index.csv at {$index}. Set TEXTBOOKS_ROOT in .env to the textbooks folder.");

            return self::FAILURE;
        }

        // Files are registered on the textbooks disk by their path inside it,
        // so the index must be the one in that folder.
        if (strcasecmp(rtrim(dirname($index), '/'), $root) !== 0) {
            $this->error("The index is in ".dirname($index).", but TEXTBOOKS_ROOT is {$root}. Point TEXTBOOKS_ROOT at the index's folder.");

            return self::FAILURE;
        }

        $classes = $this->classes((string) $this->option('classes'));
        $preset = (string) $this->option('preset');
        $languages = ['English' => Language::byCode('en')?->getKey(), 'Tamil' => Language::byCode('ta')?->getKey()];

        $rows = $this->rows($index);
        $chosen = array_values(array_filter($rows, fn (array $r): bool => $this->wanted($r, $classes, $preset)));

        if ($chosen === []) {
            $this->warn('No downloaded book in the index matches that selection.');

            return self::SUCCESS;
        }

        $done = ['imported' => 0, 'already' => 0, 'missing' => 0, 'queued' => 0];

        foreach ($chosen as $row) {
            $path = str_replace('\\', '/', $row['file']);
            $isTamilBook = $this->isTamilLanguageBook($row['subject']);
            $languageId = $isTamilBook ? $languages['Tamil'] : ($languages[$row['medium']] ?? $languages['English']);
            $subject = $this->subjectName($row['subject']);
            $title = "Std {$row['class']} {$subject} {$row['term']} ({$row['medium']})";

            if (! Storage::disk('textbooks')->exists($path)) {
                $this->line("  <fg=yellow>missing</>  {$title} — {$path}");
                $done['missing']++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  would import  {$title}");

                continue;
            }

            [$source, $isNew] = TenantContext::withoutTenancy(function () use ($create, $path, $title, $languageId, $row, $subject): array {
                $before = Source::query()->count();
                $source = $create->registerStored(
                    disk: 'textbooks',
                    path: $path,
                    originalName: basename($path),
                    kind: 'textbook',
                    title: $title,
                    languageId: (string) $languageId,
                    rightsBasis: 'govt_open',
                    meta: [
                        'board' => 'Tamil Nadu State Board',
                        'standard' => (string) $row['class'],
                        'subject_name' => $subject,
                    ],
                    isPlatformContent: true,
                );

                return [$source, Source::query()->count() > $before];
            });

            $this->line(($isNew ? '  <fg=green>imported</>  ' : '  already   ').$title);
            $done[$isNew ? 'imported' : 'already']++;

            if ($this->option('process') && $source->status !== 'ready') {
                TenantContext::withoutTenancy(fn () => $dispatch->execute(
                    type: 'ingest',
                    jobClass: ProcessSource::class,
                    input: ['source_id' => $source->getKey()],
                    organizationId: null,
                    userId: null,
                    subjectType: Source::class,
                    subjectId: $source->getKey(),
                    idempotencyKey: 'ingest:'.$source->getKey(),
                ));
                $done['queued']++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%d imported, %d already there, %d missing on disk%s.',
            $done['imported'], $done['already'], $done['missing'],
            $this->option('process') ? ", {$done['queued']} queued to be read" : '',
        ));

        return self::SUCCESS;
    }

    /** @return list<array<string, string>> */
    private function rows(string $index): array
    {
        $handle = fopen($index, 'r');
        $header = null;
        $rows = [];

        while (($line = fgetcsv($handle, escape: '\\')) !== false) {
            if ($header === null) {
                // Spreadsheet exports start the file with a byte-order mark.
                $line[0] = preg_replace('/^\x{FEFF}/u', '', (string) $line[0]);
                $header = array_map(static fn ($h): string => strtolower(trim((string) $h)), $line);

                continue;
            }

            if (count($line) === count($header)) {
                $rows[] = array_map(static fn ($v): string => trim((string) $v), array_combine($header, $line));
            }
        }

        fclose($handle);

        return $rows;
    }

    /** @param  list<int>  $classes */
    private function wanted(array $row, array $classes, string $preset): bool
    {
        // "not on server" rows have no file; unofficial copies say so after "downloaded".
        $status = strtolower($row['status'] ?? '');

        if (! str_starts_with($status, 'downloaded') && ! str_starts_with($status, 'already had')) {
            return false;
        }

        if (! in_array((int) ($row['class'] ?? 0), $classes, true)) {
            return false;
        }

        if ($preset === 'all') {
            return true;
        }

        $subject = $row['subject'] ?? '';

        // A CBSE book is not the state board's.
        if (stripos($subject, 'cbse') !== false) {
            return false;
        }

        // Part A: the Tamil language books, whichever folder holds them.
        if ($this->isTamilLanguageBook($subject)) {
            return true;
        }

        // Part B: General Studies and aptitude, from the English medium only.
        return ($row['medium'] ?? '') === 'English'
            && preg_match('/math|science|social|history|geograph|polit|econom/i', $subject) === 1;
    }

    private function isTamilLanguageBook(string $subject): bool
    {
        return stripos($subject, 'tamil') !== false && stripos($subject, 'cbse') === false;
    }

    /** "VOL - III ( SCIENCE & SOCIAL)" → "Science & Social Science"; plain names tidied. */
    private function subjectName(string $subject): string
    {
        $s = strtolower($subject);

        return match (true) {
            str_contains($s, 'science & social') || str_contains($s, 'science &social') => 'Science & Social Science',
            str_contains($s, 'maths') || str_contains($s, 'mathematics') => 'Mathematics',
            str_contains($s, 'tamil & english') => 'Tamil',
            str_contains($s, 'general tamil') => 'Tamil',
            default => ucwords(trim(preg_replace('/\s*\(.*\)\s*/', ' ', $subject) ?? $subject)),
        };
    }

    /** @return list<int> */
    private function classes(string $spec): array
    {
        $classes = [];

        foreach (explode(',', $spec) as $part) {
            if (preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $part, $m)) {
                $classes = [...$classes, ...range((int) $m[1], (int) $m[2])];
            } elseif (is_numeric(trim($part))) {
                $classes[] = (int) trim($part);
            }
        }

        return array_values(array_unique($classes));
    }
}
