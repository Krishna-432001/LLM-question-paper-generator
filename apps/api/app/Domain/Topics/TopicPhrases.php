<?php

declare(strict_types=1);

namespace App\Domain\Topics;

use App\Models\CanonicalTopic;

/**
 * The phrases a canonical topic can be recognised by, and the normalisation
 * both sides are compared under. Shared by every lexical matcher so a past
 * question and a book lesson are filed by the same rules.
 */
final class TopicPhrases
{
    /**
     * Distinctive phrases only: two words or more, or one long word. "Indian
     * Polity" is evidence; "history" on its own is not.
     *
     * @return list<string> normalised phrases
     */
    public static function for(CanonicalTopic $topic): array
    {
        $aliases = is_array($topic->aliases) ? $topic->aliases : [];

        // Topic names are compound ("Physics: Force, Motion and Energy"), so
        // each clause is a candidate phrase in its own right.
        $parts = preg_split('/[:,()\/]|\band\b/u', (string) $topic->name) ?: [];

        $phrases = [];

        foreach ([...$parts, ...$aliases, $topic->name] as $raw) {
            $phrase = self::normalise((string) $raw);

            if ($phrase === '' || (self::words($phrase) < 2 && mb_strlen($phrase) < 9)) {
                continue;
            }

            $phrases[$phrase] = true;
        }

        return array_keys($phrases);
    }

    /**
     * Aliases, normalised, with no length filter. Only matched against a
     * lesson's own title, where even one word ("Light", "Rocks") is the
     * lesson's subject rather than a passing mention.
     *
     * @return list<string>
     */
    public static function aliases(CanonicalTopic $topic): array
    {
        $aliases = is_array($topic->aliases) ? $topic->aliases : [];

        return array_values(array_unique(array_filter(array_map(
            static fn ($a): string => self::normalise((string) $a),
            $aliases,
        ))));
    }

    /**
     * Content words of the topic's name, singularised. One alone proves
     * little; two together in a short title ("Force and Motion") do.
     *
     * @return list<string>
     */
    public static function keywords(CanonicalTopic $topic): array
    {
        return array_values(array_unique(array_filter(
            self::stems(self::normalise((string) $topic->name)),
            static fn (string $w): bool => mb_strlen($w) >= 4 && ! in_array($w, self::STOPWORDS, true),
        )));
    }

    /** @return list<string> */
    public static function stems(string $normalised): array
    {
        if ($normalised === '') {
            return [];
        }

        // Naive plural folding is enough for titles: "plants" → "plant".
        return array_map(
            static fn (string $w): string => mb_strlen($w) > 4 && str_ends_with($w, 's') && ! str_ends_with($w, 'ss')
                ? mb_substr($w, 0, -1)
                : $w,
            explode(' ', $normalised),
        );
    }

    private const STOPWORDS = ['and', 'the', 'with', 'from', 'into', 'their', 'about', 'other', 'general'];

    public static function normalise(string $text): string
    {
        $lower = mb_strtolower($text);

        // \p{M} is kept: Tamil vowel signs and the pulli are combining marks,
        // and dropping them chopped "தமிழ்த்தேன்" into fragments like "த" that
        // then "matched" unrelated Tamil words.
        return trim((string) preg_replace(
            '/\s+/u',
            ' ',
            (string) preg_replace('/[^\p{L}\p{M}\p{N}\s]+/u', ' ', $lower),
        ));
    }

    public static function words(string $normalised): int
    {
        return $normalised === '' ? 0 : count(explode(' ', $normalised));
    }

    /** Whole-phrase occurrences in already-normalised text. */
    public static function occurrences(string $normalisedHaystack, string $phrase): int
    {
        return substr_count(' '.$normalisedHaystack.' ', ' '.$phrase.' ');
    }
}
