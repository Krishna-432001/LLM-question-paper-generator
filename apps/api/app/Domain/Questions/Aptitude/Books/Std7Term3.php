<?php

declare(strict_types=1);

namespace App\Domain\Questions\Aptitude\Books;

use App\Domain\Questions\Aptitude\AptitudeBook;
use Random\Randomizer;

/** Percentage, simple interest and the arithmetic mean, from the Std 7 Mathematics Term-III book (Chapters 2 and 5). */
final class Std7Term3 extends AptitudeBook
{
    private const PERCENT = 'simplification-and-percentage';

    private const MONEY = 'ratio-proportion-and-averages';

    private const PERCENT_QUOTE = 'That is 1% means 1 out of hundred';

    private const SI_QUOTE = 'we know that, I = Pnr';

    private const MEAN_QUOTE = 'Arithmetic Mean = Sum of all observations / Number of observations';

    public function source(): string
    {
        return 'Std 7 Mathematics Term-III (English)';
    }

    public function breadcrumb(): string
    {
        return 'Std 7 Mathematics Term-III (English) — Chapters 2 and 5: Percentage, Simple Interest and Statistics';
    }

    public function file(): string
    {
        return 'aptitude-std7-term3.json';
    }

    protected function templates(): array
    {
        return [
            'marks-percentage' => fn (Randomizer $r): ?array => $this->marks($r),
            'fraction-to-percent' => fn (Randomizer $r): ?array => $this->fraction($r),
            'si-amount' => fn (Randomizer $r): ?array => $this->amount($r),
            'si-principal' => fn (Randomizer $r): ?array => $this->principal($r),
            'si-rate' => fn (Randomizer $r): ?array => $this->rate($r),
            'mean' => fn (Randomizer $r): ?array => $this->mean($r),
            'mean-missing' => fn (Randomizer $r): ?array => $this->missing($r),
        ];
    }

    private function marks(Randomizer $r): ?array
    {
        $total = $this->pick($r, [25, 40, 50, 60, 75, 80, 120, 150, 200, 250, 300, 400, 500, 600]);
        $got = $r->getInt(1, $total - 1);
        $pct = $got * 100 / $total;

        if (! $this->whole($pct) || $pct < 20) {
            return null;
        }

        $name = $this->pick($r, [['Kavin', 'கவின்'], ['Malar', 'மலர்'], ['Arul', 'அருள்'], ['Nila', 'நிலா'], ['Selvi', 'செல்வி']]);

        return $this->draft(self::PERCENT, 'easy', 28, self::PERCENT_QUOTE,
            "{$name[0]} scored {$got} marks out of {$total}. What percentage of marks did {$name[0]} get?",
            "{$name[1]} {$total} மதிப்பெண்களுக்கு {$got} மதிப்பெண்கள் பெற்றார். அவர் பெற்ற மதிப்பெண் சதவீதம் என்ன?",
            "Percentage = {$got}/{$total} × 100 = ".$this->n($pct).'%.',
            "சதவீதம் = {$got}/{$total} × 100 = ".$this->n($pct).'%.',
            $pct, [100 - $pct, $got * $total / 100, $pct + 5, $pct - 5],
            $this->percent(), $this->percent(),
        );
    }

    private function fraction(Randomizer $r): ?array
    {
        $b = $this->pick($r, [2, 4, 5, 8, 10, 16, 20, 25, 40, 50]);
        $a = $r->getInt(1, $b - 1);

        if ($this->gcd($a, $b) !== 1) {
            return null;
        }

        $pct = $a * 100 / $b;

        return $this->draft(self::PERCENT, 'easy', 28, self::PERCENT_QUOTE,
            "Express {$a}/{$b} as a percentage.",
            "{$a}/{$b} ஐச் சதவீதமாக மாற்றுக.",
            "{$a}/{$b} × 100 = ".$this->n($pct).'%.',
            "{$a}/{$b} × 100 = ".$this->n($pct).'%.',
            $pct, [$a * $b, 100 - $pct, $pct / 10, $pct * 2],
            $this->percent(), $this->percent(),
        );
    }

    private function amount(Randomizer $r): ?array
    {
        $p = $r->getInt(2, 100) * 500;
        $n = $r->getInt(2, 6);
        $rate = $this->pick($r, [4, 5, 6, 8, 9, 10, 12, 15]);
        $i = $p * $n * $rate / 100;

        if (! $this->whole($i)) {
            return null;
        }

        $amount = $p + $i;

        return $this->draft(self::MONEY, 'medium', 43, self::SI_QUOTE,
            "Find the amount when ₹{$p} is lent at {$rate}% per annum simple interest for {$n} years.",
            "₹{$p} ஆண்டுக்கு {$rate}% தனிவட்டி வீதத்தில் {$n} ஆண்டுகளுக்குக் கடனாகக் கொடுக்கப்படுகிறது. மொத்தத் தொகை எவ்வளவு?",
            "I = P × n × r = {$p} × {$n} × {$rate}/100 = ₹".$this->n($i).", so amount = {$p} + ".$this->n($i).' = ₹'.$this->n($amount).'.',
            "வட்டி I = P × n × r = {$p} × {$n} × {$rate}/100 = ₹".$this->n($i)."; மொத்தத் தொகை = {$p} + ".$this->n($i).' = ₹'.$this->n($amount).'.',
            $amount, [$i, $p + $p * $rate / 100, $amount + $p * $rate / 100, $p * $n],
            $this->rupees(), $this->rupees(),
        );
    }

    private function principal(Randomizer $r): ?array
    {
        $p = $r->getInt(2, 100) * 500;
        $n = $r->getInt(2, 6);
        $rate = $this->pick($r, [4, 5, 6, 8, 10, 12, 15]);
        $i = $p * $n * $rate / 100;

        if (! $this->whole($i)) {
            return null;
        }

        return $this->draft(self::MONEY, 'medium', 43, self::SI_QUOTE,
            "What sum of money earns ₹".$this->n($i)." as simple interest in {$n} years at {$rate}% per annum?",
            "ஆண்டுக்கு {$rate}% வட்டி வீதத்தில் {$n} ஆண்டுகளில் ₹".$this->n($i).' தனிவட்டி தரும் அசல் எவ்வளவு?',
            "P = I × 100 ÷ (n × R) = ".$this->n($i)." × 100 ÷ ({$n} × {$rate}) = ₹{$p}.",
            "அசல் P = I × 100 ÷ (n × R) = ".$this->n($i)." × 100 ÷ ({$n} × {$rate}) = ₹{$p}.",
            $p, [$i * $n * $rate / 100, $p + $i, $i * 100 / $rate, $p / 2],
            $this->rupees(), $this->rupees(),
        );
    }

    private function rate(Randomizer $r): ?array
    {
        $p = $r->getInt(2, 80) * 500;
        $n = $r->getInt(2, 6);
        $rate = $this->pick($r, [4, 5, 6, 8, 10, 12, 15, 20]);
        $i = $p * $n * $rate / 100;

        if (! $this->whole($i)) {
            return null;
        }

        return $this->draft(self::MONEY, 'medium', 43, self::SI_QUOTE,
            "At what rate per cent per annum will ₹{$p} earn ₹".$this->n($i)." as simple interest in {$n} years?",
            "₹{$p} அசல் {$n} ஆண்டுகளில் ₹".$this->n($i).' தனிவட்டி தர, ஆண்டு வட்டி வீதம் எத்தனை சதவீதமாக இருக்க வேண்டும்?',
            "R = I × 100 ÷ (P × n) = ".$this->n($i)." × 100 ÷ ({$p} × {$n}) = {$rate}%.",
            "வட்டி வீதம் R = I × 100 ÷ (P × n) = ".$this->n($i)." × 100 ÷ ({$p} × {$n}) = {$rate}%.",
            $rate, [$rate * $n, $rate + 2, $rate / 2, $i * 100 / $p],
            $this->percent(), $this->percent(),
        );
    }

    private function mean(Randomizer $r): ?array
    {
        $count = $r->getInt(4, 7);
        $values = [];
        for ($k = 0; $k < $count; $k++) {
            $values[] = $r->getInt(5, 99);
        }
        $sum = array_sum($values);

        if ($sum % $count !== 0) {
            return null;
        }

        $mean = intdiv($sum, $count);
        $list = implode(', ', $values);
        sort($values);

        return $this->draft(self::MONEY, 'easy', 97, self::MEAN_QUOTE,
            "Find the arithmetic mean of {$list}.",
            "{$list} ஆகியவற்றின் கூட்டுச் சராசரி காண்க.",
            "Mean = sum of observations ÷ number of observations = {$sum} ÷ {$count} = {$mean}.",
            "சராசரி = தரவுகளின் கூடுதல் ÷ தரவுகளின் எண்ணிக்கை = {$sum} ÷ {$count} = {$mean}.",
            $mean, [$values[intdiv($count, 2)], $sum / ($count - 1), $mean + 1, $mean - 1],
            $this->plain(), $this->plain(),
        );
    }

    private function missing(Randomizer $r): ?array
    {
        $count = $r->getInt(4, 6);
        $mean = $r->getInt(10, 80);
        $known = [];
        for ($k = 0; $k < $count - 1; $k++) {
            $known[] = $r->getInt(max(1, $mean - 20), $mean + 20);
        }
        $missing = $mean * $count - array_sum($known);

        if ($missing <= 0 || $missing > 150) {
            return null;
        }

        $list = implode(', ', $known);

        return $this->draft(self::MONEY, 'medium', 97, self::MEAN_QUOTE,
            "The mean of {$count} numbers is {$mean}. If ".($count - 1)." of them are {$list}, find the remaining number.",
            "{$count} எண்களின் சராசரி {$mean}. அவற்றுள் ".($count - 1)." எண்கள் {$list} எனில், மீதமுள்ள எண் எது?",
            "Sum of all = {$mean} × {$count} = ".($mean * $count).'; sum of the known numbers = '.array_sum($known).", so the remaining number = {$missing}.",
            "மொத்தக் கூடுதல் = {$mean} × {$count} = ".($mean * $count).'; தெரிந்த எண்களின் கூடுதல் = '.array_sum($known)."; எனவே மீதமுள்ள எண் = {$missing}.",
            $missing, [$mean, round(array_sum($known) / ($count - 1)), $missing + $count, $missing - $count],
            $this->plain(), $this->plain(),
        );
    }
}
