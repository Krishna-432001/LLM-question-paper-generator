<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Models\StudyPlan;
use App\Models\Test;
use Illuminate\Support\Collection;

/**
 * Turns database rows into a render payload and then HTML.
 *
 * No model is involved: the questions, the answer key and the citations are
 * all already in the database, so composing a paper is string building. That
 * is why a download costs nothing and can be repeated indefinitely.
 */
final class DocumentComposer
{
    /**
     * @return array{payload: array<string, mixed>, html: string, title: string}
     */
    public function questionPaper(Test $test, string $documentCode): array
    {
        $test->loadMissing(['questions.question.master', 'exam', 'language']);

        $payload = [
            'template' => 'question_paper',
            'locale' => $test->language->code ?? 'en',
            'meta' => [
                'title' => $test->title,
                'exam' => $test->exam->name ?? '',
                'duration_minutes' => $test->duration_minutes,
                'total_marks' => (float) $test->total_marks,
                'total_questions' => $test->question_count,
                'negative_marking' => (float) $test->negative_marking,
                'document_code' => $documentCode,
            ],
            'questions' => $test->questions->map(fn ($tq) => [
                'number' => $tq->sequence,
                'text' => $tq->question->master?->question_text,
                'options' => $tq->question->master?->optionMap() ?? [],
                'marks' => (float) $tq->marks,
            ])->all(),
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
        $test->loadMissing(['questions.question.master', 'questions.question.options', 'exam']);

        $rows = $test->questions->map(fn ($tq) => [
            'number' => $tq->sequence,
            // Read from question_options — the language-neutral truth.
            'answer' => $tq->question->correctOptionKey(),
            'difficulty' => $tq->question->difficulty,
        ])->all();

        $payload = [
            'template' => 'answer_key',
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
            'questions.question.master',
            'questions.question.options',
            'questions.question.sources.chunk',
        ]);

        $payload = [
            'template' => 'explanations',
            'meta' => [
                'title' => $test->title.' — Explanations',
                'document_code' => $documentCode,
            ],
            'items' => $test->questions->map(fn ($tq) => [
                'number' => $tq->sequence,
                'text' => $tq->question->master?->question_text,
                'answer' => $tq->question->correctOptionKey(),
                'answer_text' => $tq->question->master?->optionMap()[$tq->question->correctOptionKey()] ?? '',
                'explanation' => $tq->question->master?->explanation,
                'citation' => $tq->question->sources->first()?->citation(),
                'quote' => $tq->question->sources->first()?->supporting_quote,
            ])->all(),
        ];

        return [
            'payload' => $payload,
            'title' => $payload['meta']['title'],
            'html' => $this->renderExplanations($payload),
        ];
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
        $questions = '';

        foreach ($p['questions'] as $q) {
            // Two options per row. Rows are built as complete units rather
            // than by inserting separators mid-stream, which previously left
            // a trailing empty row on every question.
            $cells = [];

            foreach ($q['options'] as $key => $text) {
                $cells[] = '<td width="50%" class="opt">('.$key.') '.e((string) $text).'</td>';
            }

            $rows = '';

            foreach (array_chunk($cells, 2) as $pair) {
                // Pad a short final row so the table stays rectangular.
                $rows .= '<tr>'.implode('', $pair)
                    .(count($pair) === 1 ? '<td width="50%"></td>' : '').'</tr>';
            }

            $questions .= '<div class="q">'
                // nl2br, not plain escaping: statement-based, assertion-reason
                // and match-the-following stems carry their layout in newlines,
                // and HTML would otherwise collapse them into one run-on line.
                .'<p class="stem"><b>'.$q['number'].'.</b> '.nl2br(e((string) $q['text'])).'</p>'
                .'<table class="opts">'.$rows.'</table>'
                .'</div>';
        }

        return $this->shell($m['title'], <<<HTML
            <div class="head">
              <h1>{$this->esc($m['exam'])}</h1>
              <h2>{$this->esc($m['title'])}</h2>
              <table class="bar">
                <tr>
                  <td>Time: {$m['duration_minutes']} min</td>
                  <td class="c">Questions: {$m['total_questions']}</td>
                  <td class="r">Marks: {$m['total_marks']}</td>
                </tr>
              </table>
            </div>
            <div class="instr">
              <b>Instructions</b>
              <ol>
                <li>All questions carry equal marks.</li>
                <li>Choose the most appropriate answer for each question.</li>
                <li>Negative marking: {$m['negative_marking']} mark per wrong answer.</li>
              </ol>
            </div>
            {$questions}
        HTML, $m['document_code']);
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
        $body = '';

        foreach ($p['items'] as $item) {
            $citation = $item['citation']
                ? '<p class="cite">Source: '.$this->esc((string) $item['citation']).'</p>'
                : '';
            $quote = $item['quote']
                ? '<p class="quote">&ldquo;'.$this->esc((string) $item['quote']).'&rdquo;</p>'
                : '';

            $body .= '<div class="q">'
                .'<p class="stem"><b>'.$item['number'].'.</b> '.nl2br($this->esc((string) $item['text'])).'</p>'
                .'<p class="ans">Answer: <b>'.$item['answer'].'</b> &mdash; '
                .$this->esc((string) $item['answer_text']).'</p>'
                .'<p class="exp">'.$this->esc((string) $item['explanation']).'</p>'
                .$quote.$citation
                .'</div>';
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

    private function shell(string $title, string $body, string $code): string
    {
        // Indic scripts need a font with real shaping; nirmala is registered
        // by the renderer when the system provides it.
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
          .q { margin-bottom: 9pt; }
          .stem { margin: 0 0 3pt; }
          .opts { width: 100%; font-size: 10pt; }
          .opt { padding: 1pt 0; }
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
