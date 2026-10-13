<?php

declare(strict_types=1);

namespace App\Domain\Pyq;

use App\Domain\Pyq\DTOs\ParsedPaper;
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
 * What makes this harder than it looks:
 *
 *   - TNPSC papers are bilingual. Options are labelled (A)-(D) in English and
 *     அ) ஆ) இ) ஈ) in Tamil, often in the same paper, and a parser that knows
 *     only Latin silently returns stems with the options still glued on.
 *   - The same papers print every question twice, once per language, inside
 *     one numbered block. Both versions are kept when each reads as a whole
 *     question; half a translation is skipped, never patched from the other.
 *   - A numbered line is not always a question. "Consider the following
 *     statements: 1. … 2. …" numbers its statements exactly like questions,
 *     and a paper in parts starts again at 1. Where questions begin is chosen
 *     for the whole paper at once (see chooseBlocks), not line by line.
 *   - The answer key is usually a grid at the end ("1-A 2-C 3-B"), sometimes
 *     an "Answer: (B)" line under each question. Either way it must be cut
 *     out of the questions — left in, it becomes part of the last option and
 *     a student sees the answers on screen.
 */
final class PyqParser
{
    /** Tamil option labels, in order, as state-board papers print them. */
    private const TAMIL_LABELS = ['அ', 'ஆ', 'இ', 'ஈ'];

    private const KEYS = ['A', 'B', 'C', 'D'];

    /**
     * "12." or "12)" at the start of a line, or the same with a question
     * prefix: "Q.12", "Q12.", "Q 12)", "Question 12", "வினா 12". A bare number
     * needs its dot or bracket so a line opening with a year is not a
     * question; after a prefix the number is clear enough alone.
     */
    private const QUESTION_NUMBER = '[ \t]*(?|(?i:q(?:uestion|n)?|வினா)[ \t]*(?i:no\.?)?[ \t]*\.?[ \t]*(\d{1,3})[ \t]*[.):\-]?|(\d{1,3})[.)])\s+';

    private const QUESTION_START = '/^'.self::QUESTION_NUMBER.'/mu';

    private const LEADING_NUMBER = '/^'.self::QUESTION_NUMBER.'/u';

    /**
     * One option label in either script.
     *
     * The Latin bare form insists on a following dot or bracket and a space,
     * so prose with a stray capital is not read as an option. (E) and (உ) are
     * TNPSC's fifth choice, "Answer not known": recognised only so they end
     * option D, never kept as an option.
     */
    private const OPTION_LABEL = '/\((?<lp>[A-Ea-e])\)|(?<![\p{L}])(?<lb>[A-Da-d])(?<lt>[.)])\s|(?<tpo>\()?(?<tp>[அஆஇஈஉ])\)|(?<![\p{L}])(?<tb>[அஆஇஈ])\.\s/u';

    /**
     * One entry of a printed key: "12 - B", "12) C", "12 : a", "12. (B)",
     * "Q12 - B". Bound to one line, so "12." ending a line cannot pair with an
     * "A …" starting the next.
     */
    private const ANSWER_KEY_ENTRY = '/(?<!\d)(?:(?i:q)\.?[ \t]*)?(\d{1,3})[ \t]*[-–—:.)][ \t]*\(?([A-Da-d]|[அஆஇஈ])\)?(?![\p{L}\d])/u';

    /**
     * A key someone typed rather than one printed in a paper: the separator
     * is optional ("1A 2C") and the letter may be bracketed ("1 (A)").
     */
    private const TYPED_KEY_ENTRY = '/(?<!\d)(?:(?i:q)\.?\s*)?(\d{1,3})\s*[-–—:.)=]?\s*\(?([A-Da-d]|[அஆஇஈ])\)?(?![\p{L}\d])/u';

    /** "ANSWER KEY", "Answers:", "விடைகள்" — a heading that starts the key. */
    private const KEY_HEADING = '/^[ \t]*(?:(?i:answer[ \t]*keys?|answers|key[ \t]*answers?|answer[ \t]*sheet)|விடைக்[ \t]*குறிப்பு(?:கள்)?|விடைக்குறிப்பு(?:கள்)?|விடைகள்)[ \t]*[:\-–—.]?[ \t]*(?=$|\d|\()/mu';

    /**
     * "Answer: (B)", "Ans. C", "Ans (b) Article 21", "விடை: ஆ" on a line of its
     * own under a question. A separator or bracket is required after the word
     * so a stem such as "Answer a question…" is left alone.
     */
    private const ANSWER_LINE = '/^[ \t]*(?:(?i:(?:correct[ \t]+)?ans(?:wer)?)|சரியான[ \t]+விடை|விடை)[ \t]*(?:[:.\-–—][ \t]*\(?|\()([A-Da-d]|[அஆஇஈ])\)?(?![\p{L}\d]).*$/mu';

    /**
     * "PART B", "PART - B : GENERAL ENGLISH", "Section 2", "பகுதி - ஆ". Only a
     * whole line, and only when what follows the letter is a separator or an
     * all-capitals title, so "Part A of the Constitution…" stays a stem.
     */
    private const SECTION_HEADING = '/^[ \t]*(?:(?i:part|section)|பகுதி|பிரிவு)[ \t]*[-–—:.]?[ \t]*(?:[A-Za-z]|[IVXivx]{1,4}|\d{1,2}|[அஆஇஈஉ])(?:[ \t]*[-–—:.)].*|[ \t]+[A-Z][A-Z \t&,]*)?[ \t]*$/mu';

    /** Private-use marker standing in for an "Answer:" line until its block is read. */
    private const MARK = "\u{E000}";

    /** A run of Tamil-block characters, vowel signs included, is one word. */
    private const TAMIL_WORD = '/[\x{0B80}-\x{0BFF}]+/u';

    private const LATIN_WORD = '/[A-Za-z]+/';

    /** How many numbered lines one question may swallow (statements, match lists). */
    private const WINDOW = 12;

    /** Question numbers may jump by this much when a number line was lost in extraction. */
    private const MAX_GAP = 4;

    /** A part that starts again at 1 (or 2, 3 if its first questions were unreadable). */
    private const RESTART_MAX = 3;

    /**
     * Questions, in printed order, with no repeated numbers.
     *
     * The shape the PYQ analysis has always read: one row per printed number.
     * A paper in several parts keeps its first part's question for a number
     * that repeats; read() returns every part.
     *
     * @param  list<string>  $pages  page text in reading order
     * @return list<ParsedPyq>
     */
    public function parse(array $pages): array
    {
        $seen = [];
        $questions = [];

        foreach ($this->read($pages)->questions as $question) {
            if (! isset($seen[$question->number])) {
                $seen[$question->number] = true;
                $questions[] = $question;
            }
        }

        return $questions;
    }

    /**
     * Every question in every part, keyed answers applied, and notes about
     * anything the reader should check.
     *
     * @param  list<string>  $pages  page text in reading order
     */
    public function read(array $pages): ParsedPaper
    {
        $text = $this->flattenPages($pages);

        // A part heading between two questions would otherwise become the end
        // of the earlier question's option D.
        $text = (string) preg_replace(self::SECTION_HEADING, '', $text);

        // Per-question answers are lifted out of the text before anything else
        // reads it, and travel with their block as a marker.
        $text = (string) preg_replace_callback(
            self::ANSWER_LINE,
            fn (array $m): string => self::MARK.$this->normaliseLabel($m[1]).self::MARK,
            $text,
        );

        [$body, $tailKey, $keyHeading] = $this->splitOffKey($text);
        $blocks = $this->chooseBlocks($body);

        $questions = [];
        $cutKeys = [];

        foreach ($blocks as $block) {
            if ($block['read']['key'] !== '') {
                $cutKeys[] = $block['read']['key'];
                $keyHeading = $keyHeading || preg_match(self::KEY_HEADING, $block['read']['key']) === 1;
            }

            if ($block['read']['question'] !== null) {
                $questions[] = $block['read']['question']->inSection($block['section']);
            }
        }

        $questions = $this->mergeTranslatedSections($questions);
        $sections = $questions === [] ? 0 : max(array_map(static fn (ParsedPyq $q): int => $q->section, $questions));
        $notes = $sections > 1 ? [['code' => 'sections', 'params' => ['count' => $sections]]] : [];

        $keyText = implode("\n", [...$cutKeys, $tailKey]);
        $fromPreamble = false;

        if (trim($keyText) === '' && $blocks !== []) {
            // No key where keys are printed. The only other place one can be
            // without being part of a question is before the first question.
            $keyText = substr($body, 0, $blocks[0]['from']);
            $fromPreamble = true;
        }

        [$questions, $keyNotes] = $this->applyKey(
            $questions,
            $this->entrySections($keyText, self::ANSWER_KEY_ENTRY),
            $sections,
            trusted: $keyHeading && ! $fromPreamble,
        );

        return new ParsedPaper($questions, [...$notes, ...$keyNotes]);
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
        $answers = [];

        foreach ($this->readAnswerKeySections($text) as $section) {
            $answers = array_replace($answers, $section);
        }

        return $answers;
    }

    /**
     * A typed key split into the parts of the paper: a number that comes
     * round again ("… 50-C 1-B 2-D") starts the key for the next part.
     *
     * @return list<array<int, string>>
     */
    public function readAnswerKeySections(string $text): array
    {
        return $this->entrySections($text, self::TYPED_KEY_ENTRY);
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
     * Separates the answer key printed after the last question.
     *
     * It starts at an answer-key heading, or at the first line made only of
     * key entries ("1 - A  2 - B …", "1. (A)"), whichever comes first after
     * the last line that carries options. Keys printed between parts are cut
     * out of their block instead (see cutKey).
     *
     * @return array{0: string, 1: string, 2: bool}  questions, key text, whether a heading introduced it
     */
    private function splitOffKey(string $text): array
    {
        $lines = $this->lines($text);
        $lastOptions = null;

        foreach ($lines as $i => [$line]) {
            if (preg_match(self::OPTION_LABEL, $line) === 1 && ! $this->isKeyLine($line)) {
                $lastOptions = $i;
            }
        }

        if ($lastOptions === null) {
            return [$text, '', false];
        }

        foreach ($lines as $i => [$line, $offset]) {
            if ($i <= $lastOptions) {
                continue;
            }

            $heading = preg_match(self::KEY_HEADING, $line) === 1;

            if ($heading || $this->isKeyLine($line)) {
                return [substr($text, 0, $offset), substr($text, $offset), $heading];
            }
        }

        return [$text, '', false];
    }

    /**
     * Decides which numbered lines start questions.
     *
     * Each numbered line either starts a question or belongs to the one
     * before it. The choice that reads the most whole questions wins, and
     * among those the one whose numbers run on most smoothly. That is what
     * keeps "1. … 2. …" statements inside question 1, keeps "1." under
     * question 47 as a statement rather than a new part, and still lets a
     * real "PART B / 1." start a new part: there, starting again reads two
     * more questions, while folding it in reads none.
     *
     * @return list<array{from: int, number: int, section: int, read: array{question: ?ParsedPyq, key: string}}>
     */
    private function chooseBlocks(string $text): array
    {
        if (preg_match_all(self::QUESTION_START, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $starts = [];

        foreach ($matches[0] as $i => $match) {
            $starts[] = ['from' => (int) $match[1], 'number' => (int) $matches[1][$i][0]];
        }

        $count = count($starts);
        $end = strlen($text);
        $reads = [];

        $read = function (int $from, ?int $to) use ($starts, $text, $end, &$reads): array {
            $key = $from.':'.($to ?? 'end');

            return $reads[$key] ??= $this->parseBlock(substr(
                $text,
                $starts[$from]['from'],
                ($to === null ? $end : $starts[$to]['from']) - $starts[$from]['from'],
            ));
        };

        $worth = static fn (array $result): int => $result['question'] !== null ? 10 : 0;

        // best[i]: the best total with i the latest question start so far.
        // Every start may also open the paper, everything before it preamble.
        $best = array_fill(0, $count, 0);
        $previous = array_fill(0, $count, null);

        for ($i = 0; $i < $count; $i++) {
            for ($j = max(0, $i - self::WINDOW); $j < $i; $j++) {
                $score = $best[$j] + $worth($read($j, $i))
                    + $this->sequenceScore($starts[$j]['number'], $starts[$i]['number']);

                if ($score > $best[$i]) {
                    $best[$i] = $score;
                    $previous[$i] = $j;
                }
            }
        }

        $last = null;
        $total = 0;

        for ($j = max(0, $count - self::WINDOW); $j < $count; $j++) {
            $score = $best[$j] + $worth($read($j, null));

            if ($last === null || $score > $total) {
                $total = $score;
                $last = $j;
            }
        }

        $path = [];

        for ($i = $last; $i !== null; $i = $previous[$i]) {
            array_unshift($path, $i);
        }

        $blocks = [];
        $section = 1;

        foreach ($path as $k => $i) {
            if ($k > 0 && $this->isRestart($starts[$path[$k - 1]]['number'], $starts[$i]['number'])) {
                $section++;
            }

            $blocks[] = [
                'from' => $starts[$i]['from'],
                'number' => $starts[$i]['number'],
                'section' => $section,
                'read' => $read($i, $path[$k + 1] ?? null),
            ];
        }

        return $blocks;
    }

    /** How well question $next follows question $previous. */
    private function sequenceScore(int $previous, int $next): int
    {
        return match (true) {
            $next === $previous + 1 => 1,
            $next > $previous + 1 && $next <= $previous + self::MAX_GAP => 0,
            $this->isRestart($previous, $next) => -3,
            // Allowed only so a path always exists; never worth a question.
            default => -20,
        };
    }

    private function isRestart(int $previous, int $next): bool
    {
        return $next <= $previous && $next <= self::RESTART_MAX;
    }

    /**
     * @return array{question: ?ParsedPyq, key: string}  the question, and any key text cut from its end
     */
    private function parseBlock(string $block): array
    {
        $number = preg_match(self::LEADING_NUMBER, $block, $m) === 1 ? (int) $m[1] : 0;

        // Drop the leading "12." so it cannot be mistaken for an option label.
        $body = (string) preg_replace(self::LEADING_NUMBER, '', $block, 1);
        [$body, $key] = $this->cutKey($body);

        $answer = null;

        if (preg_match_all('/\x{E000}([A-D])\x{E000}/u', $body, $marks) > 0) {
            $answer = (string) end($marks[1]);
            $body = (string) preg_replace('/\x{E000}[A-D]\x{E000}/u', '', $body);
        }

        $versions = $this->readBilingual($body);

        if ($versions !== null) {
            $first = (string) array_key_first($versions);

            return ['key' => $key, 'question' => new ParsedPyq(
                number: $number,
                stem: $versions[$first]['stem'],
                options: $versions[$first]['options'],
                correctOption: $answer,
                language: $first,
                translations: $versions,
            )];
        }

        $read = $this->readVersion($body);

        if ($read === null) {
            return ['key' => $key, 'question' => null];
        }

        return ['key' => $key, 'question' => new ParsedPyq(
            number: $number,
            stem: $read['stem'],
            options: $read['options'],
            correctOption: $answer,
            language: $this->languageOf($read['stem'])
                ?? $this->languageOf(implode(' ', $read['options']))
                ?? 'en',
        )];
    }

    /**
     * Cuts a key printed inside a block — between two parts of the paper, or
     * straight after the last question with no blank line — off the end of
     * that block, so it cannot become part of option D.
     *
     * @return array{0: string, 1: string}  the question, and the key text cut from it
     */
    private function cutKey(string $body): array
    {
        if (preg_match(self::OPTION_LABEL, $body, $first, PREG_OFFSET_CAPTURE) !== 1) {
            return [$body, ''];
        }

        $firstLabel = (int) $first[0][1];

        foreach ($this->lines($body) as [$line, $offset]) {
            if ($offset + strlen($line) <= $firstLabel) {
                continue;   // the stem: a key cannot come before the options
            }

            if (preg_match(self::KEY_HEADING, $line) === 1 || ($offset > $firstLabel && $this->isKeyLine($line))) {
                return [substr($body, 0, $offset), substr($body, $offset)];
            }
        }

        return [$body, ''];
    }

    /** A line made of nothing but key entries: "1 - A   2 - B", "3. (C)". */
    private function isKeyLine(string $line): bool
    {
        if (preg_match(self::ANSWER_KEY_ENTRY, $line) !== 1) {
            return false;
        }

        return preg_match('/\p{L}/u', (string) preg_replace(self::ANSWER_KEY_ENTRY, ' ', $line)) === 0;
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
        $labels = $this->labels($body);

        if ($labels === []) {
            return null;   // no options: a heading, instruction or passage
        }

        $positions = [];

        foreach ($labels as $label) {
            // First occurrence wins: a bilingual paper repeats the whole option
            // set in the second language, and taking the later one would return
            // the translation as if it were the option.
            if ($label['key'] !== 'E' && ! isset($positions[$label['key']])) {
                $positions[$label['key']] = $label;
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
                : $this->endOfLastOption($positions[$key], $labels, strlen($body));

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
     * Every option label in a block, in order, with the labels that cannot be
     * this question's options removed.
     *
     * A match-the-following question lists its items as "a. … b. … c. … d."
     * and then gives its real options as "(A) … (D)". Both form a complete
     * A-D set in the same script; the list comes first and the options last,
     * so when one script has more than one complete style, only the style
     * whose set starts last is kept. The same rule drops a stray "(a)" from
     * "Article 21(a)" in a stem whose options are "(A)-(D)".
     *
     * @return list<array{key: string, offset: int, length: int, script: string, style: string}>
     */
    private function labels(string $body): array
    {
        if (preg_match_all(self::OPTION_LABEL, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) === 0) {
            return [];
        }

        $labels = [];

        foreach ($matches as $match) {
            [$letter, $script, $form] = match (true) {
                $match['lp'][0] !== null => [$match['lp'][0], 'latin', 'paren'],
                $match['lb'][0] !== null => [$match['lb'][0], 'latin', $match['lt'][0] === ')' ? 'close' : 'dot'],
                $match['tp'][0] !== null => [$match['tp'][0], 'tamil', $match['tpo'][0] !== null ? 'paren' : 'close'],
                default => [(string) $match['tb'][0], 'tamil', 'dot'],
            };

            $key = $this->labelKey($letter);

            if ($key === null) {
                continue;
            }

            $case = $script === 'latin' ? (ctype_upper($letter) ? 'upper' : 'lower') : '';

            $labels[] = [
                'key' => $key,
                'offset' => (int) $match[0][1],
                'length' => strlen((string) $match[0][0]),
                'script' => $script,
                'style' => "{$script}:{$form}:{$case}",
            ];
        }

        return $this->preferOptionStyle($labels);
    }

    /**
     * @param  list<array{key: string, offset: int, length: int, script: string, style: string}>  $labels
     * @return list<array{key: string, offset: int, length: int, script: string, style: string}>
     */
    private function preferOptionStyle(array $labels): array
    {
        $keep = [];

        foreach (['latin', 'tamil'] as $script) {
            $sets = [];

            foreach ($labels as $label) {
                if ($label['script'] === $script && $label['key'] !== 'E') {
                    $sets[$label['style']][$label['key']] ??= $label['offset'];
                }
            }

            $complete = array_filter($sets, static fn (array $keys): bool => count($keys) === 4);

            if ($complete === []) {
                continue;   // no full set in this script: nothing to choose between
            }

            // The complete set whose A comes last is the options.
            uasort($complete, static fn (array $a, array $b): int => $b['A'] <=> $a['A']);
            $keep[$script] = (string) array_key_first($complete);
        }

        return array_values(array_filter(
            $labels,
            static fn (array $label): bool => $label['key'] === 'E'
                || ! isset($keep[$label['script']])
                || $keep[$label['script']] === $label['style'],
        ));
    }

    /**
     * Where the last option stops.
     *
     * Normally at the end of the block. Two things end it sooner: TNPSC's
     * fifth choice, "(E) Answer not known", which is not an option of the
     * question; and a complete A-D run in the other label script, which is
     * the paper repeating the option set in its second language (அ-ஈ after
     * A-D). A lone "(A)" inside option D ("Both (A) and (B)") is neither, and
     * is left alone.
     *
     * @param  array{key: string, offset: int, length: int, script: string, style: string}  $last
     * @param  list<array{key: string, offset: int, length: int, script: string, style: string}>  $labels
     */
    private function endOfLastOption(array $last, array $labels, int $end): int
    {
        $expect = 0;
        $runStart = null;

        foreach ($labels as $label) {
            if ($label['offset'] <= $last['offset']) {
                continue;
            }

            if ($label['key'] === 'E') {
                return $label['offset'];
            }

            if ($label['script'] === $last['script']) {
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
     * A paper printed as a whole Tamil part followed by the same questions in
     * English reads as two parts numbered from 1. When consecutive parts are
     * each in one language, different languages, and mostly share their
     * numbers, they are one set of questions in two languages.
     *
     * @param  list<ParsedPyq>  $questions
     * @return list<ParsedPyq>
     */
    private function mergeTranslatedSections(array $questions): array
    {
        $bySection = [];

        foreach ($questions as $question) {
            $bySection[$question->section][] = $question;
        }

        if (count($bySection) < 2) {
            return $questions;
        }

        ksort($bySection);
        $sections = array_values($bySection);
        $merged = [];

        for ($s = 0; $s < count($sections); $s++) {
            $current = $sections[$s];
            $next = $sections[$s + 1] ?? null;

            if ($next !== null && $this->isTranslationOf($current, $next)) {
                $current = $this->combine($current, $next);
                $s++;
            }

            $merged[] = $current;
        }

        $out = [];

        foreach ($merged as $index => $section) {
            foreach ($section as $question) {
                $out[] = $question->inSection($index + 1);
            }
        }

        return $out;
    }

    /**
     * @param  list<ParsedPyq>  $first
     * @param  list<ParsedPyq>  $second
     */
    private function isTranslationOf(array $first, array $second): bool
    {
        $languages = static fn (array $section): array => array_values(array_unique(array_merge(
            ...array_map(static fn (ParsedPyq $q): array => $q->languages(), $section),
        )));

        $a = $languages($first);
        $b = $languages($second);

        if (count($a) !== 1 || count($b) !== 1 || $a[0] === $b[0]) {
            return false;
        }

        $numbersA = array_map(static fn (ParsedPyq $q): int => $q->number, $first);
        $numbersB = array_map(static fn (ParsedPyq $q): int => $q->number, $second);
        $shared = count(array_intersect($numbersA, $numbersB));

        return $shared >= 0.8 * min(count($numbersA), count($numbersB));
    }

    /**
     * @param  list<ParsedPyq>  $first
     * @param  list<ParsedPyq>  $second
     * @return list<ParsedPyq>
     */
    private function combine(array $first, array $second): array
    {
        $other = [];

        foreach ($second as $question) {
            $other[$question->number] ??= $question;
        }

        $combined = [];

        foreach ($first as $question) {
            $translation = $other[$question->number] ?? null;
            unset($other[$question->number]);

            $combined[$question->number] = $translation === null ? $question : new ParsedPyq(
                number: $question->number,
                stem: $question->stem,
                options: $question->options,
                correctOption: $question->correctOption ?? $translation->correctOption,
                language: $question->language,
                translations: $question->translations + $translation->translations,
                section: $question->section,
            );
        }

        // Read only in the second language: still a question of this part.
        foreach ($other as $number => $question) {
            $combined[$number] = $question->inSection($first[0]->section);
        }

        ksort($combined);

        return array_values($combined);
    }

    /**
     * Applies the key to the questions, part by part.
     *
     * A key found without a heading is believed only when it is long enough
     * not to be coincidence, or when it answers every question read — so a
     * three-question sheet with its three answers is keyed, and "Article 21 -
     * a right" in a stem is not.
     *
     * @param  list<ParsedPyq>  $questions
     * @param  list<array<int, string>>  $keySections
     * @return array{0: list<ParsedPyq>, 1: list<array{code: string, params: array<string, int|string>}>}
     */
    private function applyKey(array $questions, array $keySections, int $sections, bool $trusted): array
    {
        if ($keySections === [] || $questions === []) {
            return [$questions, []];
        }

        $notes = [];
        $bySection = [];

        if ($sections <= 1) {
            // One part: a key printed twice, or in pieces, is still one key;
            // later entries win, as the grid at the end is authoritative.
            $bySection[1] = array_replace(...$keySections);
        } elseif (count($keySections) === $sections) {
            foreach ($keySections as $i => $entries) {
                $bySection[$i + 1] = $entries;
            }
        } elseif (count($keySections) === 1) {
            $bySection[1] = $keySections[0];
            $notes[] = ['code' => 'key_first_section_only', 'params' => ['sections' => $sections]];
        } else {
            foreach (array_slice($keySections, 0, $sections) as $i => $entries) {
                $bySection[$i + 1] = $entries;
            }

            $notes[] = ['code' => 'key_sections_mismatch', 'params' => [
                'key_sections' => count($keySections),
                'sections' => $sections,
            ]];
        }

        $entries = array_sum(array_map('count', $keySections));
        $keyed = array_map(
            static fn (ParsedPyq $q): ParsedPyq => $q->correctOption !== null
                ? $q
                : $q->withAnswer($bySection[$q->section][$q->number] ?? null),
            $questions,
        );

        $coversAll = array_filter($keyed, static fn (ParsedPyq $q): bool => $q->correctOption === null) === [];

        if (! $trusted && $entries < 5 && ! $coversAll) {
            return [$questions, []];
        }

        return [$keyed, $notes];
    }

    /**
     * Key entries in order, split where a number comes round again. A grid
     * printed column by column (1, 11, 21, 2, 12 …) never repeats a number,
     * so it stays one part.
     *
     * @return list<array<int, string>>
     */
    private function entrySections(string $text, string $pattern): array
    {
        if ($text === '' || preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $sections = [];
        $current = [];

        foreach ($matches as $match) {
            $number = (int) $match[1];
            $key = $this->normaliseLabel($match[2]);

            if ($key === null || $number === 0) {
                continue;
            }

            if (isset($current[$number])) {
                $sections[] = $current;
                $current = [];
            }

            $current[$number] = $key;
        }

        if ($current !== []) {
            $sections[] = $current;
        }

        return $sections;
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

    /** @return list<array{0: string, 1: int}>  each line and its byte offset */
    private function lines(string $text): array
    {
        return array_map(
            static fn (array $line): array => [(string) $line[0], (int) $line[1]],
            preg_split('/\n/', $text, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [],
        );
    }

    /** An answer: A-D in either script. */
    private function normaliseLabel(string $label): ?string
    {
        $key = $this->labelKey($label);

        return $key === 'E' ? null : $key;
    }

    /** An option label: A-D, plus E for the fifth "Answer not known" choice. */
    private function labelKey(string $label): ?string
    {
        if ($label === 'உ') {
            return 'E';
        }

        $tamil = array_search($label, self::TAMIL_LABELS, true);

        if ($tamil !== false) {
            return self::KEYS[$tamil];
        }

        $upper = strtoupper($label);

        return in_array($upper, ['A', 'B', 'C', 'D', 'E'], true) ? $upper : null;
    }

    private function tidy(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
