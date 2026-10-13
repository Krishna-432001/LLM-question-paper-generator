<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Models\Language;
use App\Models\Question;
use App\Models\StudyPlan;
use App\Models\Test;
use Illuminate\Support\Collection;

/**
 * Turns database rows into a render payload and then HTML.
 *
 * No model is involved: the questions, the answer key and the citations are
 * all already in the database, so composing a paper is string building. That
 * is why a download costs nothing and can be repeated indefinitely.
 *
 * A paper prints in its test's own languages. A single-language test prints
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
        ],
    ];

    /**
     * Options longer than this go one to a row. Two narrow columns of long
     * options, each with a second language beneath it, wrap into a block
     * nobody can scan.
     */
    private const SINGLE_COLUMN_OPTION_LENGTH = 40;

    /**
     * @return array{payload: array<string, mixed>, html: string, title: string}
     */
    public function questionPaper(Test $test, string $documentCode): array
    {
        $test->loadMissing(['questions.question.translations', 'exam', 'language']);
        $languages = $test->paperLanguages();

        $payload = [
            'template' => 'question_paper',
            'locale' => $languages->first()->code ?? 'en',
            'language_mode' => $test->language_mode,
            'languages' => $languages->pluck('code')->all(),
            'meta' => [
                'title' => $test->title,
                'exam' => $test->exam->name ?? '',
                'duration_minutes' => $test->duration_minutes,
                'total_marks' => (float) $test->total_marks,
                'total_questions' => $test->question_count,
                'negative_marking' => (float) $test->negative_marking,
                'document_code' => $documentCode,
            ],
            'questions' => $test->questions->map(function ($tq) use ($languages): array {
                $texts = $this->textsFor($tq->question, $languages);
                $primary = reset($texts) ?: ['text' => null, 'options' => []];

                return [
                    'number' => $tq->sequence,
                    // The primary language stays at the top level, so the DOCX,
                    // XLSX and JSON exports keep reading the keys they always
                    // have.
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
            'languages' => $test->paperLanguages()->pluck('code')->all(),
            'meta' => [
                'title' => $test->title.' — Answer Key',
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
            'questions.question.translations',
            'questions.question.options',
            'questions.question.sources.chunk',
        ]);
        $languages = $test->paperLanguages();

        $payload = [
            'template' => 'explanations',
            'language_mode' => $test->language_mode,
            'languages' => $languages->pluck('code')->all(),
            'meta' => [
                'title' => $test->title.' — Explanations',
                'document_code' => $documentCode,
            ],
            'items' => $test->questions->map(function ($tq) use ($languages): array {
                $question = $tq->question;
                $answer = $question->correctOptionKey();
                $texts = [];

                foreach ($this->textsFor($question, $languages, withExplanation: true) as $code => $text) {
                    $texts[$code] = [
                        'text' => $text['text'],
                        'answer_text' => $text['options'][$answer] ?? '',
                        'explanation' => $text['explanation'],
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
     * print order. Question::textIn is the rule: the validated translation,
     * or the master when there is none, so a paper assembled before its
     * translation was withdrawn still prints rather than leaving a blank.
     *
     * @param  Collection<int, Language>  $languages
     * @return array<string, array{text: ?string, options: array<string, string>, explanation?: ?string}>
     */
    private function textsFor(Question $question, Collection $languages, bool $withExplanation = false): array
    {
        $texts = [];

        foreach ($languages as $language) {
            $translation = $question->textIn($language->getKey());

            $texts[(string) $language->code] = [
                'text' => $translation?->question_text,
                'options' => $translation?->optionMap() ?? [],
            ] + ($withExplanation ? ['explanation' => $translation?->explanation] : []);
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

        foreach ($p['questions'] as $q) {
            [$primary, $others] = $this->versions($q, ['text', 'options']);

            // The second language sits beneath the first, never beside it:
            // side by side halves the width of both, and a long Tamil stem
            // wraps into a column of single words.
            $body = '<div class="stem">'.$this->lines($primary['text']).'</div>';

            foreach ($others as $other) {
                $body .= $this->alternate($primary['text'], $other['text'], 'div', 'stem alt');
            }

            $body .= '<table class="opts">'.$this->optionRows($primary['options'], $others).'</table>';

            $questions .= $this->numbered($q['number'], $body);
        }

        $labels = fn (string $key): string => $this->esc(implode(' / ', $this->labels($codes, $key)));
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
     * option — the wrapped primary and the second language beneath it — hangs
     * aligned under the text rather than under the letter.
     *
     * Two options to a row unless any of them, in any language, is long.
     * Rows are built as complete units rather than by inserting separators
     * mid-stream, which once left a trailing empty row on every question.
     *
     * @param  array<string, string>  $options
     * @param  list<array<string, mixed>>  $others
     */
    private function optionRows(array $options, array $others): string
    {
        $longest = 0;

        foreach ([['options' => $options], ...$others] as $version) {
            foreach ($version['options'] ?? [] as $text) {
                $longest = max($longest, mb_strlen((string) $text));
            }
        }

        $perRow = $longest > self::SINGLE_COLUMN_OPTION_LENGTH ? 1 : 2;
        $width = $perRow === 1 ? '95%' : '45%';
        $cells = [];

        foreach ($options as $key => $text) {
            $cell = nl2br($this->esc($text));

            foreach ($others as $other) {
                $cell .= $this->alternate($text, $other['options'][$key] ?? null, 'span', 'alt', '<br>');
            }

            $cells[] = '<td width="5%" class="ok">('.$key.')</td><td width="'.$width.'" class="opt">'.$cell.'</td>';
        }

        $rows = '';

        foreach (array_chunk($cells, $perRow) as $group) {
            // Pad a short final row so the table stays rectangular.
            $rows .= '<tr>'.implode('', $group)
                .str_repeat('<td width="5%"></td><td width="'.$width.'"></td>', $perRow - count($group)).'</tr>';
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
        $answerLabel = $this->esc(implode(' / ', $this->labels($codes, 'answer')));
        $sourceLabel = $this->esc(implode(' / ', $this->labels($codes, 'source')));
        $body = '';

        foreach ($p['items'] as $item) {
            [$primary, $others] = $this->versions($item, ['text', 'answer_text', 'explanation']);

            $block = '<div class="stem">'.$this->lines($primary['text']).'</div>';

            foreach ($others as $other) {
                $block .= $this->alternate($primary['text'], $other['text'], 'div', 'stem alt');
            }

            $block .= '<p class="ans">'.$answerLabel.': <b>'.$item['answer'].'</b> &mdash; '
                .$this->esc($primary['answer_text']);

            foreach ($others as $other) {
                $block .= $this->alternate($primary['answer_text'], $other['answer_text'], 'span', 'alt', '<br>');
            }

            $block .= '</p><p class="exp">'.$this->lines($primary['explanation']).'</p>';

            foreach ($others as $other) {
                $block .= $this->alternate($primary['explanation'], $other['explanation'], 'p', 'exp alt');
            }

            if ($item['quote']) {
                $block .= '<p class="quote">&ldquo;'.$this->esc((string) $item['quote']).'&rdquo;</p>';
            }

            if ($item['citation']) {
                $block .= '<p class="cite">'.$sourceLabel.': '.$this->esc((string) $item['citation']).'</p>';
            }

            $body .= $this->numbered($item['number'], $block);
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
     * its second language and its options all hang aligned to the right of it.
     * One table per item also lets the renderer keep an item on one page — a
     * Tamil stem at the foot of one page with its English and options on the
     * next is a question nobody can read.
     *
     * autosize="1" forbids mPDF from shrinking an item to squeeze it into the
     * space left at the foot of a page. Without it the last question on a
     * page came out in visibly smaller type than the rest; with it, the
     * question moves to the next page at full size.
     */
    private function numbered(int|string $number, string $body): string
    {
        return '<table class="q" autosize="1"><tr><td class="num">'.$this->esc($number).'.</td>'
            .'<td class="qb">'.$body.'</td></tr></table>';
    }

    /**
     * The same text in another language, set lighter beneath the primary.
     * Nothing when it is missing or identical — a paper assembled before its
     * translation existed falls back to the master in both slots, and printing
     * the same sentence twice helps nobody.
     */
    private function alternate(?string $primary, ?string $other, string $tag, string $class, string $before = ''): string
    {
        if ($other === null || trim($other) === '' || trim($other) === trim((string) $primary)) {
            return '';
        }

        return $before.'<'.$tag.' class="'.$class.'">'.$this->lines($other).'</'.$tag.'>';
    }

    /**
     * The primary version of an item and the others, in print order. Payloads
     * stored before language modes carry no `texts`, so the top-level fields
     * stand in as the only version.
     *
     * @param  array<string, mixed>  $item
     * @param  list<string>  $fields
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function versions(array $item, array $fields): array
    {
        $versions = array_values($item['texts'] ?? []);

        if ($versions === []) {
            $versions = [array_intersect_key($item, array_flip($fields))];
        }

        $versions = array_map(
            static fn (array $v): array => $v + array_fill_keys($fields, null),
            $versions,
        );

        $primary = array_shift($versions);
        $primary['options'] ??= [];

        return [$primary, $versions];
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

    /**
     * nl2br, not plain escaping: statement-based, assertion-reason and
     * match-the-following stems carry their layout in newlines, and HTML
     * would otherwise collapse them into one run-on line.
     */
    private function lines(?string $text): string
    {
        return nl2br($this->esc((string) $text));
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
          table.q { width: 100%; margin-bottom: 7pt; border-collapse: collapse; page-break-inside: avoid; }
          td.num { width: 22pt; padding: 0; vertical-align: top; font-weight: bold; }
          td.qb { padding: 0; vertical-align: top; }
          .stem { margin: 0 0 2pt; }
          .alt { color: #444; font-size: 9.5pt; }
          div.alt { margin: 0 0 3pt; }
          .opts { width: 100%; font-size: 10pt; border-collapse: collapse; }
          .ok { padding: 1pt 0; vertical-align: top; }
          .opt { padding: 1pt 6pt 2pt 0; vertical-align: top; }
          .keys { width: 100%; font-size: 10pt; }
          .key { padding: 3pt; border: 0.3pt solid #ccc; text-align: center; }
          .ans { margin: 2pt 0; color: #056; }
          .exp { margin: 2pt 0; font-size: 9.5pt; }
          .quote { margin: 2pt 0; font-size: 9pt; font-style: italic; color: #555;
                   border-left: 2pt solid #c90; padding-left: 5pt; }
          .cite { margin: 1pt 0 0; font-size: 8pt; color: #777; }
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
