<?php

declare(strict_types=1);

namespace App\Domain\Questions\Aptitude;

use Closure;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The machinery shared by the number-varied aptitude sets. A set is a list of
 * methods; each turns a random source into a draft question (or null when the
 * numbers drawn do not give a clean one). A draft becomes a question with
 * three wrong options taken from likely mistakes, and the answer sits at a
 * letter fixed by the wording, so the same seed always gives the same file and
 * a larger count only adds questions.
 */
trait BuildsAptitudeQuestions
{
    /**
     * @param  array<string, Closure(Randomizer): ?array<string, mixed>>  $templates
     * @return list<array<string, mixed>>
     */
    protected function fromTemplates(array $templates, int $perTemplate, int $seed): array
    {
        $questions = [];

        foreach ($templates as $name => $template) {
            // Each method draws from its own stream, so adding a method later
            // does not change the questions the others already made.
            $random = new Randomizer(new Mt19937($seed ^ crc32($name)));
            $seen = [];

            for ($tries = 0; count($seen) < $perTemplate && $tries < $perTemplate * 60; $tries++) {
                $draft = $template($random);

                if ($draft === null || isset($seen[$draft['en_q']])) {
                    continue;
                }

                $question = $this->finish($draft);

                if ($question === null) {
                    continue;
                }

                $seen[$draft['en_q']] = true;
                $questions[] = $question;
            }
        }

        return $questions;
    }

    /**
     * A question whose options are numbers.
     *
     * @param  list<int|float>  $wrong  likely mistakes, best first
     * @return array<string, mixed>
     */
    protected function draft(
        string $topic, string $difficulty, int $page, string $quote,
        string $enQ, string $taQ, string $enX, string $taX,
        int|float $answer, array $wrong, Closure $formatEn, Closure $formatTa,
        bool $signed = false,
    ): array {
        return [
            'topic' => $topic, 'difficulty' => $difficulty, 'page' => $page, 'quote' => $quote,
            'en_q' => $enQ, 'ta_q' => $taQ, 'en_x' => $enX, 'ta_x' => $taX,
            'answer' => $answer, 'wrong' => $wrong, 'en_fmt' => $formatEn, 'ta_fmt' => $formatTa, 'signed' => $signed,
        ];
    }

    /**
     * A question whose options are written out, the correct one first. Tamil
     * options default to the English ones (ratios and other symbols).
     *
     * @param  list<string>  $enOptions
     * @param  list<string>|null  $taOptions
     * @return array<string, mixed>
     */
    protected function textDraft(
        string $topic, string $difficulty, int $page, string $quote,
        string $enQ, string $taQ, string $enX, string $taX,
        array $enOptions, ?array $taOptions = null,
    ): array {
        return [
            'topic' => $topic, 'difficulty' => $difficulty, 'page' => $page, 'quote' => $quote,
            'en_q' => $enQ, 'ta_q' => $taQ, 'en_x' => $enX, 'ta_x' => $taX,
            'text' => true, 'en_options' => $enOptions, 'ta_options' => $taOptions ?? $enOptions,
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    protected function finish(array $draft): ?array
    {
        $slot = crc32($draft['en_q']) % 4;

        if ($draft['text'] ?? false) {
            $en = [];
            $ta = [];
            $seenEn = [];
            $seenTa = [];

            foreach ($draft['en_options'] as $i => $option) {
                $tamil = $draft['ta_options'][$i] ?? $option;

                if (isset($seenEn['k'.$option]) || isset($seenTa['k'.$tamil])) {
                    continue;
                }

                $seenEn['k'.$option] = true;
                $seenTa['k'.$tamil] = true;
                $en[] = $option;
                $ta[] = $tamil;

                if (count($en) === 4) {
                    break;
                }
            }

            if (count($en) < 4) {
                return null;
            }

            $rightEn = array_shift($en);
            $rightTa = array_shift($ta);
            array_splice($en, $slot, 0, [$rightEn]);
            array_splice($ta, $slot, 0, [$rightTa]);

            return $this->question($draft, $slot, $en, $ta);
        }

        $answer = $draft['answer'];
        $key = fn (int|float $v): string => ($draft['en_fmt'])($v);
        $taken = [$key($answer) => true];
        $wrong = [];

        $fallback = [];
        $step = max(1, (int) round(abs((float) $answer) / 10));
        foreach ([1, -1, 2, -2, 3, -3, 4, 5] as $k) {
            $fallback[] = $answer + $k * $step;
        }

        foreach ([...$draft['wrong'], ...$fallback] as $value) {
            if (count($wrong) === 3) {
                break;
            }

            $value = round((float) $value, 2);

            if (! $draft['signed'] && $value <= 0) {
                continue;
            }

            // A decimal beside a whole-number answer gives the answer away.
            if ($this->whole($answer) && ! $this->whole($value)) {
                continue;
            }

            if (isset($taken[$key($value)])) {
                continue;
            }

            $taken[$key($value)] = true;
            $wrong[] = $value;
        }

        if (count($wrong) < 3) {
            return null;
        }

        $values = $wrong;
        array_splice($values, $slot, 0, [$answer]);

        return $this->question($draft, $slot, array_map($draft['en_fmt'], $values), array_map($draft['ta_fmt'], $values));
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  list<string>  $en
     * @param  list<string>  $ta
     * @return array<string, mixed>
     */
    protected function question(array $draft, int $slot, array $en, array $ta): array
    {
        return [
            'topic' => $draft['topic'],
            'format' => 'mcq4',
            'difficulty' => $draft['difficulty'],
            'bloom' => 'apply',
            'page' => $draft['page'],
            'quote' => $draft['quote'],
            'answer' => ['A', 'B', 'C', 'D'][$slot],
            'en' => ['q' => $draft['en_q'], 'options' => $en, 'explanation' => $draft['en_x']],
            'ta' => ['q' => $draft['ta_q'], 'options' => $ta, 'explanation' => $draft['ta_x']],
        ];
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    protected function pick(Randomizer $r, array $items): mixed
    {
        return $items[$r->getInt(0, count($items) - 1)];
    }

    protected function whole(int|float $value): bool
    {
        return abs($value - round($value)) < 1e-9;
    }

    /** A number as the book writes it: no grouping, at most two decimals. */
    protected function n(int|float $value): string
    {
        if ($this->whole($value)) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    /** Whether a value is exact to two decimals, so no rounding hides in the answer. */
    protected function clean(int|float $value): bool
    {
        return abs($value * 100 - round($value * 100)) < 1e-6;
    }

    protected function plain(): Closure
    {
        return fn (int|float $v): string => $this->n($v);
    }

    protected function rupees(): Closure
    {
        return fn (int|float $v): string => '₹'.$this->n($v);
    }

    protected function percent(): Closure
    {
        return fn (int|float $v): string => $this->n($v).'%';
    }

    /** A number followed by a fixed unit, e.g. " cm²". */
    protected function with(string $unit): Closure
    {
        return fn (int|float $v): string => $this->n($v).$unit;
    }

    protected function unit(string $plural, string $singular): Closure
    {
        return fn (int|float $v): string => $this->n($v).' '.($this->whole($v) && (int) round($v) === 1 ? $singular : $plural);
    }

    protected function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return abs($a);
    }

    protected function lcm(int $a, int $b): int
    {
        return intdiv($a * $b, $this->gcd($a, $b));
    }

    /** Prime factorisation written out, e.g. 60 → "2 × 2 × 3 × 5". */
    protected function factors(int $n): string
    {
        $parts = [];
        for ($p = 2; $p * $p <= $n; $p++) {
            while ($n % $p === 0) {
                $parts[] = $p;
                $n = intdiv($n, $p);
            }
        }
        if ($n > 1) {
            $parts[] = $n;
        }

        return implode(' × ', $parts);
    }

    /** An exponent in superscript digits. */
    protected function sup(int $n): string
    {
        return strtr((string) $n, ['0' => '⁰', '1' => '¹', '2' => '²', '3' => '³', '4' => '⁴', '5' => '⁵', '6' => '⁶', '7' => '⁷', '8' => '⁸', '9' => '⁹', '-' => '⁻']);
    }
}
