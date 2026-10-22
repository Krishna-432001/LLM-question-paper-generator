<?php

declare(strict_types=1);

namespace App\Domain\Questions\Aptitude\Books;

use App\Domain\Questions\Aptitude\AptitudeBook;
use Random\Randomizer;

/**
 * Order of operations, number patterns, ratio, proportion and the unitary
 * method, from the Std 6 Mathematics Term-I book (Chapters 1–3).
 */
final class Std6Term1 extends AptitudeBook
{
    private const SIMPLIFY = 'simplification-and-percentage';

    private const RATIO = 'ratio-proportion-and-averages';

    private const SERIES = 'logical-reasoning-and-series';

    public function source(): string
    {
        return 'Std 6 Mathematics Term-I (English)';
    }

    public function breadcrumb(): string
    {
        return 'Std 6 Mathematics Term-I (English) — Chapters 1–3: Numbers, Patterns, Ratio and Proportion';
    }

    public function file(): string
    {
        return 'aptitude-std6-term1.json';
    }

    protected function templates(): array
    {
        return [
            'bidmas' => fn (Randomizer $r): ?array => $this->bidmas($r),
            'bidmas-brackets' => fn (Randomizer $r): ?array => $this->bidmasBrackets($r),
            'pattern-steady' => fn (Randomizer $r): ?array => $this->steadyPattern($r),
            'pattern-growing' => fn (Randomizer $r): ?array => $this->growingPattern($r),
            'ratio-units' => fn (Randomizer $r): ?array => $this->ratioUnits($r),
            'ratio-divide' => fn (Randomizer $r): ?array => $this->divide($r),
            'proportion-missing' => fn (Randomizer $r): ?array => $this->missingTerm($r),
            'unitary' => fn (Randomizer $r): ?array => $this->unitary($r),
        ];
    }

    private function bidmas(Randomizer $r): ?array
    {
        $a = $r->getInt(2, 60);
        $b = $r->getInt(2, 15);
        $c = $r->getInt(2, 12);
        $e = $r->getInt(2, 9);
        $d = $e * $r->getInt(2, 12);
        $answer = $a + $b * $c - intdiv($d, $e);
        $leftToRight = (($a + $b) * $c - $d) / $e;

        if ($answer <= 0) {
            return null;
        }

        return $this->draft(self::SIMPLIFY, 'easy', 16,
            'The rule of order of operations is called as BIDMAS. In BIDMAS the operations done from left to right.',
            "Simplify using BIDMAS: {$a} + {$b} × {$c} − {$d} ÷ {$e}",
            "BIDMAS விதிப்படி சுருக்குக: {$a} + {$b} × {$c} − {$d} ÷ {$e}",
            "Multiplication and division come before addition and subtraction: {$b} × {$c} = ".($b * $c)." and {$d} ÷ {$e} = ".intdiv($d, $e).", so {$a} + ".($b * $c).' − '.intdiv($d, $e)." = {$answer}.",
            "பெருக்கல், வகுத்தல் முதலில்: {$b} × {$c} = ".($b * $c).", {$d} ÷ {$e} = ".intdiv($d, $e)."; எனவே {$a} + ".($b * $c).' − '.intdiv($d, $e)." = {$answer}.",
            $answer, [$leftToRight, $a + $b * $c - $d, ($a + $b) * $c - intdiv($d, $e), $answer + $e],
            $this->plain(), $this->plain(),
        );
    }

    private function bidmasBrackets(Randomizer $r): ?array
    {
        $a = $r->getInt(2, 20);
        $b = $r->getInt(2, 20);
        $c = $r->getInt(2, 9);
        $d = $r->getInt(2, 40);
        $answer = ($a + $b) * $c - $d;
        $noBrackets = $a + $b * $c - $d;

        if ($answer <= 0 || $noBrackets === $answer) {
            return null;
        }

        return $this->draft(self::SIMPLIFY, 'easy', 16,
            'The rule of order of operations is called as BIDMAS. In BIDMAS the operations done from left to right.',
            "Simplify: ({$a} + {$b}) × {$c} − {$d}",
            "சுருக்குக: ({$a} + {$b}) × {$c} − {$d}",
            "Brackets first: {$a} + {$b} = ".($a + $b).', then '.($a + $b)." × {$c} = ".(($a + $b) * $c).', and '.(($a + $b) * $c)." − {$d} = {$answer}.",
            "அடைப்புக்குறி முதலில்: {$a} + {$b} = ".($a + $b).'; பின் '.($a + $b)." × {$c} = ".(($a + $b) * $c).'; '.(($a + $b) * $c)." − {$d} = {$answer}.",
            $answer, [$noBrackets, ($a + $b) * ($c - $d), $answer + $c, $answer - $c],
            $this->plain(), $this->plain(),
            signed: true,
        );
    }

    private function steadyPattern(Randomizer $r): ?array
    {
        $start = $r->getInt(5, 200);
        $step = $r->getInt(2, 15) * ($r->getInt(0, 1) === 1 ? 1 : -1);
        $terms = array_map(fn (int $k): int => $start + $k * $step, range(0, 4));
        $next = $start + 5 * $step;

        if ($next <= 0) {
            return null;
        }

        $list = implode(', ', $terms);
        $verbEn = $step > 0 ? 'adding '.$step.' to' : 'subtracting '.abs($step).' from';
        $verbTa = $step > 0 ? 'முந்தைய எண்ணுடன் '.$step.' கூட்டுவதால்' : 'முந்தைய எண்ணிலிருந்து '.abs($step).' கழிப்பதால்';

        return $this->draft(self::SERIES, 'easy', 33,
            'Easy, each number is obtained by subtracting 4 from the previous number.',
            "Find the next number in the pattern: {$list}, ?",
            "பின்வரும் எண் அமைப்பில் அடுத்த எண் எது? {$list}, ?",
            "Each number is obtained by {$verbEn} the previous number, so the next number is {$next}.",
            "ஒவ்வோர் எண்ணும் {$verbTa} கிடைக்கிறது; எனவே அடுத்த எண் {$next}.",
            $next, [$next + $step, $terms[4] - $step, $next + 1, $next - 1],
            $this->plain(), $this->plain(),
        );
    }

    private function growingPattern(Randomizer $r): ?array
    {
        $start = $r->getInt(1, 60);
        $gap = $r->getInt(1, 9);
        $grow = $r->getInt(1, 4);
        $terms = [$start];
        for ($k = 0; $k < 4; $k++) {
            $terms[] = end($terms) + $gap + $k * $grow;
        }
        $next = end($terms) + $gap + 4 * $grow;
        $list = implode(', ', $terms);

        return $this->draft(self::SERIES, 'medium', 33,
            'Easy, each number is obtained by subtracting 4 from the previous number.',
            "Find the next number in the pattern: {$list}, ?",
            "பின்வரும் எண் அமைப்பில் அடுத்த எண் எது? {$list}, ?",
            'The differences are '.$gap.', '.($gap + $grow).', '.($gap + 2 * $grow).', '.($gap + 3 * $grow).' — each grows by '.$grow.'. The next difference is '.($gap + 4 * $grow).", so the next number is {$next}.",
            'வேறுபாடுகள் '.$gap.', '.($gap + $grow).', '.($gap + 2 * $grow).', '.($gap + 3 * $grow).' — ஒவ்வொன்றும் '.$grow.' அதிகரிக்கிறது. அடுத்த வேறுபாடு '.($gap + 4 * $grow)."; எனவே அடுத்த எண் {$next}.",
            $next, [end($terms) + $gap + 3 * $grow, end($terms) + $gap, $next + $grow, $next - 1],
            $this->plain(), $this->plain(),
        );
    }

    private function ratioUnits(Randomizer $r): ?array
    {
        // [small unit, big unit, factor, Tamil small, Tamil big]
        [$small, $big, $factor, $taSmall, $taBig] = $this->pick($r, [
            ['cm', 'm', 100, 'செ.மீ', 'மீ'],
            ['g', 'kg', 1000, 'கி', 'கிகி'],
            ['paise', 'rupees', 100, 'காசு', 'ரூபாய்'],
            ['minutes', 'hours', 60, 'நிமிடங்கள்', 'மணி நேரம்'],
            ['ml', 'litres', 1000, 'மி.லி', 'லிட்டர்'],
        ]);
        $bigCount = $r->getInt(1, 9);
        $smallCount = $this->pick($r, [5, 10, 15, 20, 25, 30, 40, 45, 50, 60, 75, 80, 90, 120, 125, 150, 200, 250, 300, 400, 500, 600, 750]);

        if ($smallCount >= $bigCount * $factor) {
            return null;
        }

        $g = $this->gcd($smallCount, $bigCount * $factor);
        $p = intdiv($smallCount, $g);
        $q = intdiv($bigCount * $factor, $g);
        $raw = $this->gcd($smallCount, $bigCount);
        $wrong = [
            "{$p} : {$q}",
            "{$q} : {$p}",
            intdiv($smallCount, $raw).' : '.intdiv($bigCount, $raw),
            $p.' : '.($q * 10),
            ($p * 10).' : '.$q,
            ($p + 1).' : '.$q,
        ];

        return $this->textDraft(self::RATIO, 'medium', 48, 'A ratio is a comparison of two quantities with same units.',
            "Express the ratio of {$smallCount} {$small} to {$bigCount} {$big} in its simplest form.",
            "{$smallCount} {$taSmall}, {$bigCount} {$taBig} ஆகியவற்றுக்கு இடையிலான விகிதத்தைச் சுருக்கிய வடிவில் எழுதுக.",
            "Write both in the same unit: {$bigCount} {$big} = ".($bigCount * $factor)." {$small}. Then {$smallCount} : ".($bigCount * $factor)." = {$p} : {$q}.",
            "இரண்டையும் ஒரே அலகில் எழுதுக: {$bigCount} {$taBig} = ".($bigCount * $factor)." {$taSmall}. பின் {$smallCount} : ".($bigCount * $factor)." = {$p} : {$q}.",
            $wrong,
        );
    }

    private function divide(Randomizer $r): ?array
    {
        $a = $r->getInt(1, 9);
        $b = $r->getInt(1, 9);

        if ($a === $b || $this->gcd($a, $b) !== 1) {
            return null;
        }

        $total = ($a + $b) * $r->getInt(2, 80) * 10;
        $second = $total * $b / ($a + $b);

        return $this->draft(self::RATIO, 'easy', 54,
            'Kumaran has ₹600 and wants to divide it between Vimala and Yazhini in the ratio 2 : 3.',
            "Kumaran divides ₹{$total} between Vimala and Yazhini in the ratio {$a} : {$b}. How much does Yazhini get?",
            "குமரன் ₹{$total} ஐ விமலா, யாழினி இருவருக்கும் {$a} : {$b} என்ற விகிதத்தில் பிரித்துக் கொடுக்கிறார். யாழினிக்குக் கிடைக்கும் தொகை எவ்வளவு?",
            "Total parts = {$a} + {$b} = ".($a + $b).", so Yazhini's share = {$b}/".($a + $b)." × {$total} = ₹".$this->n($second).'.',
            'மொத்தப் பங்குகள் = '.$a.' + '.$b.' = '.($a + $b).'; யாழினியின் பங்கு = '.$b.'/'.($a + $b)." × {$total} = ₹".$this->n($second).'.',
            $second, [$total * $a / ($a + $b), $total / 2, $total / ($a + $b), $total * $b / $a],
            $this->rupees(), $this->rupees(),
        );
    }

    private function missingTerm(Randomizer $r): ?array
    {
        $a = $r->getInt(2, 15);
        $b = $r->getInt(2, 20);
        $k = $r->getInt(2, 12);
        $c = $a * $k;
        $x = $b * $k;

        if ($a === $b) {
            return null;
        }

        return $this->draft(self::RATIO, 'easy', 56,
            'If two ratios are in proportion ie., a : b : : c : d then the product of the extremes is equal to the product of the means.',
            "If {$a} : {$b} :: {$c} : x, find x.",
            "{$a} : {$b} :: {$c} : x எனில், x இன் மதிப்பு காண்க.",
            "Product of extremes = product of means: {$a} × x = {$b} × {$c}, so x = ".($b * $c)." ÷ {$a} = {$x}.",
            "கோடி உறுப்புகளின் பெருக்கற்பலன் = நடு உறுப்புகளின் பெருக்கற்பலன்: {$a} × x = {$b} × {$c}; எனவே x = ".($b * $c)." ÷ {$a} = {$x}.",
            $x, [$a * $c / $b, $b + $c - $a, $c * $b, $x + $k],
            $this->plain(), $this->plain(),
        );
    }

    private function unitary(Randomizer $r): ?array
    {
        [$en, $ta] = $this->pick($r, [
            ['notebooks', 'நோட்டுப் புத்தகங்கள்'], ['pens', 'பேனாக்கள்'], ['chairs', 'நாற்காலிகள்'],
            ['kg of rice', 'கிகி அரிசி'], ['metres of cloth', 'மீட்டர் துணி'], ['books', 'புத்தகங்கள்'],
        ]);
        $n = $r->getInt(2, 15);
        $m = $r->getInt(2, 25);
        $unit = $r->getInt(2, 60) * 5;

        if ($n === $m) {
            return null;
        }

        $cost = $n * $unit;
        $answer = $m * $unit;

        return $this->draft(self::RATIO, 'easy', 57,
            'Finding the value of one unit and then using it to find the value of the required number of units is known as unitary method.',
            "If {$n} {$en} cost ₹{$cost}, what is the cost of {$m} {$en}?",
            "{$n} {$ta} விலை ₹{$cost} எனில், {$m} {$ta} விலை என்ன?",
            "Cost of 1 = {$cost} ÷ {$n} = ₹{$unit}, so the cost of {$m} = {$m} × {$unit} = ₹{$answer}.",
            "1 இன் விலை = {$cost} ÷ {$n} = ₹{$unit}; எனவே {$m} இன் விலை = {$m} × {$unit} = ₹{$answer}.",
            $answer, [$cost + ($m - $n), $unit * ($m + 1), $unit * ($m - 1), $cost * $m],
            $this->rupees(), $this->rupees(),
        );
    }
}
