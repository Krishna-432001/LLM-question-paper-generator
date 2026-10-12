<?php

declare(strict_types=1);

namespace App\Domain\Syllabus;

/**
 * Turns the text of a syllabus notification into units and topics.
 *
 * Deterministic on purpose, like past-paper parsing: a syllabus is a short,
 * regular document, the result is reviewed in the editor before anything is
 * published, and a parser that fails visibly is easier to correct than a
 * model that paraphrases. What it cannot place is left for the editor.
 *
 * Recognised shapes, all seen in TNPSC and SSC notifications:
 *
 *   UNIT I : GENERAL SCIENCE (5 Questions)       unit heading, with a count
 *   Unit - II  Geography                          unit heading
 *   அலகு 3 – இந்திய ஆட்சியியல்                      Tamil unit heading
 *   (i) Nature of Universe – Mechanics ...       enumerated topic
 *   1. Indus Valley Civilization                 numbered topic
 *   Rights; Duties; Directive Principles          semicolon-separated topics
 */
final class SyllabusTextParser
{
    private const UNIT_HEADING = '/^(?:unit|அலகு|part|பகுதி|section|paper)\s*[-–—:.]?\s*([ivxlcdm]+|\d+|[a-zஅ-ஔ])\b[\s.:\-–—)]*(.*)$/iu';

    /** "(ii) " anywhere, or "2. " / "b) " at the start of a line. */
    private const ENUMERATOR = '/(?:^|\s)\((?:[ivxlcdm]+|\d+|[a-z])\)\s+|^\s*(?:\d{1,2}|[a-z])[.)]\s+/imu';

    private const MAX_TOPIC_CHARS = 160;

    /**
     * @return list<array{name: string, questions: int|null, topics: list<string>}>
     */
    public function parse(string $text): array
    {
        $units = [];
        $current = null;

        foreach (preg_split('/\R/u', $text) ?: [] as $rawLine) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $rawLine));

            if ($line === '') {
                continue;
            }

            if (preg_match(self::UNIT_HEADING, $line, $m) === 1) {
                if ($current !== null) {
                    $units[] = $current;
                }

                [$name, $questions] = $this->headingParts(trim($m[2]) !== '' ? trim($m[2]) : $line);
                $current = ['name' => $name, 'questions' => $questions, 'body' => ''];

                continue;
            }

            if ($current === null) {
                // Preamble before the first unit: instructions, scheme of
                // examination. Not syllabus content.
                continue;
            }

            // Line breaks are kept: a numbered list's boundaries are its line
            // starts.
            $current['body'] .= "\n".$line;
        }

        if ($current !== null) {
            $units[] = $current;
        }

        return array_map(fn (array $u): array => [
            'name' => $u['name'],
            'questions' => $u['questions'],
            'topics' => $this->topics($u['body']),
        ], $units);
    }

    /** @return array{0: string, 1: int|null} */
    private function headingParts(string $heading): array
    {
        $questions = null;

        if (preg_match('/[(\[]?\s*(\d{1,3})\s*(?:questions?|qns?|marks?|வினாக்கள்)\s*[)\]]?/iu', $heading, $m) === 1) {
            $questions = (int) $m[1];
            $heading = str_replace($m[0], '', $heading);
        }

        $name = trim($heading, " \t-–—:.()");

        // Notifications often SHOUT unit names; store them readably.
        if ($name !== '' && mb_strtoupper($name) === $name && preg_match('/\p{Lu}/u', $name) === 1) {
            $name = mb_convert_case(mb_strtolower($name), MB_CASE_TITLE);
        }

        return [$name, $questions];
    }

    /** @return list<string> */
    private function topics(string $body): array
    {
        $body = trim($body);

        if ($body === '') {
            return [];
        }

        // Enumerated items are the notification's own topic boundaries; then
        // semicolons; failing both, one topic per line.
        $pieces = match (true) {
            preg_match(self::ENUMERATOR, $body) === 1 => preg_split(self::ENUMERATOR, $body),
            str_contains($body, ';') => preg_split('/\s*;\s*/u', $body),
            default => preg_split('/\R/u', $body),
        };

        $topics = [];

        foreach ($pieces ?: [] as $piece) {
            // A wrapped item spans lines; rejoin it.
            $topic = trim((string) preg_replace('/\s+/u', ' ', (string) $piece), " \t.;,-–—");

            if (mb_strlen($topic) < 3) {
                continue;
            }

            if (mb_strlen($topic) > self::MAX_TOPIC_CHARS) {
                // Keep the leading clause; the full text stays in the source.
                $first = preg_split('/\s+[-–—]\s+|:\s+/u', $topic)[0] ?? $topic;
                $topic = mb_strlen($first) <= self::MAX_TOPIC_CHARS
                    ? $first
                    : rtrim(mb_substr($first, 0, self::MAX_TOPIC_CHARS - 1)).'…';
            }

            $topics[$topic] = true;
        }

        return array_keys($topics);
    }
}
