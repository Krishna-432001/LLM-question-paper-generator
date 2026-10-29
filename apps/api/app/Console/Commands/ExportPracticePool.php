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
 */
final class ExportPracticePool extends Command
{
    protected $signature = 'tests:export-pool {--count=3000} {--per-file=500} {--out=} {--seed=2026}';

    protected $description = 'Export a free practice pool (not in any paid paper) for the student website';

    public function handle(): int
    {
        $out = rtrim((string) $this->option('out'), '/\\');
        if ($out === '' || (! is_dir($out) && ! mkdir($out, 0777, true))) {
            $this->error('Give an --out folder.');

            return self::FAILURE;
        }

        return TenantContext::withoutTenancy(function () use ($out): int {
            $en = Language::byCode('en');
            $ta = Language::byCode('ta');

            // Every question used in a paid paper (unit-wise or model) is kept out.
            $paid = DB::table('test_questions')->join('tests', 'tests.id', '=', 'test_questions.test_id')
                ->where(fn ($q) => $q->whereIn('tests.selection_profile->kind', ['unit_prediction', 'model_paper'])->orWhere('tests.type', 'prediction'))
                ->pluck('test_questions.question_id')->flip();

            $topics = DB::table('questions')->where('status', 'APPROVED')->where('format', 'mcq4')->whereNotNull('canonical_topic_id')
                ->select('canonical_topic_id', DB::raw('count(*) n'))->groupBy('canonical_topic_id')->pluck('n', 'canonical_topic_id');
            $want = (int) $this->option('count');
            $perTopic = (int) ceil($want / max(1, $topics->count()));
            mt_srand((int) $this->option('seed'));

            $rows = [];
            foreach ($topics->keys() as $topicId) {
                $candidates = Question::query()->where('status', 'APPROVED')->where('format', 'mcq4')->where('canonical_topic_id', $topicId)
                    ->with(['translations', 'options', 'canonicalTopic'])->inRandomOrder()->limit($perTopic * 4)->get();
                $taken = 0;
                foreach ($candidates as $q) {
                    if ($taken >= $perTopic || isset($paid[$q->getKey()]) || $q->correctOptionKey() === null) {
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
            }

            shuffle($rows);
            $rows = array_slice($rows, 0, $want);
            foreach (array_chunk($rows, (int) $this->option('per-file')) as $i => $chunk) {
                $code = sprintf('POOL-%02d', $i + 1);
                $bundle = ['format' => 'examforge-test-bundle/1', 'code' => $code, 'title' => ['en' => "Free practice pool $code", 'ta' => "இலவசப் பயிற்சித் தொகுப்பு $code"],
                    'kind' => 'pool', 'duration_minutes' => 1, 'total_marks' => count($chunk), 'language_mode' => 'ta', 'questions' => $chunk];
                $bundle['checksum'] = 'sha256:'.hash('sha256', json_encode($chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                file_put_contents("{$out}/{$code}.json", json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->info(sprintf('%s  %d questions', $code, count($chunk)));
            }

            $this->info(sprintf('%d questions over %d topics, none from a paid paper.', count($rows), $topics->count()));

            return self::SUCCESS;
        });
    }
}
