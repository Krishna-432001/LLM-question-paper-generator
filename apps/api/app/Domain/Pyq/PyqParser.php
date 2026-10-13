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
 * Three things make this harder than it looks:
 *
 *   - TNPSC papers are bilingual. Options are labelled (A)-(D) in English and
 *     அ) ஆ) இ) ஈ) in Tamil, often in the same paper, and a parser that knows
 *     only Latin silently returns stems with the options still glued on.
 *   - The same papers print every question twice, once per language, inside
 *     one numbered block. Both versions are worth keeping — a student who
 *     reads Tamil should sit the Tamil one — but only when each can be read
 *     whole. Half a translation is skipped, never patched from the other half.
 *   - The answer key is usually a grid at the end ("1-A 2-C 3-B"), not marked
 *     against each question, so it has to be matched back by number.
 */
final class PyqParser
{
    /** Tamil option labels, in order, as state-board papers print them. */
    private const TAMIL_LABELS = ['அ', 'ஆ', 'இ', 'ஈ'];

    private const KEYS = ['A', 'B', 'C', 'D'];

    /** A question starts at a number followed by . or ) at the start of a line. */
    private const QUESTION_START = '/^[ \t]*(\d{1,3})[.)]\s+/mu';

    /**
     * Matches one option label in either script.
     *
     * The Latin half insists on a bracket or a following dot so that ordinary
     * prose containing a stray capital letter is not read as an option.
     * Groups 1-2 are Latin labels, groups 3-4 Tamil ones.
     */
    private const OPTION_LABEL = '/(?:\(([A-Da-d])\)|(?<![\p{L}])([A-Da-d])[.)]\s|\(?([அஆஇஈ])\)|(?<![\p{L}])([அஆஇஈ])[.)]\s)/u';

    /** "12 - B", "12) C", "12 : a" — the shapes an answer grid actually uses. */
    private const ANSWER_KEY_ENTRY = '/(?<!\d)(\d{1,3})\s*[-–—:.)]\s*([A-Da-d]|[அஆஇஈ])(?![\p{L}\d])/u';

    /**
     * A key a person typed rather than one printed in a paper: the separator
     * is optional ("1A 2C") and the letter may be bracketed ("1 (A)").
     */
    private const TYPED_KEY_ENTRY = '/(?<!\d)(\d{1,3})\s*[-–—:.)=]?\s*\(?([A-Da-d]|[அஆஇஈ])\)?(?![\p{L}\d])/u';

    /** A run of Tamil-block characters, vowel signs included, is one word. */
    private const TAMIL_WORD = '/[\x{0B80}-\x{0BFF}]+/u';

    private const LATIN_WORD = '/[A-Za-z]+/';

    /**
     * @param  list<string>  $pages  page text in reading order
     * @return list<ParsedPyq>
     */
    public function parse(array $pages): array
    {
        $text = $this->flattenPages($pages);

        $parsed = [];
        $stemOffsets = [];

        foreach ($this->splitIntoQuestions($text) as $number => $block) {
            $question = $this->parseBlock($number, $block['text']);

            if ($question !== null) {
                $parsed[] = $question;
                $stemOffsets[$block['offset']] = true;
            }
        }

        $answers = $this->answerKey($text, $stemOffsets);

        return array_map(
            static fn (ParsedPyq $question): ParsedPyq => $question->withAnswer($answers[$question->number] ?? null),
            $parsed,
        );
    }

    /**
     * Reads an answer key someone typed in, e.g. "1-A 2-C 3-B" or "1 அ, 2 இ".
     *
     * No minimum run applies here, unlike a key found inside a paper: the
     * person meant every entry, so one entry is a key of one.
     *
     * @return array<int, string>  question number => option key
     */
    public function readAnswerKey(string $text): array
    {
        if (preg_match_all(self::TYPED_KEY_ENTRY, $text, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $answers = [];

        foreach ($matches as $match) {
            $key = $this->normaliseLabel($match[2]);

            if ($key !== null && (int) $match[1] > 0) {
                $answers[(int) $match[1]] = $key;
            }
        }

        return $answers;
    }

    /** "b", "B", "(B)" or "ஆ" → "B"; anything else is not an option. */
    public function optionKey(string $label): ?string
    {
        return $this->normaliseLabel(trim($label, " \t\n\r()."));
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
     * @return array<int, array{offset: int, text: string}>  question number => where its number sits, and the raw block
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

            $blocks[$number] = [
                'offset' => (int) $numbers[$i][1],
                'text' => substr($text, $from, $to - $from),
            ];
        }

        ksort($blocks);

        return $blocks;
    }

    private function parseBlock(int $number, string $block): ?ParsedPyq
    {
        // Drop the leading "12." so it cannot be mistaken for an option label.
        $body = (string) preg_replace('/^[ \t]*\d{1,3}[.)]\s+/u', '', $block, 1);

        $versions = $this->readBilingual($body);

        if ($versions !== null) {
            $first = (string) array_key_first($versions);

            return new ParsedPyq(
                number: $number,
                stem: $versions[$first]['stem'],
                options: $versions[$first]['options'],
                correctOption: null,
                language: $first,
                translations: $versions,
            );
        }

        $read = $this->readVersion($body);

        if ($read === null) {
            return null;
        }

        return new ParsedPyq(
            number: $number,
            stem: $read['stem'],
            options: $read['options'],
            correctOption: null,
            language: $this->languageOf($read['stem'])
                ?? $this->languageOf(implode(' ', $read['options']))
                ?? 'en',
        );
    }

    /**
     * Splits a block printed in both languages into two complete questions.
     *
     * Lines are sorted by script; lines with no letters in either (numbers,
     * bare labels, blank lines) belong to both, which is what lets a numeric
     * option set printed once serve both stems. The split is trusted only if
     * each side then reads as a whole four-option question on its own — a
     * Tamil option set with no Tamil stem is not a translation, it is a
     * fragment, and the block falls back to being read as one language.
     *
     * A line that mixes the scripts evenly cannot be divided between them, so
     * it abandons the split rather than guessing which half it belongs to.
     *
     * @return array<string, array{stem: string, options: array<string, string>}>|null
     */
    private function readBilingual(string $body): ?array
    {
        $streams = ['ta' => [], 'en' => []];
        $firstSeen = [];

        foreach (preg_split('/\n/u', $body) ?: [] as $line) {
            $script = $this->lineScript($line);

            if ($script === 'mixed') {
                return null;
            }

            if ($script === null) {
                $streams['ta'][] = $line;
                $streams['en'][] = $line;

                continue;
            }

            $streams[$script][] = $line;
            $firstSeen[$script] ??= count($firstSeen);
        }

        if (count($firstSeen) < 2) {
            return null;   // one language only: nothing to split
        }

        asort($firstSeen);
        $versions = [];

        foreach (array_keys($firstSeen) as $language) {
            $read = $this->readVersion(implode("\n", $streams[$language]));

            if ($read === null) {
                return null;
            }

            $versions[$language] = $read;
        }

        return $versions;
    }

    /**
     * Reads one stem and its four options.
     *
     * @return array{stem: string, options: array<string, string>}|null
     */
    private function readVersion(string $body): ?array
    {
        if (preg_match_all(self::OPTION_LABEL, $body, $labels, PREG_OFFSET_CAPTURE) === 0) {
            return null;   // no options: a heading, instruction or passage
        }

        $positions = [];
        $all = [];

        foreach ($labels[0] as $i => $match) {
            // Only one capture group fires per match; the rest are empty.
            $label = '';
            $group = 0;

            foreach ([1, 2, 3, 4] as $candidate) {
                if (($labels[$candidate][$i][0] ?? '') !== '') {
                    $label = $labels[$candidate][$i][0];
                    $group = $candidate;
                    break;
                }
            }

            $key = $this->normaliseLabel($label);

            if ($key === null) {
                continue;
            }

            $entry = [
                'key' => $key,
                'offset' => (int) $match[1],
                'length' => strlen($match[0]),
                'script' => $group <= 2 ? 'latin' : 'tamil',
            ];
            $all[] = $entry;

            // First occurrence wins: a bilingual paper repeats the whole option
            // set in the second language, and taking the later one would return
            // the translation as if it were the option.
            if (! isset($positions[$key])) {
                $positions[$key] = $entry;
            }
        }

        if (count($positions) < 4) {
            return null;   // not a four-option MCQ; not what this parser claims to read
        }

        $firstOffset = min(array_map(static fn (array $p): int => $p['offset'], $positions));

        $stem = $this->tidy(substr($body, 0, $firstOffset));

        if (mb_strlen($stem) < 8) {
            return null;   // a stem that short is a fragment, not a question
        }

        $options = [];

        foreach (self::KEYS as $i => $key) {
            $start = $positions[$key]['offset'] + $positions[$key]['length'];
            $next = self::KEYS[$i + 1] ?? null;
            $end = $next !== null
                ? $positions[$next]['offset']
                : $this->endOfLastOption($positions[$key], $all, strlen($body));

            $options[$key] = $this->tidy(substr($body, $start, max(0, $end - $start)));
        }

        // An empty option means the block was misread, and a question with a
        // blank choice is worse than one that was skipped.
        foreach ($options as $text) {
            if ($text === '') {
                return null;
            }
        }

        return ['stem' => $stem, 'options' => $options];
    }

    /**
     * Where the last option stops.
     *
     * Normally at the end of the block. But a paper that prints the English
     * option set and then the Tamil one (அ-ஈ after A-D) would otherwise hand
     * the whole Tamil set to option D. A complete A-D run in the other label
     * script is that second set; a lone "(A)" inside option D ("Both (A) and
     * (B)") is not, and is left alone.
     *
     * @param  array{key: string, offset: int, length: int, script: string}  $last
     * @param  list<array{key: string, offset: int, length: int, script: string}>  $all
     */
    private function endOfLastOption(array $last, array $all, int $end): int
    {
        $expect = 0;
        $runStart = null;

        foreach ($all as $label) {
            if ($label['offset'] <= $last['offset'] || $label['script'] === $last['script']) {
                continue;
            }

            if ($label['key'] === self::KEYS[$expect]) {
                $runStart = $expect === 0 ? $label['offset'] : $runStart;
                $expect++;

                if ($expect === 4) {
                    return (int) $runStart;
                }
            } elseif ($label['key'] === 'A') {
                $expect = 1;
                $runStart = $label['offset'];
            }
        }

        return $end;
    }

    /**
     * Which language a line is written in: 'ta', 'en', null for a line with no
     * words in either (numbers, labels, blank), or 'mixed' when neither script
     * clearly dominates.
     *
     * Counted in words rather than characters, and option labels are removed
     * first so "(A) புதன்" is a Tamil line. A two-to-one majority is enough:
     * a Tamil stem with "(Photosynthesis)" in brackets is still Tamil, and an
     * English stem quoting 'திருக்குறள்' is still English.
     */
    private function lineScript(string $line): ?string
    {
        $text = (string) preg_replace(self::OPTION_LABEL, ' ', $line);
        $tamil = (int) preg_match_all(self::TAMIL_WORD, $text);
        $latin = (int) preg_match_all(self::LATIN_WORD, $text);

        if ($tamil === 0 && $latin === 0) {
            return null;
        }

        if ($tamil >= 2 * $latin) {
            return 'ta';
        }

        if ($latin >= 2 * $tamil) {
            return 'en';
        }

        return 'mixed';
    }

    /** The majority script of a whole stem; null when it is a tie or has no words. */
    private function languageOf(string $text): ?string
    {
        $tamil = (int) preg_match_all(self::TAMIL_WORD, $text);
        $latin = (int) preg_match_all(self::LATIN_WORD, $text);

        return match (true) {
            $tamil > $latin => 'ta',
            $latin > $tamil => 'en',
            default => null,
        };
    }

    /**
     * @param  array<int, true>  $stemOffsets  where the numbers of parsed questions sit
     * @return array<int, string>  question number => option key
     */
    private function answerKey(string $text, array $stemOffsets): array
    {
        if (preg_match_all(self::ANSWER_KEY_ENTRY, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $answers = [];

        foreach ($matches as $match) {
            // "4. A train leaves Chennai…" reads exactly like the entry "4 - A".
            // A number that opened a question the parser read is a stem, so it
            // cannot also be a key entry. Five such stems would otherwise key
            // a paper that never printed a key.
            if (isset($stemOffsets[$match[1][1]])) {
                continue;
            }

            $key = $this->normaliseLabel($match[2][0]);

            if ($key !== null) {
                // Later entries win: the grid at the end of the paper is the
                // authoritative key, and anything earlier is incidental text.
                $answers[(int) $match[1][0]] = $key;
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
            return self::KEYS[$tamil];
        }

        $upper = strtoupper($label);

        return in_array($upper, self::KEYS, true) ? $upper : null;
    }

    private function tidy(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
