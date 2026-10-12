<?php

declare(strict_types=1);

namespace App\Domain\Pyq;

use App\Domain\Pyq\DTOs\ParsedPyq;

/**
 * Pulls questions out of a previous-year paper.
 *
 * Deliberately not an LLM job. A past paper is a fixed, highly regular
 * document, and its questions are facts about what was asked — paying a model
 * to read one invites paraphrase into the single corpus that must stay
 * verbatim, and costs money per paper forever. Regexes are the right tool
 * here, and where they fail they fail visibly rather than plausibly.
 *
 * Two things make this harder than it looks:
 *
 *   - TNPSC papers are bilingual. Options are labelled (A)-(D) in English and
 *     அ) ஆ) இ) ஈ) in Tamil, often in the same paper, and a parser that knows
 *     only Latin silently returns stems with the options still glued on.
 *   - The answer key is usually a grid at the end ("1-A 2-C 3-B"), not marked
 *     against each question, so it has to be matched back by number.
 */
final class PyqParser
{
    /** Tamil option labels, in order, as state-board papers print them. */
    private const TAMIL_LABELS = ['அ', 'ஆ', 'இ', 'ஈ'];

    /** A question starts at a number followed by . or ) at the start of a line. */
    private const QUESTION_START = '/^[ \t]*(\d{1,3})[.)]\s+/mu';

    /**
     * Matches one option label in either script.
     *
     * The Latin half insists on a bracket or a following dot so that ordinary
     * prose containing a stray capital letter is not read as an option.
     */
    private const OPTION_LABEL = '/(?:\(([A-Da-d])\)|(?<![\p{L}])([A-Da-d])[.)]\s|\(?([அஆஇஈ])\)|(?<![\p{L}])([அஆஇஈ])[.)]\s)/u';

    /** "12 - B", "12) C", "12 : a" — the shapes an answer grid actually uses. */
    private const ANSWER_KEY_ENTRY = '/(?<!\d)(\d{1,3})\s*[-–—:.)]\s*([A-Da-d]|[அஆஇஈ])(?![\p{L}\d])/u';

    /**
     * @param  list<string>  $pages  page text in reading order
     * @return list<ParsedPyq>
     */
    public function parse(array $pages): array
    {
        $text = $this->flattenPages($pages);
        $answers = $this->answerKey($text);
        $blocks = $this->splitIntoQuestions($text);

        $parsed = [];

        foreach ($blocks as $number => $block) {
            $question = $this->parseBlock($number, $block);

            if ($question !== null) {
                $parsed[] = $question->withAnswer($answers[$number] ?? null);
            }
        }

        return $parsed;
    }

    /**
     * A question routinely runs across a page break, so pages are joined before
     * splitting. Page markers would otherwise cut a stem in half and leave the
     * options orphaned in a block of their own.
     *
     * @param  list<string>  $pages
     */
    private function flattenPages(array $pages): string
    {
        return implode("\n", array_map(
            static fn (string $page): string => trim($page),
            $pages,
        ));
    }

    /**
     * @return array<int, string>  question number => raw block
     */
    private function splitIntoQuestions(string $text): array
    {
        if (preg_match_all(self::QUESTION_START, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $blocks = [];
        $starts = $matches[0];
        $numbers = $matches[1];

        foreach ($starts as $i => $start) {
            $number = (int) $numbers[$i][0];
            $from = (int) $start[1];
            $to = isset($starts[$i + 1]) ? (int) $starts[$i + 1][1] : strlen($text);

            // A repeated number means the grid at the end, or a second
            // language section. Keep the first, which is the real question.
            if (isset($blocks[$number])) {
                continue;
            }

            $blocks[$number] = substr($text, $from, $to - $from);
        }

        ksort($blocks);

        return $blocks;
    }

    private function parseBlock(int $number, string $block): ?ParsedPyq
    {
        // Drop the leading "12." so it cannot be mistaken for an option label.
        $body = (string) preg_replace('/^[ \t]*\d{1,3}[.)]\s+/u', '', $block, 1);

        if (preg_match_all(self::OPTION_LABEL, $body, $labels, PREG_OFFSET_CAPTURE) === 0) {
            return null;   // no options: a heading, instruction or passage
        }

        $positions = [];

        foreach ($labels[0] as $i => $match) {
            // Only one capture group fires per match; the rest are empty.
            $label = '';

            foreach ([1, 2, 3, 4] as $group) {
                if (($labels[$group][$i][0] ?? '') !== '') {
                    $label = $labels[$group][$i][0];
                    break;
                }
            }

            $key = $this->normaliseLabel($label);

            // First occurrence wins: a bilingual paper repeats the whole option
            // set in the second language, and taking the later one would return
            // the translation as if it were the option.
            if ($key !== null && ! isset($positions[$key])) {
                $positions[$key] = ['offset' => (int) $match[1], 'length' => strlen($match[0])];
            }
        }

        if (count($positions) < 4) {
            return null;   // not a four-option MCQ; not what this parser claims to read
        }

        $ordered = ['A', 'B', 'C', 'D'];
        $firstOffset = min(array_map(static fn (array $p): int => $p['offset'], $positions));

        $stem = $this->tidy(substr($body, 0, $firstOffset));

        if (mb_strlen($stem) < 8) {
            return null;   // a stem that short is a fragment, not a question
        }

        $options = [];

        foreach ($ordered as $i => $key) {
            $start = $positions[$key]['offset'] + $positions[$key]['length'];
            $next = $ordered[$i + 1] ?? null;
            $end = $next !== null && isset($positions[$next])
                ? $positions[$next]['offset']
                : strlen($body);

            $options[$key] = $this->tidy(substr($body, $start, max(0, $end - $start)));
        }

        // An empty option means the block was misread, and a question with a
        // blank choice is worse than one that was skipped.
        foreach ($options as $text) {
            if ($text === '') {
                return null;
            }
        }

        return new ParsedPyq(
            number: $number,
            stem: $stem,
            options: $options,
            correctOption: null,
        );
    }

    /**
     * @return array<int, string>  question number => option key
     */
    private function answerKey(string $text): array
    {
        if (preg_match_all(self::ANSWER_KEY_ENTRY, $text, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $answers = [];

        foreach ($matches as $match) {
            $key = $this->normaliseLabel($match[2]);

            if ($key !== null) {
                // Later entries win: the grid at the end of the paper is the
                // authoritative key, and anything earlier is incidental text.
                $answers[(int) $match[1]] = $key;
            }
        }

        // A handful of coincidental matches ("Article 21 - a right") is not a
        // key. Requiring a run of them keeps stray prose out.
        return count($answers) >= 5 ? $answers : [];
    }

    private function normaliseLabel(string $label): ?string
    {
        $tamil = array_search($label, self::TAMIL_LABELS, true);

        if ($tamil !== false) {
            return ['A', 'B', 'C', 'D'][$tamil];
        }

        $upper = strtoupper($label);

        return in_array($upper, ['A', 'B', 'C', 'D'], true) ? $upper : null;
    }

    private function tidy(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
