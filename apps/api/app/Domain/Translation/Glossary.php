<?php

declare(strict_types=1);

namespace App\Domain\Translation;

use App\Models\GlossaryTerm;

/**
 * The board's vocabulary for one language pair, readable in both directions.
 *
 * Entries are curated once, English → Tamil, because that is how the
 * textbooks print them. A Tamil-mastered question being translated into
 * English needs the same pairs the other way round; storing them twice would
 * mean a correction made to one copy silently disagrees with the other. So a
 * lookup for ta → en reads the en → ta rows inverted, and a row stored
 * directly for ta → en wins when both exist.
 */
final class Glossary
{
    /** @var array<string, list<array{source: string, target: string, kind: string, domain: ?string}>> */
    private array $cache = [];

    /**
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
            $entries[mb_strtolower($row->source_term)] ??= [
                'source' => $row->source_term,
                'target' => $row->target_term,
                'kind' => $row->kind,
                'domain' => $row->domain,
            ];
        }

        foreach (GlossaryTerm::query()->forPair($targetLanguageId, $sourceLanguageId)->orderBy('domain')->get() as $row) {
            $entries[mb_strtolower($row->target_term)] ??= [
                'source' => $row->target_term,
                'target' => $row->source_term,
                'kind' => $row->kind,
                'domain' => $row->domain,
            ];
        }

        return $this->cache[$key] = array_values($entries);
    }

    /**
     * Proper nouns for the masker.
     *
     * @return list<array{source: string, target: string}>
     */
    public function namesFor(string $sourceLanguageId, string $targetLanguageId): array
    {
        return array_values(array_map(
            static fn (array $e): array => ['source' => $e['source'], 'target' => $e['target']],
            array_filter(
                $this->entriesFor($sourceLanguageId, $targetLanguageId),
                static fn (array $e): bool => $e['kind'] === 'name',
            ),
        ));
    }

    /**
     * The enforced terms that actually occur in a text.
     *
     * Longest first, and a shorter term inside a longer one already found is
     * not counted separately: "acid rain" demands அமில மழை, and also
     * demanding அமிலம் for the "acid" inside it would fail a correct
     * translation.
     *
     * @return list<array{source: string, target: string, kind: string, domain: ?string}>
     */
    public function termsIn(string $text, string $sourceLanguageId, string $targetLanguageId): array
    {
        $terms = array_values(array_filter(
            $this->entriesFor($sourceLanguageId, $targetLanguageId),
            static fn (array $e): bool => $e['kind'] === 'term' && trim($e['source']) !== '',
        ));

        usort($terms, static fn (array $a, array $b): int => mb_strlen($b['source']) <=> mb_strlen($a['source']));

        $claimed = [];
        $found = [];

        foreach ($terms as $term) {
            $latin = preg_match('/\p{Latin}/u', $term['source']) === 1;

            // English plurals still count as the term ("acids" uses "acid").
            // Tamil adds case endings to the stem, so only the left edge is
            // anchored there.
            $pattern = '/(?<![\p{L}\p{M}\p{N}])'.preg_quote($term['source'], '/')
                .($latin ? '(?:s|es)?(?![\p{L}\p{M}\p{N}])' : '').'/iu';

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

            if ($fresh) {
                $found[] = $term;
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

        if (preg_match('/[\x{0B80}-\x{0BFF}]/u', $target) !== 1) {
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
