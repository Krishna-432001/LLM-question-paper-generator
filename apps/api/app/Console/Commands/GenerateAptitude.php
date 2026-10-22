<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Questions\Aptitude\AptitudeBooks;
use App\Domain\Questions\Aptitude\AptitudeGenerator;
use Illuminate\Console\Command;

/**
 * Writes number-varied aptitude questions (English with Tamil) as files for
 * questions:import: the Std 8 life-mathematics set, plus one file for each
 * textbook set in AptitudeBooks. Every method comes from a TN Mathematics
 * book and the questions cite the page that teaches it; the answers are
 * computed.
 *
 * The same seed always gives the same questions, and a larger
 * --per-template gives those same questions plus more, so the files can be
 * regenerated bigger and loaded again: questions already in the bank are
 * skipped by the importer.
 */
final class GenerateAptitude extends Command
{
    protected $signature = 'questions:aptitude
        {--per-template=50 : How many questions to make from each method}
        {--seed=2026 : The same seed always gives the same questions}
        {--out= : Write only the Std 8 life-mathematics file, to this path}
        {--only= : Write only the file with this name, e.g. aptitude-std9-mensuration.json}';

    protected $description = 'Write number-varied aptitude questions from the TN Maths books, as files for questions:import';

    public function handle(AptitudeGenerator $generator): int
    {
        $perTemplate = max(1, (int) $this->option('per-template'));
        $seed = (int) $this->option('seed');
        $out = $this->option('out');
        $only = $out ? 'aptitude-std8-life-mathematics.json' : $this->option('only');
        $folder = database_path('content/questions/tnpsc-g4');

        if (! $only || $only === 'aptitude-std8-life-mathematics.json') {
            $this->write(
                (string) ($out ?: $folder.'/aptitude-std8-life-mathematics.json'),
                AptitudeGenerator::SOURCE, AptitudeGenerator::BREADCRUMB,
                $generator->generate($perTemplate, $seed),
            );
        }

        foreach (AptitudeBooks::all() as $book) {
            if ($only && $only !== $book->file()) {
                continue;
            }

            $this->write($folder.'/'.$book->file(), $book->source(), $book->breadcrumb(), $book->generate($perTemplate, $seed));
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     */
    private function write(string $path, string $source, string $breadcrumb, array $questions): void
    {
        file_put_contents($path, json_encode([
            'exam' => 'TNPSC_G4',
            'source' => $source,
            'breadcrumb' => $breadcrumb,
            'questions' => $questions,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        $byTopic = array_count_values(array_column($questions, 'topic'));
        $byAnswer = array_count_values(array_column($questions, 'answer'));
        ksort($byAnswer);

        $this->info(count($questions).' questions written to '.$path);
        foreach ($byTopic as $topic => $count) {
            $this->line("  {$topic}: {$count}");
        }
        $this->line('  answers: '.implode(', ', array_map(static fn ($letter, $count): string => "{$letter} {$count}", array_keys($byAnswer), $byAnswer)));
    }
}
