<?php

declare(strict_types=1);

namespace App\Domain\Translation;

/**
 * Takes the parts of a question that must never be translated out of the
 * translator's hands.
 *
 * A model asked to translate "1,26,000" will sometimes helpfully write 126000,
 * or spell it out in words, and a formula like 6CO₂ + 6H₂O → C₆H₁₂O₆ is not
 * language at all. Each such span is swapped for an opaque __PH_n__ token
 * before the call and put back afterwards, so the only way a number can change
 * is for the model to drop or repeat a token — which placeholderProblems()
 * catches.
 *
 * Proper nouns from the glossary go through the same door, except that they
 * come back in their fixed target form: "Cauvery" leaves as a token and
 * returns as காவிரி, spelled the way the board spells it, instead of whatever
 * transliteration the model prefers that day.
 *
 * Every occurrence gets its own token, even when two spans are identical.
 * "Exactly once" is then a simple, strict rule rather than a count to
 * reconcile.
 */
final class ProtectedSpanMasker
{
    /** Canonical placeholder shape. */
    public const TOKEN = '/__PH_(\d+)__/';

    /**
     * The periodic table, longest symbols first so "Cl" wins over "C".
     *
     * A real list rather than [A-Z][a-z]? is what keeps acronyms out:
     * "NASA", "RBI" and "TNPSC" contain letters that are not elements, so they
     * can never be mistaken for formulas and hidden from the translator.
     */
    private const ELEMENTS = [
        'He', 'Li', 'Be', 'Ne', 'Na', 'Mg', 'Al', 'Si', 'Cl', 'Ar', 'Ca', 'Sc', 'Ti', 'Cr', 'Mn',
        'Fe', 'Co', 'Ni', 'Cu', 'Zn', 'Ga', 'Ge', 'As', 'Se', 'Br', 'Kr', 'Rb', 'Sr', 'Zr', 'Nb',
        'Mo', 'Tc', 'Ru', 'Rh', 'Pd', 'Ag', 'Cd', 'In', 'Sn', 'Sb', 'Te', 'Xe', 'Cs', 'Ba', 'La',
        'Ce', 'Pr', 'Nd', 'Pm', 'Sm', 'Eu', 'Gd', 'Tb', 'Dy', 'Ho', 'Er', 'Tm', 'Yb', 'Lu', 'Hf',
        'Ta', 'Re', 'Os', 'Ir', 'Pt', 'Au', 'Hg', 'Tl', 'Pb', 'Bi', 'Po', 'At', 'Rn', 'Fr', 'Ra',
        'Ac', 'Th', 'Pa', 'Np', 'Pu', 'Am', 'Cm', 'Bk', 'Cf', 'Es', 'Fm', 'Md', 'No', 'Lr', 'Rf',
        'Db', 'Sg', 'Bh', 'Hs', 'Mt', 'Ds', 'Rg', 'Cn', 'Nh', 'Fl', 'Mc', 'Lv', 'Ts', 'Og',
        'H', 'B', 'C', 'N', 'O', 'F', 'P', 'S', 'K', 'V', 'Y', 'I', 'W', 'U',
    ];

    /**
     * Units that may follow a number. Only ever matched straight after a
     * number and before a non-letter, so "5 men" never loses its "m".
     */
    private const UNITS = [
        'km/hr', 'km/h', 'kmph', 'm/s²', 'm/s2', 'm/s', 'cm/s', 'km²', 'km2', 'sq km', 'sq.km',
        'm²', 'm³', 'cm²', 'cm³', 'mm²', 'kWh', 'kW', 'MW', 'GW', 'mW', 'kJ', 'MJ', 'kcal', 'cal',
        'kPa', 'Pa', 'atm', 'mmHg', 'kHz', 'MHz', 'GHz', 'Hz', 'dB', 'ppm', 'mol', 'mmol',
        'km', 'cm', 'mm', 'nm', 'µm', 'μm', 'kg', 'mg', 'µg', 'ml', 'mL', 'kV', 'mA', 'Ω',
        'ha', 'ft', 'sec', 'min', 'hr', 'hrs', 'yrs', 'AU', 'ly',
        '°C', '°F', '°', '%', '‰', 'K', 'J', 'W', 'V', 'A', 'N', 'g', 'L', 'l', 'm', 's', 'h',
    ];

    /** Tokens issued since the last reset; lets callers number across fields. */
    private int $issued = 0;

    private ?string $pattern = null;

    /** Start numbering from __PH_1__ again — one call per question item. */
    public function reset(): void
    {
        $this->issued = 0;
    }

    /**
     * Masks one piece of text. Numbering continues across calls until reset(),
     * so the stem, the four options and the explanation of one question never
     * share a token.
     *
     * @param  list<array{source: string, target: string}>  $names  proper nouns to pin
     * @return array{text: string, map: array<string, array{original: string, restore: string, kind: string}>}
     */
    public function mask(string $text, array $names = []): array
    {
        $map = [];

        // Names first: they are whole words a later pattern must not split.
        $text = $this->maskNames($text, $names, $map);

        // Everything else runs only over the text between tokens, so a digit
        // inside "__PH_3__" can never be mistaken for a number.
        $parts = preg_split('/(__PH_\d+__)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        foreach ($parts as $i => $part) {
            if ($i % 2 === 1 || $part === '') {
                continue;
            }

            $parts[$i] = (string) preg_replace_callback(
                $this->pattern(),
                function (array $m) use (&$map): string {
                    $kind = $this->kindOf($m);

                    if ($kind === null) {
                        return $m[0];
                    }

                    [$span, $trail] = $kind === 'url' ? $this->splitUrl($m[0]) : [$m[0], ''];

                    return $this->issue($span, $span, $kind, $map).$trail;
                },
                $part,
            );
        }

        return ['text' => implode('', $parts), 'map' => $map];
    }

    /**
     * Masks several named fields of one item with tokens unique across all of
     * them, remembering which field each token belongs to.
     *
     * @param  array<string, string>  $fields
     * @param  list<array{source: string, target: string}>  $names
     * @return array{fields: array<string, string>, map: array<string, array{original: string, restore: string, kind: string}>, owners: array<string, string>}
     */
    public function maskFields(array $fields, array $names = []): array
    {
        $this->reset();

        $out = [];
        $map = [];
        $owners = [];

        foreach ($fields as $field => $text) {
            $masked = $this->mask($text, $names);
            $out[$field] = $masked['text'];

            foreach ($masked['map'] as $token => $entry) {
                $map[$token] = $entry;
                $owners[$token] = $field;
            }
        }

        return ['fields' => $out, 'map' => $map, 'owners' => $owners];
    }

    /**
     * Puts every protected span back. Names come back in their fixed target
     * form; everything else exactly as it was.
     *
     * @param  array<string, array{original: string, restore: string, kind: string}>  $map
     */
    public function unmask(string $text, array $map): string
    {
        $text = $this->normalise($text);

        // strtr matches the longest key first, so __PH_1__ never eats the
        // front of __PH_12__.
        return strtr($text, array_map(static fn (array $e): string => $e['restore'], $map));
    }

    /**
     * Local models sometimes treat __x__ as Markdown bold and hand back
     * **PH_3** or a token with a stray space. Those are unambiguous, so they
     * are repaired rather than counted as lost.
     */
    public function normalise(string $text): string
    {
        return (string) preg_replace('/(?:_{1,2}|\*{2})\s?PH[_ ]?(\d+)\s?(?:_{1,2}|\*{2})/', '__PH_$1__', $text);
    }

    /**
     * Checks that each expected token appears exactly once, and that nothing
     * appears that was never issued.
     *
     * @param  list<string>  $expected
     * @return array{missing: list<string>, duplicated: list<string>, unexpected: list<string>}
     */
    public function placeholderProblems(string $text, array $expected): array
    {
        preg_match_all(self::TOKEN, $this->normalise($text), $m);
        $counts = array_count_values($m[0]);

        $missing = [];
        $duplicated = [];

        foreach ($expected as $token) {
            $n = $counts[$token] ?? 0;

            if ($n === 0) {
                $missing[] = $token;
            } elseif ($n > 1) {
                $duplicated[] = $token;
            }
        }

        $unexpected = array_values(array_diff(array_keys($counts), $expected));

        return ['missing' => $missing, 'duplicated' => $duplicated, 'unexpected' => $unexpected];
    }

    /** True when a model left any token, canonical or mangled, in final text. */
    public function hasLeftoverTokens(string $text): bool
    {
        return preg_match('/_{1,2}\s?PH[_ ]?\d+|\bPH_\d+\b/', $text) === 1;
    }

    /**
     * @param  list<array{source: string, target: string}>  $names
     * @param  array<string, array{original: string, restore: string, kind: string}>  $map
     */
    private function maskNames(string $text, array $names, array &$map): string
    {
        $names = array_values(array_filter($names, static fn (array $n): bool => trim($n['source']) !== ''));

        if ($names === []) {
            return $text;
        }

        // Longest first, so "Tamil Nadu" is taken whole before "Tamil".
        usort($names, static fn (array $a, array $b): int => mb_strlen($b['source']) <=> mb_strlen($a['source']));

        $targets = [];
        $byEdge = [];

        foreach ($names as $name) {
            $targets[$name['source']] ??= $name['target'];

            // Grouped by the right edge their script needs — a strict word
            // end for English, a word end or a case ending for Tamil, so
            // சேரர் never matches inside சேர்மம் — with one edge per group,
            // not per name, to keep the pattern within PCRE's size limit.
            $byEdge[WordEdges::after($name['source'])][] = preg_quote($name['source'], '/');
        }

        $groups = [];

        foreach ($byEdge as $edge => $alternatives) {
            $groups[] = '(?:'.implode('|', $alternatives).')'.$edge;
        }

        // No /i: matched exactly as written, capitals included. "Erode" is a
        // town; "erode" is what rivers do to rocks.
        $pattern = '/(?<![\p{L}\p{M}\p{N}_])(?:'.implode('|', $groups).')/u';

        return (string) preg_replace_callback(
            $pattern,
            function (array $m) use (&$map, $targets): string {
                return $this->issue($m[0], $targets[$m[0]] ?? $m[0], 'name', $map);
            },
            $text,
        );
    }

    /** @param  array<string, array{original: string, restore: string, kind: string}>  $map */
    private function issue(string $original, string $restore, string $kind, array &$map): string
    {
        $token = '__PH_'.(++$this->issued).'__';
        $map[$token] = ['original' => $original, 'restore' => $restore, 'kind' => $kind];

        return $token;
    }

    /** @param  array<int|string, string>  $m */
    private function kindOf(array $m): ?string
    {
        foreach (['url', 'equation', 'date', 'number'] as $kind) {
            if (($m[$kind] ?? '') !== '') {
                return $kind;
            }
        }

        if (($m['formula'] ?? '') !== '') {
            return $this->isFormula($m['formula']) ? 'formula' : null;
        }

        return null;
    }

    /**
     * A lone run of element symbols is only a formula when something marks it
     * as one: a count (H2O, CO₂), a charge (Na⁺) or two symbols at least one
     * of which is two letters (NaCl, HCl). Otherwise "In", "I" or "No" at the
     * start of a sentence would be hidden from the translator.
     */
    private function isFormula(string $candidate): bool
    {
        $body = ltrim($candidate, '0123456789');

        if (preg_match('/[0-9₀-₉⁺⁻]/u', $body) === 1) {
            return true;
        }

        return preg_match_all('/[A-Z]/', $body) >= 2 && preg_match('/[A-Z][a-z]/', $body) === 1;
    }

    /**
     * A URL pattern is greedy about trailing punctuation; the full stop that
     * ends the sentence belongs to the sentence.
     *
     * @return array{0: string, 1: string}
     */
    private function splitUrl(string $url): array
    {
        $trimmed = rtrim($url, '.,;:!?)\'"');

        return [$trimmed, substr($url, strlen($trimmed))];
    }

    private function pattern(): string
    {
        if ($this->pattern !== null) {
            return $this->pattern;
        }

        $elements = implode('|', self::ELEMENTS);
        $count = '[0-9₀-₉]*';
        $group = "(?:(?:{$elements}){$count}|\((?:(?:{$elements}){$count})+\){$count})";
        $formula = "[0-9]*(?:{$group})+(?:(?:[·•]|\.(?=[0-9]))[0-9]*(?:{$group})+)?"
            .'(?:[⁰¹²³⁴⁵⁶⁷⁸⁹]*[⁺⁻])?(?:\((?:aq|s|l|g)\))?';
        $arrow = '(?:→|⟶|-->|->|=>|⇌|⇄|<=>|=)';
        $equation = "{$formula}(?:\s*\+\s*{$formula})*\s*{$arrow}\s*{$formula}(?:\s*\+\s*{$formula})*";

        $units = self::UNITS;
        usort($units, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        // A one-letter unit must stand apart from its number ("10 s"), or
        // the decade in "the 1940s" would be read as 1940 seconds.
        $quote = static fn (array $list): string => implode('|', array_map(static fn (string $u): string => preg_quote($u, '/'), $list));
        $single = array_filter($units, static fn (string $u): bool => preg_match('/^[A-Za-z]$/', $u) === 1);
        $long = $quote(array_diff($units, $single));
        $short = $quote($single);

        // Indian grouping (1,26,000) before Western (126,000) before plain.
        // A leading sign or decimal point belongs to the number: masking
        // "5" out of "-5 °C" would let a translation that dropped the minus
        // pass every check.
        $digits = '(?:(?:\d{1,3}(?:,\d{2})+,\d{3}|\d{1,3}(?:,\d{3})+|\d+)(?:\.\d+)?|\.\d+)';
        $number = "(?:[₹$]\s?)?(?:[-−+](?=[.\d]))?{$digits}(?:\/\d+)?(?:(?:\s?(?:{$long})|\s(?:{$short}))(?![\p{L}\p{N}]))?";

        $date = '(?:\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}|\d{4}-\d{2}-\d{2}|\d{4}\s?[–\-]\s?\d{2,4})';

        $before = '(?<![\p{L}\p{M}\p{N}_])';

        // A formula may be followed straight away by a Tamil case ending
        // (CO₂வின்), but never by more Latin letters or digits.
        $chemistryAfter = '(?![\p{Latin}\p{N}_])';

        return $this->pattern = '/'
            .'(?<url>(?:https?:\/\/|www\.)[^\s<>"]+)'
            ."|{$before}(?<equation>{$equation}){$chemistryAfter}"
            ."|{$before}(?<date>{$date})(?!\p{N})"
            ."|{$before}(?<formula>{$formula}){$chemistryAfter}"
            ."|{$before}(?<number>{$number})(?!\p{N})"
            .'/u';
    }
}
