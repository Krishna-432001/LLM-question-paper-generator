<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tests\PaperText;
use App\Models\Language;
use App\Models\Test;
use App\Models\TestQuestion;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Writes tests as "examforge-test-bundle/1" files for the student website.
 *
 * The text of each question is read from the rows the test was assembled
 * with (PaperText), in English and Tamil, so the website shows exactly what
 * the PDF prints. The checksum seals the questions; the website refuses a
 * file edited after export.
 *
 *   php artisan tests:export-bundles --kind=unit_prediction --code=UWP26 --out=D:/bundles
 *   php artisan tests:export-bundles --test=<id> --test=<id> --code=MP26 --site-kind=model --out=…
 */
final class ExportTestBundles extends Command
{
    protected $signature = 'tests:export-bundles
        {--test=* : test ids to export}
        {--kind= : export every test whose selection_profile.kind is this (e.g. unit_prediction, model_paper)}
        {--user=krishna@examforge.test : whose copies to export when using --kind}
        {--code=EF : code prefix; files are numbered in title order}
        {--site-kind= : kind on the website: daily, unit, model, mock or topic (guessed if empty)}
        {--out= : folder to write the .json files to}';

    protected $description = 'Export tests as bundles for the student website';

    public function handle(): int
    {
        $out = rtrim((string) $this->option('out'), '/\\');

        if ($out === '' || (! is_dir($out) && ! mkdir($out, 0777, true))) {
            $this->error('Give an --out folder that exists or can be created.');

            return self::FAILURE;
        }

        return TenantContext::withoutTenancy(function () use ($out): int {
            $query = Test::query()->orderBy('title');

            if ($ids = (array) $this->option('test')) {
                $query->whereIn('id', $ids);
            } elseif ($kind = $this->option('kind')) {
                $query->where('selection_profile->kind', $kind)
                    ->where('created_by', User::query()->where('email', $this->option('user'))->value('id'));
            } else {
                $this->error('Name the tests with --test or --kind.');

                return self::FAILURE;
            }

            $tests = $query->get();

            if ($tests->isEmpty()) {
                $this->error('No tests matched.');

                return self::FAILURE;
            }

            $en = Language::byCode('en');
            $ta = Language::byCode('ta');

            foreach ($tests->values() as $n => $test) {
                $test->loadMissing([...PaperText::RELATIONS, 'questions.question.options', 'questions.question.canonicalTopic']);
                $code = sprintf('%s-%02d', $this->option('code'), $n + 1);
                $questions = $test->questions->map(fn (TestQuestion $tq): array => $this->bundleQuestion($tq, $en, $ta))->all();
                $tamilOnly = $test->language_mode !== Test::MODE_BILINGUAL && strtolower((string) $test->language_id) === strtolower((string) $ta->getKey());

                $bundle = [
                    'format' => 'examforge-test-bundle/1',
                    'code' => $code,
                    'title' => ['en' => $test->title, 'ta' => $test->title],
                    'kind' => $this->option('site-kind') ?: $this->guessKind($test),
                    'duration_minutes' => (int) $test->duration_minutes,
                    'total_marks' => (float) $test->total_marks,
                    'negative_mark' => (float) $test->negative_marking,
                    'language_mode' => $tamilOnly ? 'ta' : ($test->language_mode === Test::MODE_BILINGUAL ? 'both' : 'en'),
                    'sections' => $test->questions->pluck('section')->filter()->unique()->values()->map(fn ($s) => ['name' => $s, 'minutes' => null])->all(),
                    'questions' => $questions,
                ];
                $bundle['checksum'] = 'sha256:'.hash('sha256', json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                $missing = collect($questions)->filter(fn ($q) => ! isset($q['ta']) && ! isset($q['en']))->count();
                file_put_contents("{$out}/{$code}.json", json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
                $this->info(sprintf('%s  %s  (%d questions%s)', $code, $test->title, count($questions), $missing ? ", {$missing} without text" : ''));
            }

            return self::SUCCESS;
        });
    }

    /** @return array<string, mixed> */
    private function bundleQuestion(TestQuestion $tq, Language $en, Language $ta): array
    {
        $q = $tq->question;
        $texts = PaperText::forQuestion($tq, collect([$ta, $en]), withExplanation: true);
        $source = $q->sources()->first();
        $row = [
            'ref' => $q->getKey().':'.$tq->question_version,
            'section' => $tq->section,
            'unit' => null,
            'topic' => $q->canonicalTopic?->name,
            'format' => $q->format,
            'difficulty' => $q->difficulty,
            'marks' => (float) $tq->marks,
            'answer' => $q->correctOptionKey(),
            'tamil_only' => $tq->fixed_language_id !== null && strtolower((string) $tq->fixed_language_id) === strtolower((string) $ta->getKey()),
            'source' => $source ? trim(($source->breadcrumb ?? '').($source->page_start > 1 ? ', p. '.$source->page_start : '')) : null,
        ];

        foreach (['ta', 'en'] as $code) {
            $t = $texts[$code] ?? null;
            if ($t !== null && $t['available'] && count($t['options']) === 4) {
                $row[$code] = ['q' => $t['question_text'], 'options' => array_values($t['options']), 'explanation' => $t['explanation'] ?? null];
            }
        }

        return $row;
    }

    private function guessKind(Test $test): string
    {
        return match (true) {
            ($test->selection_profile['kind'] ?? null) === 'unit_prediction' => 'unit',
            ($test->selection_profile['kind'] ?? null) === 'model_paper' => 'model',
            $test->type === 'daily' => 'daily',
            $test->type === 'topic_practice' => 'topic',
            default => 'mock',
        };
    }
}
