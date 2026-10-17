<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Questions\Aptitude\AptitudeGenerator;
use Illuminate\Console\Command;

/**
 * Writes number-varied aptitude questions (English with Tamil) as a file for
 * questions:import. Each method comes from the Std 8 Mathematics book and the
 * questions cite the page that teaches it; the answers are computed.
 *
 * The same seed always gives the same questions, and a larger
 * --per-template gives those same questions plus more, so the file can be
 * regenerated bigger and loaded again: questions already in the bank are
 * skipped by the importer.
 */
final class GenerateAptitude extends Command
{
    protected $signature = 'questions:aptitude
        {--per-template=50 : How many questions to make from each method}
        {--seed=2026 : The same seed always gives the same questions}
        {--out= : Where to write the file (default: the tnpsc-g4 question content folder)}';

    protected $description = 'Write number-varied aptitude questions from the Std 8 Maths methods, as a file for questions:import';

    public function handle(AptitudeGenerator $generator): int
    {
        $perTemplate = max(1, (int) $this->option('per-template'));
        $questions = $generator->generate($perTemplate, (int) $this->option('seed'));
        $path = (string) ($this->option('out') ?: database_path('content/questions/tnpsc-g4/aptitude-std8-life-mathematics.json'));

        file_put_contents($path, json_encode([
            'exam' => 'TNPSC_G4',
            'source' => AptitudeGenerator::SOURCE,
            'breadcrumb' => AptitudeGenerator::BREADCRUMB,
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

        return self::SUCCESS;
    }
}
