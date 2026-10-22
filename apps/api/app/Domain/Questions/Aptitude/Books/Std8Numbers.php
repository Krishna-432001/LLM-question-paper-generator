<?php

declare(strict_types=1);

namespace App\Domain\Questions\Aptitude\Books;

use App\Domain\Questions\Aptitude\AptitudeBook;
use Random\Randomizer;

/** Squares, cubes, their roots and the laws of exponents, from the Std 8 Mathematics book (Chapter 1). */
final class Std8Numbers extends AptitudeBook
{
    private const SIMPLIFY = 'simplification-and-percentage';

    private const SERIES = 'logical-reasoning-and-series';

    private const PRODUCT_LAW = 'Product law: According to this law, when multiplying two powers that have the same base, we can add the exponents.';

    public function source(): string
    {
        return 'Std 8 Mathematics Term-Full (English)';
    }

    public function breadcrumb(): string
    {
        return 'Std 8 Mathematics (English) — Chapter 1: Numbers (Squares, Cubes and Exponents)';
    }

    public function file(): string
    {
        return 'aptitude-std8-numbers.json';
    }

    protected function templates(): array
    {
        return [
            'square-root' => fn (Randomizer $r): ?array => $this->squareRoot($r),
            'cube-root' => fn (Randomizer $r): ?array => $this->cubeRoot($r),
            'cube-multiplier' => fn (Randomizer $r): ?array => $this->cubeMultiplier($r),
            'product-law' => fn (Randomizer $r): ?array => $this->productLaw($r),
            'quotient-law' => fn (Randomizer $r): ?array => $this->quotientLaw($r),
            'squares-series' => fn (Randomizer $r): ?array => $this->squaresSeries($r),
            'cubes-series' => fn (Randomizer $r): ?array => $this->cubesSeries($r),
        ];
    }

    private function squareRoot(Randomizer $r): ?array
    {
        $k = $r->getInt(11, 99);
        $n = $k * $k;

        return $this->draft(self::SIMPLIFY, 'easy', 27,
            'The square root of a number n, written √n, is the number that gives n when multiplied by itself.',
            "Find the square root of {$n}.",
            "{$n} இன் வர்க்கமூலம் காண்க.",
            "{$k} × {$k} = {$n}, so √{$n} = {$k}.",
            "{$k} × {$k} = {$n}; எனவே √{$n} = {$k}.",
            $k, [$k + 1, $k - 1, 2 * $k, intdiv($n, 2)],
            $this->plain(), $this->plain(),
        );
    }

    private function cubeRoot(Randomizer $r): ?array
    {
        $k = $r->getInt(2, 30);
        $n = $k ** 3;

        return $this->draft(self::SIMPLIFY, 'easy', 37,
            'The cube root of a number is the value that when cubed gives the original number.',
            "Find the cube root of {$n}.",
            "{$n} இன் கனமூலம் காண்க.",
            "{$k} × {$k} × {$k} = {$n}, so ∛{$n} = {$k}.",
            "{$k} × {$k} × {$k} = {$n}; எனவே ∛{$n} = {$k}.",
            $k, [$k + 1, $k - 1, $k * $k, intdiv($n, 3)],
            $this->plain(), $this->plain(),
        );
    }

    private function cubeMultiplier(Randomizer $r): ?array
    {
        $k = $r->getInt(2, 12);
        $p = $this->pick($r, [2, 3, 5, 7, 11]);
        $twice = $r->getInt(0, 1) === 1;
        $extra = $twice ? $p * $p : $p;
        $n = $k ** 3 * $extra;
        $multiplier = $twice ? $p : $p * $p;

        if ($k % $p === 0 || $n > 50000) {
            return null;
        }

        return $this->draft(self::SIMPLIFY, 'hard', 38,
            'Find the smallest number by which 675 must be multiplied to obtain a perfect cube.',
            "Find the smallest number by which {$n} must be multiplied to obtain a perfect cube.",
            "{$n} ஐ எந்த மிகச் சிறிய எண்ணால் பெருக்கினால் அது ஒரு முழுக் கனம் ஆகும்?",
            "{$n} = {$this->factors($n)}. To make every prime occur in threes, {$p} needs ".($twice ? 'one more factor' : 'two more factors').", so multiply by {$multiplier}.",
            "{$n} = {$this->factors($n)}. ஒவ்வொரு பகா எண்ணும் மும்மூன்றாக வர {$p} க்கு ".($twice ? 'இன்னும் ஒரு காரணி' : 'இன்னும் இரண்டு காரணிகள்')." தேவை; எனவே {$multiplier} ஆல் பெருக்க வேண்டும்.",
            $multiplier, [$extra, $p ** 3, $k, $multiplier * 2],
            $this->plain(), $this->plain(),
        );
    }

    private function productLaw(Randomizer $r): ?array
    {
        $a = $this->pick($r, [2, 3, 5, 10]);
        $m = $r->getInt(1, 6);
        $n = $r->getInt(1, 6);
        $value = $a ** ($m + $n);

        if ($value > 1000000 || $m === $n && $r->getInt(0, 1) === 0) {
            return null;
        }

        return $this->draft(self::SIMPLIFY, 'medium', 41, self::PRODUCT_LAW,
            "Find the value of {$a}{$this->sup($m)} × {$a}{$this->sup($n)}.",
            "{$a}{$this->sup($m)} × {$a}{$this->sup($n)} இன் மதிப்பு காண்க.",
            "aᵐ × aⁿ = aᵐ⁺ⁿ, so {$a}{$this->sup($m)} × {$a}{$this->sup($n)} = {$a}{$this->sup($m + $n)} = {$value}.",
            "aᵐ × aⁿ = aᵐ⁺ⁿ; எனவே {$a}{$this->sup($m)} × {$a}{$this->sup($n)} = {$a}{$this->sup($m + $n)} = {$value}.",
            $value, [$m * $n <= 9 ? $a ** ($m * $n) : $value * $a * $a, $a ** ($m + $n - 1), $a ** $m + $a ** $n, $value * $a],
            $this->plain(), $this->plain(),
        );
    }

    private function quotientLaw(Randomizer $r): ?array
    {
        $a = $this->pick($r, [2, 3, 5, 10]);
        $m = $r->getInt(3, 12);
        $n = $r->getInt(1, 8);

        if ($n >= $m || $a ** ($m - $n) > 1000000 || $a ** $m > 100000000) {
            return null;
        }

        $value = $a ** ($m - $n);

        return $this->draft(self::SIMPLIFY, 'medium', 41, 'Quotient law: aᵐ ÷ aⁿ = aᵐ⁻ⁿ',
            "Find the value of {$a}{$this->sup($m)} ÷ {$a}{$this->sup($n)}.",
            "{$a}{$this->sup($m)} ÷ {$a}{$this->sup($n)} இன் மதிப்பு காண்க.",
            "aᵐ ÷ aⁿ = aᵐ⁻ⁿ, so {$a}{$this->sup($m)} ÷ {$a}{$this->sup($n)} = {$a}{$this->sup($m - $n)} = {$value}.",
            "aᵐ ÷ aⁿ = aᵐ⁻ⁿ; எனவே {$a}{$this->sup($m)} ÷ {$a}{$this->sup($n)} = {$a}{$this->sup($m - $n)} = {$value}.",
            $value, [$a ** ($m + $n) > 100000000 ? $value * $a * $a : $a ** ($m + $n), $a ** max(1, $m - $n - 1), $value * $a, $m - $n],
            $this->plain(), $this->plain(),
        );
    }

    private function squaresSeries(Randomizer $r): ?array
    {
        $k = $r->getInt(1, 20);
        $terms = array_map(fn (int $i): int => ($k + $i) ** 2, range(0, 4));
        $next = ($k + 5) ** 2;
        $list = implode(', ', $terms);

        return $this->draft(self::SERIES, 'easy', 25, 'This is a square of side 2 units. It is 2 squared.',
            "Find the next number in the series: {$list}, ?",
            "பின்வரும் தொடரில் அடுத்த எண் எது? {$list}, ?",
            'These are the squares '.$k.'², '.($k + 1).'², '.($k + 2).'², '.($k + 3).'², '.($k + 4).'²; the next is '.($k + 5)."² = {$next}.",
            'இவை '.$k.'², '.($k + 1).'², '.($k + 2).'², '.($k + 3).'², '.($k + 4).'² ஆகிய வர்க்க எண்கள்; அடுத்தது '.($k + 5)."² = {$next}.",
            $next, [$terms[4] + ($terms[4] - $terms[3]), ($k + 6) ** 2, $next - 1, $next + 1],
            $this->plain(), $this->plain(),
        );
    }

    private function cubesSeries(Randomizer $r): ?array
    {
        $k = $r->getInt(1, 10);
        $terms = array_map(fn (int $i): int => ($k + $i) ** 3, range(0, 3));
        $next = ($k + 4) ** 3;
        $list = implode(', ', $terms);

        return $this->draft(self::SERIES, 'medium', 36, 'Cube numbers are also called as perfect cubes.',
            "Find the next number in the series: {$list}, ?",
            "பின்வரும் தொடரில் அடுத்த எண் எது? {$list}, ?",
            'These are the cubes '.$k.'³, '.($k + 1).'³, '.($k + 2).'³, '.($k + 3).'³; the next is '.($k + 4)."³ = {$next}.",
            'இவை '.$k.'³, '.($k + 1).'³, '.($k + 2).'³, '.($k + 3).'³ ஆகிய கன எண்கள்; அடுத்தது '.($k + 4)."³ = {$next}.",
            $next, [$terms[3] + ($terms[3] - $terms[2]), ($k + 4) ** 2, ($k + 5) ** 3, $next - 1],
            $this->plain(), $this->plain(),
        );
    }
}
