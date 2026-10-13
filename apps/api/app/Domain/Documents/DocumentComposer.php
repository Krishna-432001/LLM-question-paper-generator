<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Tests\PaperText;
use App\Models\StudyPlan;
use App\Models\Test;
use App\Models\TestQuestion;
use Illuminate\Support\Collection;

/**
 * Turns database rows into a render payload and then HTML.
 *
 * No model is involved: the questions, the answer key and the citations are
 * all already in the database, so composing a paper is string building. That
 * is why a download costs nothing and can be repeated indefinitely.
 *
 * A paper prints in its test's own languages, from the translation rows the
 * test was assembled with (see PaperText). A single-language test prints
 * every question in tests.language_id; a bilingual one prints each question
 * twice, primary first and the second language beneath it, the way TNPSC sets
 * its papers. Before language modes every paper printed the bank's master
 * text, so a Tamil test came out of the printer in English.
 */
final class DocumentComposer
{
    /**
     * The paper's own furniture in each language it can be printed in. A
     * Tamil paper with English instructions reads like a translation someone
     * forgot to finish. A language without an entry falls back to English.
     *
     * @var array<string, array<string, string>>
     */
    private const LABELS = [
        'en' => [
            'time' => 'Time',
            'minutes' => 'min',
            'questions' => 'Questions',
            'marks' => 'Marks',
            'instructions' => 'Instructions',
            'equal_marks' => 'All questions carry equal marks.',
            'choose' => 'Choose the most appropriate answer for each question.',
            'negative' => 'Negative marking: :n mark per wrong answer.',
            'answer' => 'Answer',
            'source' => 'Source',
            'answer_key' => 'Answer Key',
            'explanations' => 'Explanations',
            'unavailable' => 'English text unavailable',
        ],
        'ta' => [
            'time' => 'நேரம்',
            'minutes' => 'நிமிடம்',
            'questions' => 'வினாக்கள்',
            'marks' => 'மதிப்பெண்கள்',
            'instructions' => 'அறிவுரைகள்',
            'equal_marks' => 'அனைத்து வினாக்களுக்கும் சம மதிப்பெண்கள்.',
            'choose' => 'ஒவ்வொரு வினாவுக்கும் மிகப் பொருத்தமான விடையைத் தேர்ந்தெடுக்கவும்.',
            'negative' => 'தவறான ஒவ்வொரு விடைக்கும் :n மதிப்பெண் கழிக்கப்படும்.',
            'answer' => 'விடை',
            'source' => 'ஆதாரம்',
            'answer_key' => 'விடைக்குறிப்பு',
            'explanations' => 'விளக்கங்கள்',
            'unavailable' => 'தமிழ் மொழிபெயர்ப்பு கிடைக்கவில்லை',
        ],
    ];

    /** For a language with no labels of its own. */
    private const UNAVAILABLE_FALLBACK = 'Translation unavailable (:code)';

    /**
     * Options longer than this go one to a row. Two narrow columns of long
     * options, each with a second language beneath it, wrap into a block
     * nobody can scan.
     */
    private const SINGLE_COLUMN_OPTION_LENGTH = 40;

    /**
     * Estimated printed lines above which an item may break across pages.
     * A short item is kept on one page; holding a long one together would
     * push it whole to the next page and leave most of a page blank — or,
     * past a full page, make the renderer shrink it to unreadable type.
     */
    private const KEEP_TOGETHER_LINES = 24;

    /** Rough characters per printed line, for the estimate above. */
    private const CHARS_PER_LINE = 95;

    /**
     * @return array{payload: array<string, mixed>, html: string, title: string}
     */
    public function questionPaper(Test $test, string $documentCode): array
    {
        $test->loadMissing([...PaperText::RELATIONS, 'exam', 'language']);
        $languages = $test->paperLanguages();
        $codes = $languages->pluck('code')->all();

        $payload = [
            'template' => 'question_paper',
            'locale' => $codes[0] ?? 'en',
            'language_mode' => $test->language_mode,
            'languages' => $codes,
            'meta' => [
                'title' => $test->title,
                'exam' => $test->exam->name ?? '',
                'duration_minutes' => $test->duration_minutes,
                'total_marks' => (float) $test->total_marks,
                'total_questions' => $test->question_count,
                'negative_marking' => (float) $test->negative_marking,
                'document_code' => $documentCode,
                // The furniture in the paper's languages, so the DOCX export
                // prints the same headings as the PDF instead of English.
                'labels' => $this->labelSet($codes, ['time', 'minutes', 'questions', 'marks', 'answer_key']),
            ],
            'questions' => $test->questions->map(function (TestQuestion $tq) use ($languages): array {
                $texts = $this->textsFor($tq, $languages);
                $primary = reset($texts) ?: ['text' => null, 'options' => [], 'available' => false];

                return [
                    'number' => $tq->sequence,
                    // The primary language stays at the top level, so the DOCX,
                    // XLSX and JSON exports keep reading the keys they always
                    // have. Null when the primary has nothing printable.
                    'text' => $primary['text'],
                    'options' => $primary['options'],
                    'marks' => (float) $tq->marks,
                    // Every language the paper prints in, in print order.
                    'texts' => $texts,
                ];
            })->all(),
        ];

        return [
            'payload' => $payload,
            'title' => $test->title,
            'html' => $this->renderQuestionPaper($payload),
        ];
    }

    /**
     * Paper and key as one document, for the formats a machine consumes.
     *
     * Built by calling the same two composers the PDFs use rather than by
     * querying again, so a JSON bundle handed to the student site cannot
     * disagree with the paper that was printed from the same test. A
     * disagreement there would mis-score real candidates and would be very
     * hard to notice.
     *
     * @return array{payload: array<string, mixed>, html: string, title: string}
     */
    public function portableBundle(Test $test, string $documentCode): array
    {
        $paper = $this->questionPaper($test, $documentCode);
        $key = $this->answerKey($test, $documentCode);

        return [
            'payload' => $paper['payload'] + ['answers' => $key['payload']['answers']],
            'title' => $paper['title'],
            // Machine formats never render HTML, but the contract carries it
            // and an empty string is honest about that.
            'html' => '',
        ];
    }

    /**
     * The answer key is rendered from the SAME payload id as the paper and
     * never from a second data source, so a key can never disagree with the
     * paper it belongs to.
     *
     * @return array{payload: array<string, mixed>, html: string, title: string}
     */
    public function answerKey(Test $test, string $documentCode): array
    {
        $test->loadMissing(['questions.question.options', 'exam']);
        $codes = $test->paperLanguages()->pluck('code')->all();

        $rows = $test->questions->map(fn ($tq) => [
            'number' => $tq->sequence,
            // Read from question_options — the language-neutral truth. The
            // key is the same letters whatever language the paper is in.
            'answer' => $tq->question->correctOptionKey(),
            'difficulty' => $tq->question->difficulty,
        ])->all();

        $payload = [
            'template' => 'answer_key',
            'language_mode' => $test->language_mode,
            'languages' => $codes,
            'meta' => [
                'title' => $test->title.' — '.$this->joined($codes, 'answer_key'),
                'exam' => $test->exam->name ?? '',
                'document_code' => $documentCode,
            ],
            'answers' => $rows,
        ];

        return [
            'payload' => $payload,
            'title' => $payload['meta']['title'],
            'html' => $this->renderAnswerKey($payload),
        ];
    }

    /**
     * Explanations carry the citation. This is the page a student can take to
     * a teacher and check.
     *
     * @return array{payload: array<string, mixed>, html: string, title: string}
     */
    public function explanations(Test $test, string $documentCode): array
    {
        $test->loadMissing([
            ...PaperText::RELATIONS,
            'questions.question.options',
            'questions.question.sources.chunk',
        ]);
        $languages = $test->paperLanguages();
        $codes = $languages->pluck('code')->all();

        $payload = [
            'template' => 'explanations',
            'language_mode' => $test->language_mode,
            'languages' => $codes,
            'meta' => [
                'title' => $test->title.' — '.$this->joined($codes, 'explanations'),
                'document_code' => $documentCode,
            ],
            'items' => $test->questions->map(function (TestQuestion $tq) use ($languages): array {
                $question = $tq->question;
                $answer = $question->correctOptionKey();
                $texts = [];

                foreach ($this->textsFor($tq, $languages, withExplanation: true) as $code => $text) {
                    $texts[$code] = [
                        'text' => $text['text'],
                        'answer_text' => $text['options'][$answer] ?? '',
                        'explanation' => $text['explanation'],
                        'available' => $text['available'],
                    ];
                }

                $primary = reset($texts) ?: ['text' => null, 'answer_text' => '', 'explanation' => null];

                return [
                    'number' => $tq->sequence,
                    'text' => $primary['text'],
                    'answer' => $answer,
                    'answer_text' => $primary['answer_text'],
                    'explanation' => $primary['explanation'],
                    'texts' => $texts,
                    'citation' => $question->sources->first()?->citation(),
                    'quote' => $question->sources->first()?->supporting_quote,
                ];
            })->all(),
        ];

        return [
            'payload' => $payload,
            'title' => $payload['meta']['title'],
            'html' => $this->renderExplanations($payload),
        ];
    }

    /**
     * One question's wording per paper language, keyed by language code in
     * print order, read from the rows the test was assembled with. A
     * language with nothing printable is marked unavailable, never filled
     * with another language's text.
     *
     * @param  Collection<int, \App\Models\Language>  $languages
     * @return array<string, array{text: ?string, options: array<string, string>, available: bool, explanation?: ?string}>
     */
    private function textsFor(TestQuestion $testQuestion, Collection $languages, bool $withExplanation = false): array
    {
        $texts = [];

        foreach (PaperText::forQuestion($testQuestion, $languages, $withExplanation) as $code => $text) {
            $texts[$code] = [
                'text' => $text['question_text'],
                'options' => $text['options'],
                'available' => $text['available'],
            ] + ($withExplanation ? ['explanation' => $text['explanation']] : []);
        }

        return $texts;
    }

    /**
     * @return array{payload: array<string, mixed>, html: string, title: string}
     */
    public function studyPlan(StudyPlan $plan, string $documentCode): array
    {
        $plan->loadMissing(['days.items.canonicalTopic', 'exam']);

        $payload = [
            'template' => 'study_plan',
            'meta' => [
                'title' => ucfirst(str_replace('_', ' ', $plan->mode)).' Study Plan',
                'exam' => $plan->exam->name ?? '',
                'start_date' => $plan->start_date->toDateString(),
                'end_date' => $plan->end_date->toDateString(),
                'hours_per_day' => (float) $plan->hours_per_day,
                'coverage_percent' => (float) $plan->coverage_percent,
                'document_code' => $documentCode,
            ],
            'days' => $plan->days->map(fn ($day) => [
                'number' => $day->day_number,
                'date' => $day->plan_date->toDateString(),
                'role' => $day->day_role,
                'minutes' => $day->target_minutes,
                'items' => $day->items->map(fn ($item) => [
                    'type' => $item->item_type,
                    'topic' => $item->canonicalTopic?->name ?? '—',
                    'minutes' => $item->target_minutes,
                    'questions' => $item->question_count,
                ])->all(),
            ])->all(),
            // A plan that could not fit the syllabus prints what it dropped.
            'dropped' => $plan->dropped_topics,
        ];

        return [
            'payload' => $payload,
            'title' => $payload['meta']['title'],
            'html' => $this->renderStudyPlan($payload),
        ];
    }

    // ------------------------------------------------------------ templates

    /** @param  array<string, mixed>  $p */
    private function renderQuestionPaper(array $p): string
    {
        $m = $p['meta'];
        $codes = $this->codesOf($p);
        $questions = '';

        foreach (array_values($p['questions']) as $index => $q) {
            $versions = $this->versions($q, $codes, ['text', 'options']);

            // The second language sits beneath the first, never beside it:
            // side by side halves the width of both, and a long Tamil stem
            // wraps into a column of single words.
            $rows = [...$this->textRows($versions, 'text', 'stem'), ...$this->optionRows($versions)];

            $questions .= $this->item($q['number'], $rows, first: $index === 0);
        }

        $labels = fn (string $key): string => $this->esc($this->joined($codes, $key));
        $instructions = '';

        foreach (['equal_marks', 'choose', 'negative'] as $line) {
            $versions = $this->labels($codes, $line, [':n' => (string) $m['negative_marking']]);
            $instructions .= '<li>'.$this->esc(array_shift($versions))
                .implode('', array_map(fn (string $v): string => '<br><span class="alt">'.$this->esc($v).'</span>', $versions))
                .'</li>';
        }

        return $this->shell($m['title'], <<<HTML
            <div class="head">
              <h1>{$this->esc($m['exam'])}</h1>
              <h2>{$this->esc($m['title'])}</h2>
              <table class="bar">
                <tr>
                  <td>{$labels('time')}: {$m['duration_minutes']} {$labels('minutes')}</td>
                  <td class="c">{$labels('questions')}: {$m['total_questions']}</td>
                  <td class="r">{$labels('marks')}: {$m['total_marks']}</td>
                </tr>
              </table>
            </div>
            <div class="instr">
              <b>{$labels('instructions')}</b>
              <ol>{$instructions}</ol>
            </div>
            {$questions}
        HTML, $m['document_code']);
    }

    /**
     * Option rows: the letter in its own narrow cell so every line of the
     * option — the wrapped first language and the second beneath it — hangs
     * aligned under the text rather than under the letter.
     *
     * Two options to a row unless any of them, in any language, is long.
     * Each pair is its own table row, so a long question can break between
     * rows of options instead of being kept whole.
     *
     * @param  list<array<string, mixed>>  $versions
     * @return list<array{html: string, lines: int}>
     */
    private function optionRows(array $versions): array
    {
        $shown = array_values(array_filter($versions, static fn (array $v): bool => $v['available']));

        if ($shown === []) {
            return [];
        }

        $keys = [];
        $longest = 0;

        foreach ($shown as $version) {
            foreach ($version['options'] as $key => $text) {
                $keys[$key] = true;
                $longest = max($longest, mb_strlen((string) $text));
            }
        }

        ksort($keys);
        $perRow = $longest > self::SINGLE_COLUMN_OPTION_LENGTH ? 1 : 2;
        $width = $perRow === 1 ? 45 : 85;
        [$first, $others] = [$shown[0], array_slice($shown, 1)];
        $cells = [];

        foreach (array_keys($keys) as $key) {
            $text = (string) ($first['options'][$key] ?? '');
            $html = nl2br($this->esc($text));
            $lines = (int) ceil(max(1, mb_strlen($text)) / $width);

            foreach ($others as $other) {
                $alt = (string) ($other['options'][$key] ?? '');

                if ($alt !== '' && trim($alt) !== trim($text)) {
                    $html .= '<br><span class="alt">'.nl2br($this->esc($alt)).'</span>';
                    $lines += (int) ceil(mb_strlen($alt) / $width);
                }
            }

            $cells[] = ['key' => $key, 'html' => $html, 'lines' => $lines];
        }

        $rows = [];

        foreach (array_chunk($cells, $perRow) as $group) {
            $html = '';

            foreach ($group as $cell) {
                $html .= '<td width="5%" class="ok">('.$this->esc($cell['key']).')</td>'
                    .($perRow === 1
                        ? '<td colspan="3" class="opt">'.$cell['html'].'</td>'
                        : '<td width="42.5%" class="opt">'.$cell['html'].'</td>');
            }

            // Pad a short final row so the table stays rectangular.
            if ($perRow === 2 && count($group) === 1) {
                $html .= '<td width="5%"></td><td width="42.5%"></td>';
            }

            $rows[] = ['html' => $html, 'lines' => max(array_column($group, 'lines'))];
        }

        return $rows;
    }

    /** @param  array<string, mixed>  $p */
    private function renderAnswerKey(array $p): string
    {
        $m = $p['meta'];
        $cells = array_map(
            static fn (array $row): string => '<td class="key">'.$row['number']
                .' &ndash; <b>'.$row['answer'].'</b></td>',
            $p['answers'],
        );

        $rows = '';

        foreach (array_chunk($cells, 8) as $group) {
            $rows .= '<tr>'.implode('', $group)
                .str_repeat('<td class="key"></td>', 8 - count($group)).'</tr>';
        }

        return $this->shell($m['title'], <<<HTML
            <div class="head">
              <h1>{$this->esc($m['exam'])}</h1>
              <h2>{$this->esc($m['title'])}</h2>
            </div>
            <table class="keys">{$rows}</table>
        HTML, $m['document_code']);
    }

    /** @param  array<string, mixed>  $p */
    private function renderExplanations(array $p): string
    {
        $m = $p['meta'];
        $codes = $this->codesOf($p);
        $answerLabel = $this->esc($this->joined($codes, 'answer'));
        $sourceLabel = $this->esc($this->joined($codes, 'source'));
        $body = '';

        foreach (array_values($p['items']) as $index => $item) {
            $versions = $this->versions($item, $codes, ['text', 'answer_text', 'explanation']);
            $shown = array_values(array_filter($versions, static fn (array $v): bool => $v['available']));
            $answerText = (string) ($shown[0]['answer_text'] ?? '');

            $rows = $this->textRows($versions, 'text', 'stem');

            $rows[] = $this->row(
                '<td colspan="4" class="ans">'.$answerLabel.': <b>'.$this->esc($item['answer']).'</b>'
                    .($answerText !== '' ? ' &mdash; '.$this->esc($answerText) : '').'</td>',
                $answerText,
            );

            foreach (array_slice($shown, 1) as $other) {
                $alt = (string) $other['answer_text'];

                if ($alt !== '' && trim($alt) !== trim($answerText)) {
                    $rows[] = $this->row('<td colspan="4" class="ans alt">'.$this->esc($alt).'</td>', $alt);
                }
            }

            $rows = [...$rows, ...$this->textRows($shown, 'explanation', 'exp')];

            if ($item['quote']) {
                $rows[] = $this->row('<td colspan="4" class="quote">&ldquo;'.$this->esc((string) $item['quote']).'&rdquo;</td>', (string) $item['quote']);
            }

            if ($item['citation']) {
                $rows[] = $this->row('<td colspan="4" class="cite">'.$sourceLabel.': '.$this->esc((string) $item['citation']).'</td>', '');
            }

            $body .= $this->item($item['number'], $rows, first: $index === 0);
        }

        return $this->shell($m['title'], '<div class="head"><h2>'.$this->esc($m['title'])
            .'</h2></div>'.$body, $m['document_code']);
    }

    /** @param  array<string, mixed>  $p */
    private function renderStudyPlan(array $p): string
    {
        $m = $p['meta'];
        $rows = '';

        foreach ($p['days'] as $day) {
            $items = '';

            foreach ($day['items'] as $item) {
                $badge = $item['type'] === 'test'
                    ? ' <span class="tag">'.($item['questions'] ?? '').'Q</span>'
                    : ($item['type'] === 'revise' ? ' <span class="tag">revise</span>' : '');

                $items .= '<div>&#9744; '.$this->esc((string) $item['topic'])
                    .' <span class="min">'.$item['minutes'].'m</span>'.$badge.'</div>';
            }

            $rows .= '<tr><td class="day"><b>Day '.$day['number'].'</b><br>'
                .$day['date'].'<br><span class="min">'.$this->esc($day['role']).'</span></td>'
                .'<td>'.$items.'</td>'
                .'<td class="r">'.$day['minutes'].' min</td></tr>';
        }

        $dropped = '';

        if (! empty($p['dropped'])) {
            $names = array_map(
                fn (array $d): string => $this->esc((string) ($d['topic'] ?? '')),
                $p['dropped'],
            );
            $dropped = '<div class="warn"><b>Not covered in this plan ('
                .count($names).' topics)</b><br>'.implode(', ', $names)
                .'<br><i>Increase your study hours or extend the timeline to include these.</i></div>';
        }

        return $this->shell($m['title'], <<<HTML
            <div class="head">
              <h1>{$this->esc($m['exam'])}</h1>
              <h2>{$this->esc($m['title'])}</h2>
              <table class="bar">
                <tr>
                  <td>{$m['start_date']} to {$m['end_date']}</td>
                  <td class="c">{$m['hours_per_day']} h/day</td>
                  <td class="r">Coverage: {$m['coverage_percent']}%</td>
                </tr>
              </table>
            </div>
            {$dropped}
            <table class="plan">{$rows}</table>
        HTML, $m['document_code']);
    }

    /**
     * One numbered item: the number in its own column, so a stem that wraps,
     * its second language and its options all hang aligned to the right of
     * it. Every line of text and every pair of options is its own table row,
     * because a row is the unit a page can break between.
     *
     * A short item is kept on one page — a Tamil stem at the foot of one page
     * with its English and options on the next is a question nobody can read.
     * A long one may break, and the first item never waits for page two: it
     * starts under the instructions rather than leaving page one blank.
     * autosize="1" forbids shrinking an item to squeeze it into the space
     * left on a page; the last question on a page used to come out in
     * visibly smaller type than the rest.
     *
     * @param  list<array{html: string, lines: int}>  $rows
     */
    private function item(int|string $number, array $rows, bool $first): string
    {
        $keep = ! $first && array_sum(array_column($rows, 'lines')) <= self::KEEP_TOGETHER_LINES;
        $html = '';

        foreach (array_values($rows) as $i => $row) {
            $html .= '<tr>'
                .($i === 0 ? '<td width="5%" class="num">'.$this->esc($number).'.</td>' : '<td width="5%"></td>')
                .$row['html'].'</tr>';
        }

        return '<table class="q'.($keep ? ' keep' : '').'" autosize="1">'.$html.'</table>';
    }

    /**
     * Rows for one field across the paper's languages: the first language
     * that has text in full, any other beneath it in the lighter style, and a
     * marked gap for a language with nothing printable. Text identical to the
     * first is not repeated.
     *
     * @param  list<array<string, mixed>>  $versions
     * @return list<array{html: string, lines: int}>
     */
    private function textRows(array $versions, string $field, string $class): array
    {
        $rows = [];
        $first = null;

        foreach ($versions as $version) {
            if (! $version['available']) {
                $rows[] = $this->row('<td colspan="4" class="na">'.$this->esc($this->unavailable($version['code'])).'</td>', '');

                continue;
            }

            $text = trim((string) $version[$field]);

            // An explanation may be missing in one language; a stem always
            // gets its row so the number has somewhere to sit.
            if ($text === '' && ($field !== 'text' || $first !== null)) {
                continue;
            }

            if ($first !== null && $text === $first) {
                continue;
            }

            $style = $first === null ? $class : $class.' alt';
            $first ??= $text;

            foreach (preg_split('/\R/u', $text) ?: [''] as $line) {
                $rows[] = $this->row(
                    '<td colspan="4" class="'.$style.'">'.($line === '' ? '&nbsp;' : $this->esc($line)).'</td>',
                    $line,
                );
            }
        }

        return $rows;
    }

    /** @return array{html: string, lines: int} */
    private function row(string $html, string $text): array
    {
        return ['html' => $html, 'lines' => max(1, (int) ceil(mb_strlen($text) / self::CHARS_PER_LINE))];
    }

    /**
     * Every version of an item in print order, with its language code and
     * whether it has printable text. Payloads stored before language modes
     * carry no `texts`; the top-level fields stand in as the only version.
     *
     * @param  array<string, mixed>  $item
     * @param  list<string>  $codes
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    private function versions(array $item, array $codes, array $fields): array
    {
        $versions = [];

        foreach ($item['texts'] ?? [] as $code => $text) {
            $versions[] = ['code' => (string) $code, 'available' => $text['available'] ?? true]
                + $text + array_fill_keys($fields, null);
        }

        if ($versions === []) {
            $versions[] = ['code' => $codes[0] ?? 'en', 'available' => true]
                + array_intersect_key($item, array_flip($fields)) + array_fill_keys($fields, null);
        }

        foreach ($versions as &$version) {
            $version['options'] ??= [];
        }

        return $versions;
    }

    private function unavailable(string $code): string
    {
        return self::LABELS[$code]['unavailable'] ?? strtr(self::UNAVAILABLE_FALLBACK, [':code' => $code]);
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<string>
     */
    private function codesOf(array $p): array
    {
        return array_values($p['languages'] ?? [$p['locale'] ?? 'en']);
    }

    /**
     * A label in each of the paper's languages, primary first. Two languages
     * that share a fallback collapse to one so an unlisted language does not
     * print "Time / Time".
     *
     * @param  list<string>  $codes
     * @param  array<string, string>  $replace
     * @return list<string>
     */
    private function labels(array $codes, string $key, array $replace = []): array
    {
        $labels = array_map(
            static fn (string $code): string => strtr(self::LABELS[$code][$key] ?? self::LABELS['en'][$key], $replace),
            $codes ?: ['en'],
        );

        return array_values(array_unique($labels));
    }

    /** @param  list<string>  $codes */
    private function joined(array $codes, string $key): string
    {
        return implode(' / ', $this->labels($codes, $key));
    }

    /**
     * @param  list<string>  $codes
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function labelSet(array $codes, array $keys): array
    {
        return array_combine($keys, array_map(fn (string $key): string => $this->joined($codes, $key), $keys));
    }

    private function shell(string $title, string $body, string $code): string
    {
        // Tamil is set in FreeSerif, which carries Tamil only in its regular
        // face; the renderer maps Tamil to a regular-only family so bold or
        // italic Tamil falls back to upright rather than to missing glyphs.
        // The second language is therefore told apart by colour and size,
        // never by italics.
        return <<<HTML
        <html><head><meta charset="utf-8"><style>
          body { font-family: freeserif, dejavusans; font-size: 10.5pt; color: #111; }
          h1 { font-size: 13pt; margin: 0 0 2pt; text-align: center; }
          h2 { font-size: 11pt; margin: 0 0 6pt; text-align: center; font-weight: normal; }
          .bar { width: 100%; font-size: 9pt; border-top: 0.6pt solid #444;
                 border-bottom: 0.6pt solid #444; padding: 3pt 0; margin-bottom: 8pt; }
          .c { text-align: center; } .r { text-align: right; }
          .instr { font-size: 9pt; border: 0.4pt solid #999; padding: 4pt 8pt; margin-bottom: 10pt; }
          .instr ol { margin: 3pt 0 0 12pt; padding: 0; }
          table.q { width: 100%; margin-bottom: 7pt; border-collapse: collapse; }
          table.keep { page-break-inside: avoid; }
          table.q td { vertical-align: top; padding: 0; }
          td.num { font-weight: bold; }
          td.stem { padding: 0 0 1pt; }
          .alt { color: #444; font-size: 9.5pt; }
          td.na { color: #777; font-size: 9pt; padding: 0 0 2pt; }
          td.ok { font-size: 10pt; padding: 1pt 0; }
          td.opt { font-size: 10pt; padding: 1pt 6pt 2pt 0; }
          .keys { width: 100%; font-size: 10pt; }
          .key { padding: 3pt; border: 0.3pt solid #ccc; text-align: center; }
          td.ans { padding: 2pt 0 1pt; color: #056; }
          td.exp { padding: 1pt 0; font-size: 9.5pt; }
          td.quote { font-size: 9pt; font-style: italic; color: #555;
                     border-left: 2pt solid #c90; padding: 1pt 0 1pt 5pt; }
          td.cite { font-size: 8pt; color: #777; padding: 1pt 0 0; }
          .plan { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
          .plan td { border: 0.3pt solid #bbb; padding: 4pt 6pt; vertical-align: top; }
          .day { width: 88pt; }
          .min { color: #777; font-size: 8.5pt; }
          .tag { background: #eee; padding: 0 3pt; font-size: 8pt; }
          .warn { border: 0.5pt solid #c90; background: #fffdf5; padding: 5pt 8pt;
                  margin-bottom: 8pt; font-size: 9pt; }
        </style></head><body>{$body}</body></html>
        HTML;
    }

    private function esc(mixed $value): string
    {
        return e((string) $value);
    }
}
