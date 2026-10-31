<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tests\PaperText;
use App\Models\Language;
use App\Models\Question;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Writes a "free practice pool" for the student website: approved one-answer
 * questions, spread over the syllabus topics, that are NOT in any paid paper
 * (unit-wise prediction or model papers). The website builds its free daily
 * tests and quiz games only from this pool, so free play never gives away a
 * paid question.
 *
 *   php artisan tests:export-pool --count=3000 --out=D:/ExamForge-backups/site-bundles/pool
 *
 * Adding more to a pool that is already on the website (so nothing already there
 * changes and no question appears twice): skip the questions in the existing files
 * and number the new files after them.
 *
 *   php artisan tests:export-pool --per-topic=80 --exclude=D:/ExamForge-backups/site-bundles/pool --start=7 --out=D:/ExamForge-backups/site-bundles/pool
 */
final class ExportPracticePool extends Command
{
    protected $signature = 'tests:export-pool {--count=3000} {--per-topic= : questions per topic (overrides --count)}
        {--exclude=* : pool .json files or folders whose questions must not be exported again}
        {--start=1 : number of the first POOL-NN file} {--per-file=500} {--out=} {--seed=2026}';

    protected $description = 'Export a free practice pool (not in any paid paper) for the student website';

    public function handle(): int
    {
        $out = rtrim((string) $this->option('out'), '/\\');
        if ($out === '' || (! is_dir($out) && ! mkdir($out, 0777, true))) {
            $this->error('Give an --out folder.');

            return self::FAILURE;
        }

        $excluded = $this->excludedIds();
        if ($excluded === null) {
            return self::FAILURE;
        }

        return TenantContext::withoutTenancy(function () use ($out, $excluded): int {
            $en = Language::byCode('en');
            $ta = Language::byCode('ta');

            // Every question used in a paid paper (unit-wise or model) is kept out, and so is every question already in the pool files given.
            $skip = DB::table('test_questions')->join('tests', 'tests.id', '=', 'test_questions.test_id')
                ->where(fn ($q) => $q->whereIn('tests.selection_profile->kind', ['unit_prediction', 'model_paper'])->orWhere('tests.type', 'prediction'))
                ->pluck('test_questions.question_id')->merge($excluded)->unique()->values()->all();

            $topics = DB::table('questions')->where('status', 'APPROVED')->where('format', 'mcq4')->whereNotNull('canonical_topic_id')
                ->select('canonical_topic_id', DB::raw('count(*) n'))->groupBy('canonical_topic_id')->pluck('n', 'canonical_topic_id');
            $perTopic = $this->option('per-topic') !== null ? max(1, (int) $this->option('per-topic'))
                : (int) ceil((int) $this->option('count') / max(1, $topics->count()));
            $want = $this->option('per-topic') !== null ? $perTopic * $topics->count() : (int) $this->option('count');
            mt_srand((int) $this->option('seed'));

            $rows = [];
            $thin = [];
            foreach ($topics->keys() as $topicId) {
                $candidates = Question::query()->where('status', 'APPROVED')->where('format', 'mcq4')->where('canonical_topic_id', $topicId)
                    ->whereNotIn('id', $skip)
                    ->with(['translations', 'options', 'canonicalTopic'])->inRandomOrder()->limit($perTopic * 3)->get();
                $taken = 0;
                foreach ($candidates as $q) {
                    if ($taken >= $perTopic || $q->correctOptionKey() === null) {
                        continue;
                    }
                    $row = ['ref' => $q->getKey().':'.$q->current_version, 'section' => $q->canonicalTopic?->name, 'topic' => $q->canonicalTopic?->name,
                        'format' => 'mcq4', 'difficulty' => $q->difficulty, 'marks' => 1, 'answer' => $q->correctOptionKey()];
                    foreach (['ta' => $ta, 'en' => $en] as $code => $lang) {
                        $t = PaperText::usableTranslation($q, (string) $lang->getKey());
                        if ($t !== null && count($t->optionMap()) === 4) {
                            $row[$code] = ['q' => $t->question_text, 'options' => array_values($t->optionMap()), 'explanation' => $t->explanation];
                        }
                    }
                    if (! isset($row['ta'])) {
                        continue;   // every pool question must at least have Tamil
                    }
                    $row['tamil_only'] = ! isset($row['en']);
                    $rows[] = $row;
                    $taken++;
                }
                if ($taken < $perTopic) {
                    $thin[] = sprintf('%s (%d)', $candidates->first()?->canonicalTopic?->name ?? $topicId, $taken);
                }
            }

            shuffle($rows);
            $rows = array_slice($rows, 0, $want);
            $start = max(1, (int) $this->option('start'));
            foreach (array_chunk($rows, (int) $this->option('per-file')) as $i => $chunk) {
                $code = sprintf('POOL-%02d', $start + $i);
                $bundle = ['format' => 'examforge-test-bundle/1', 'code' => $code, 'title' => ['en' => "Free practice pool $code", 'ta' => "இலவசப் பயிற்சித் தொகுப்பு $code"],
                    'kind' => 'pool', 'duration_minutes' => 1, 'total_marks' => count($chunk), 'language_mode' => 'ta', 'questions' => $chunk];
                $bundle['checksum'] = 'sha256:'.hash('sha256', json_encode($chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                file_put_contents("{$out}/{$code}.json", json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->info(sprintf('%s  %d questions', $code, count($chunk)));
            }

            if ($thin !== []) {
                $this->warn('Fewer than '.$perTopic.' new questions left in: '.implode(', ', $thin));
            }
            $this->info(sprintf('%d questions over %d topics, none from a paid paper%s.', count($rows), $topics->count(),
                $excluded !== [] ? ' and none already in the '.count($excluded).' excluded pool questions' : ''));

            return self::SUCCESS;
        });
    }

    /** Question ids inside the --exclude pool files ("<id>:<version>" refs); null when a path is unreadable. */
    private function excludedIds(): ?array
    {
        $files = [];
        foreach ((array) $this->option('exclude') as $path) {
            if (is_dir($path)) {
                $files = array_merge($files, glob(rtrim($path, '/\\').'/*.json') ?: []);
            } elseif (is_file($path)) {
                $files[] = $path;
            } else {
                $this->error("Cannot read --exclude path: $path");

                return null;
            }
        }

        $ids = [];
        foreach ($files as $file) {
            $bundle = json_decode((string) file_get_contents($file), true);
            foreach ($bundle['questions'] ?? [] as $q) {
                $ids[] = explode(':', (string) ($q['ref'] ?? ''))[0];
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
