<?php

declare(strict_types=1);

namespace App\Domain\Translation;

use App\Models\GlossaryTerm;

/**
 * The board's vocabulary for one language pair, readable in both directions,
 * and the rules for when an entry actually applies to a question.
 *
 * Entries are curated once, English → Tamil, because that is how the
 * textbooks print them. A Tamil-mastered question being translated into
 * English reads the same rows inverted — except where the Tamil word has
 * several everyday English senses (புதன் is Wednesday and Mercury, வெள்ளி is
 * Friday, silver and Venus). Inverting those would force one sense on every
 * question, so they are left for the translator to judge.
 *
 * Many English exam words are only terms in one subject: "force" is விசை in
 * physics and படை in "Air Force"; "motion" is இயக்கம் in physics and
 * தீர்மானம் in Parliament. So an entry tagged with a subject is enforced
 * only for a question in that subject, or when it is a multi-word phrase
 * specific enough to mean one thing anywhere. Entries with no domain, or in
 * a neutral one (months, exam phrasing), apply everywhere. And any phrase
 * found in the text claims its words, so "Salt March" is never also checked
 * as the month.
 */
final class Glossary
{
    /** Domains whose entries apply whatever the question's subject. */
    public const NEUTRAL_DOMAINS = ['calendar', 'exam', 'general'];

    /**
     * Tamil words with several common English senses. Never inverted for
     * Tamil → English, however they are stored.
     */
    private const NOT_INVERTIBLE = ['புதன்', 'வியாழன்', 'வெள்ளி', 'சனி', 'செவ்வாய்', 'ஞாயிறு', 'திங்கள்'];

    /** @var array<string, list<array{source: string, target: string, kind: string, domain: ?string}>> */
    private array $cache = [];

    /**
     * Every active entry for the pair, one row per (source, domain).
     *
     * @return list<array{source: string, target: string, kind: string, domain: ?string}>
     */
    public function entriesFor(string $sourceLanguageId, string $targetLanguageId): array
    {
        $key = "{$sourceLanguageId}:{$targetLanguageId}";

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $entries = [];

        foreach (GlossaryTerm::query()->forPair($sourceLanguageId, $targetLanguageId)->orderBy('domain')->get() as $row) {
            $entries[mb_strtolower($row->source_term).'|'.($row->domain ?? '')] ??= [
                'source' => $row->source_term,
                'target' => $row->target_term,
                'kind' => $row->kind,
                'domain' => $row->domain,
            ];
        }

        $reverse = GlossaryTerm::query()->forPair($targetLanguageId, $sourceLanguageId)->orderBy('domain')->get();

        // A target shared by two different source terms (வெள்ளி for silver
        // and Friday, நாணயம் for coin and currency) cannot be read back to
        // either one with confidence.
        $sources = [];

        foreach ($reverse as $row) {
            if ($row->kind === 'term') {
                $sources[mb_strtolower($row->target_term)][mb_strtolower($row->source_term)] = true;
            }
        }

        foreach ($reverse as $row) {
            $tamil = $row->target_term;

            if (in_array($tamil, self::NOT_INVERTIBLE, true)
                || ($row->kind === 'term' && count($sources[mb_strtolower($tamil)] ?? []) > 1)) {
                continue;
            }

            $entries[mb_strtolower($tamil).'|'.($row->domain ?? '')] ??= [
                'source' => $tamil,
                'target' => $row->source_term,
                'kind' => $row->kind,
                'domain' => $row->domain,
            ];
        }

        return $this->cache[$key] = array_values($entries);
    }

    /**
     * Proper nouns for the masker, one target per source spelling. A name in
     * the question's own subject wins over the same spelling elsewhere.
     *
     * @param  list<string>|null  $subjects
     * @return list<array{source: string, target: string}>
     */
    public function namesFor(string $sourceLanguageId, string $targetLanguageId, ?array $subjects = null): array
    {
        $names = [];

        foreach ($this->entriesFor($sourceLanguageId, $targetLanguageId) as $entry) {
            if ($entry['kind'] !== 'name') {
                continue;
            }

            $current = $names[$entry['source']] ?? null;

            if ($current === null || (! in_array($current['domain'], $subjects ?? [], true) && in_array($entry['domain'], $subjects ?? [], true))) {
                $names[$entry['source']] = $entry;
            }
        }

        return array_values(array_map(
            static fn (array $e): array => ['source' => $e['source'], 'target' => $e['target']],
            $names,
        ));
    }

    /**
     * The enforced terms that occur in a text, chosen for the question's
     * subjects (null when they are not known).
     *
     * @param  list<string>|null  $subjects  glossary domains the question belongs to
     * @return list<array{source: string, target: string, kind: string, domain: ?string}>
     */
    public function termsIn(string $text, string $sourceLanguageId, string $targetLanguageId, ?array $subjects = null): array
    {
        $groups = [];

        foreach ($this->entriesFor($sourceLanguageId, $targetLanguageId) as $entry) {
            if ($entry['kind'] === 'term' && trim($entry['source']) !== '') {
                $groups[mb_strtolower($entry['source'])][] = $entry;
            }
        }

        // Longest first: a phrase claims its words before they are tried
        // alone.
        uksort($groups, static fn (int|string $a, int|string $b): int => mb_strlen((string) $b) <=> mb_strlen((string) $a));

        $claimed = [];
        $found = [];

        foreach ($groups as $entries) {
            $source = $entries[0]['source'];

            if (WordEdges::isTamil($source) && WordEdges::letterCount($source) < WordEdges::MIN_TAMIL_LETTERS) {
                continue;
            }

            $right = WordEdges::isLatin($source)
                // English plurals still count as the term ("acids" uses "acid").
                ? '(?:s|es)?'.WordEdges::boundary()
                : WordEdges::after($source);

            $pattern = '/(?<![\p{L}\p{M}\p{N}])'.preg_quote($source, '/').$right.'/iu';

            if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            $fresh = false;

            foreach ($m[0] as [$match, $offset]) {
                $start = (int) $offset;
                $end = $start + strlen($match);

                if (! $this->overlaps($claimed, $start, $end)) {
                    $fresh = true;
                    $claimed[] = [$start, $end];
                }
            }

            // Claimed even when not enforced, so the words inside an
            // unenforced phrase are not enforced on their own either.
            $chosen = $fresh ? $this->choose($entries, $subjects) : null;

            if ($chosen !== null) {
                $found[] = $chosen;
            }
        }

        return $found;
    }

    /**
     * Whether the required target term was used.
     *
     * Exact containment first. Tamil then gets one allowance: when the word
     * takes a case ending its last syllable often changes (மரம் → மரத்தின்,
     * தமிழ்நாடு → தமிழ்நாட்டில்), so the term minus its final syllable is
     * accepted as long as a meaningful stem remains.
     */
    public function termUsed(string $target, string $translated): bool
    {
        if (mb_stripos($translated, $target) !== false) {
            return true;
        }

        if (! WordEdges::isTamil($target)) {
            // English: tolerate the plural and simple inflections.
            $stem = preg_replace('/(?:es|s|e)$/i', '', $target) ?? $target;

            return mb_strlen($stem) >= 4 && mb_stripos($translated, $stem) !== false;
        }

        $chars = preg_split('//u', $target, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $last = end($chars);

        // Ends in a vowel sign or the pulli: drop that and its consonant.
        if ($last !== false && preg_match('/[\x{0BBE}-\x{0BCD}]/u', $last) === 1 && count($chars) >= 5) {
            $stem = implode('', array_slice($chars, 0, -2));

            return mb_strpos($translated, $stem) !== false;
        }

        return false;
    }

    /** Forget cached entries, e.g. after the glossary is edited mid-process. */
    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * Which of the entries sharing one source term applies.
     *
     * @param  list<array{source: string, target: string, kind: string, domain: ?string}>  $entries
     * @param  list<string>|null  $subjects
     * @return array{source: string, target: string, kind: string, domain: ?string}|null
     */
    private function choose(array $entries, ?array $subjects): ?array
    {
        foreach ($entries as $entry) {
            if ($subjects !== null && $entry['domain'] !== null && in_array($entry['domain'], $subjects, true)) {
                return $entry;
            }
        }

        foreach ($entries as $entry) {
            if ($entry['domain'] === null || in_array($entry['domain'], self::NEUTRAL_DOMAINS, true)) {
                return $entry;
            }
        }

        // A subject's single word ("force", "root", "President") means
        // something else outside that subject, so it is not forced on a
        // question from another subject, or one whose subject is unknown. A
        // multi-word phrase ("kinetic energy") is specific enough anywhere.
        return preg_match('/[\s\-]/u', $entries[0]['source']) === 1 ? $entries[0] : null;
    }

    /** @param  list<array{0: int, 1: int}>  $claimed */
    private function overlaps(array $claimed, int $start, int $end): bool
    {
        foreach ($claimed as [$s, $e]) {
            if ($start < $e && $end > $s) {
                return true;
            }
        }

        return false;
    }
}
