<?php

declare(strict_types=1);

namespace App\Domain\Questions\Aptitude\Books;

use App\Domain\Questions\Aptitude\AptitudeBook;
use Random\Randomizer;

/** Unit digits of powers, table patterns and Pascal's triangle, from the Std 7 Mathematics Term-II book (Chapters 3 and 5). */
final class Std7Term2 extends AptitudeBook
{
    private const NUMBERS = 'simplification-and-percentage';

    private const SERIES = 'logical-reasoning-and-series';

    /** The unit digits of d¹, d², d³, d⁴ for each last digit d; the book's table for 2, 3, 7 and 8. */
    private const CYCLES = [2 => [2, 4, 8, 6], 3 => [3, 9, 7, 1], 7 => [7, 9, 3, 1], 8 => [8, 4, 2, 6]];

    public function source(): string
    {
        return 'Std 7 Mathematics Term-II (English)';
    }

    public function breadcrumb(): string
    {
        return 'Std 7 Mathematics Term-II (English) — Chapters 3 and 5: Unit Digits of Powers and Information Processing';
    }

    public function file(): string
    {
        return 'aptitude-std7-term2.json';
    }

    protected function templates(): array
    {
        return [
            'unit-digit-steady' => fn (Randomizer $r): ?array => $this->steady($r),
            'unit-digit-alternating' => fn (Randomizer $r): ?array => $this->alternating($r),
            'unit-digit-cycle' => fn (Randomizer $r): ?array => $this->cycle($r),
            'table-rule' => fn (Randomizer $r): ?array => $this->tableRule($r),
            'pascal-row-sum' => fn (Randomizer $r): ?array => $this->pascal($r),
        ];
    }

    private function base(Randomizer $r, int $lastDigit): int
    {
        return 10 * $r->getInt(0, 99) + $lastDigit;
    }

    /** @param list<int> $wrong */
    private function unitDigit(int $base, int $power, int $answer, array $wrong, string $difficulty, int $page, string $quote, string $enWhy, string $taWhy): array
    {
        $shown = $base.$this->sup($power);

        return $this->draft(self::NUMBERS, $difficulty, $page, $quote,
            "Find the unit digit of {$shown}.",
            "{$shown} இன் ஒன்றாம் இட இலக்கம் காண்க.",
            "{$enWhy} So the unit digit of {$shown} is {$answer}.",
            "{$taWhy} எனவே {$shown} இன் ஒன்றாம் இட இலக்கம் {$answer}.",
            $answer, $wrong, $this->plain(), $this->plain(),
        );
    }

    private function steady(Randomizer $r): ?array
    {
        $d = $this->pick($r, [0, 1, 5, 6]);
        $base = $this->base($r, $d);
        $power = $r->getInt(2, 150);

        if ($base < 10) {
            return null;
        }

        return $this->unitDigit($base, $power, $d, array_values(array_diff([1, 5, 6, 4, 9], [$d])), 'easy', 53,
            'for the base number whose unit digits are 0,1,5 and 6 the unit digit of a number corresponding to any positive exponent remains unchanged.',
            "The base ends in {$d}, and a base ending in 0, 1, 5 or 6 keeps the same unit digit for every power.",
            "அடிமானத்தின் ஒன்றாம் இட இலக்கம் {$d}; 0, 1, 5, 6 இல் முடியும் அடிமானத்தின் எந்த அடுக்குக்கும் ஒன்றாம் இட இலக்கம் மாறாது.",
        );
    }

    private function alternating(Randomizer $r): ?array
    {
        $d = $this->pick($r, [4, 9]);
        $base = $this->base($r, $d);
        $power = $r->getInt(2, 150);
        $odd = $power % 2 === 1;
        $answer = $d === 4 ? ($odd ? 4 : 6) : ($odd ? 9 : 1);
        $other = $d === 4 ? ($odd ? 6 : 4) : ($odd ? 1 : 9);
        $enParity = $odd ? 'odd' : 'even';
        $taParity = $odd ? 'ஒற்றை' : 'இரட்டை';

        return $this->unitDigit($base, $power, $answer, [$other, $d === 4 ? 8 : 3, $d === 4 ? 2 : 7], 'medium', 54,
            'for base ending with 4, the unit digit of the expanded form is 4 for odd power and is 6 for even power.',
            "The base ends in {$d}, whose powers end alternately in ".($d === 4 ? '4 and 6' : '9 and 1')."; the power {$power} is {$enParity}.",
            "அடிமானம் {$d} இல் முடிகிறது; அதன் அடுக்குகள் மாறி மாறி ".($d === 4 ? '4, 6' : '9, 1')." இல் முடியும்; அடுக்கு {$power} ஓர் {$taParity} எண்.",
        );
    }

    private function cycle(Randomizer $r): ?array
    {
        $d = $this->pick($r, [2, 3, 7, 8]);
        $base = $this->base($r, $d);
        $power = $r->getInt(5, 200);
        $remainder = $power % 4;
        $cycle = self::CYCLES[$d];
        $answer = $cycle[($remainder + 3) % 4];
        $wrong = array_values(array_diff($cycle, [$answer]));

        return $this->unitDigit($base, $power, $answer, $wrong, 'hard', 55,
            'the numbers in the first row, that is 1,2,3 and 0 stands for the remainder when power is divided by 4.',
            "The base ends in {$d}, whose powers end in ".implode(', ', $cycle)." in turn. {$power} ÷ 4 leaves remainder {$remainder}.",
            "அடிமானம் {$d} இல் முடிகிறது; அதன் அடுக்குகள் வரிசையாக ".implode(', ', $cycle)." இல் முடியும். {$power} ÷ 4 இன் மீதி {$remainder}.",
        );
    }

    private function tableRule(Randomizer $r): ?array
    {
        $a = $r->getInt(2, 9);
        $b = $r->getInt(-3, 12);
        $x = $r->getInt(10, 60);

        if ($b === 0 && $r->getInt(0, 1) === 0) {
            return null;
        }

        $ys = array_map(fn (int $i): int => $a * $i + $b, [1, 2, 3, 4]);

        if (min($ys) <= 0) {
            return null;
        }

        $y = $a * $x + $b;
        $list = implode(', ', $ys);
        $rule = 'y = '.$a.'x'.($b === 0 ? '' : ($b > 0 ? " + {$b}" : ' − '.abs($b)));

        return $this->draft(self::SERIES, 'medium', 90, 'Hence the relationship between x and y can be generalised as y = 2x -1',
            "When x = 1, 2, 3, 4, the values of y are {$list}. If the pattern continues, what is y when x = {$x}?",
            "x = 1, 2, 3, 4 எனும்போது y இன் மதிப்புகள் {$list}. இதே அமைப்பு தொடர்ந்தால், x = {$x} எனும்போது y இன் மதிப்பு என்ன?",
            "y goes up by {$a} each time, so {$rule}. When x = {$x}, y = {$a} × {$x}".($b === 0 ? '' : ($b > 0 ? " + {$b}" : ' − '.abs($b)))." = {$y}.",
            "y ஒவ்வொரு முறையும் {$a} அதிகரிக்கிறது; எனவே {$rule}. x = {$x} எனில், y = {$a} × {$x}".($b === 0 ? '' : ($b > 0 ? " + {$b}" : ' − '.abs($b)))." = {$y}.",
            $y, [$a * $x, $y + $a, $y - $a, $ys[3] + $a * ($x - 5)],
            $this->plain(), $this->plain(),
        );
    }

    private function pascal(Randomizer $r): ?array
    {
        $row = $r->getInt(4, 20);
        $sum = 2 ** ($row - 1);
        $ord = $this->ordinal($row);

        return $this->draft(self::SERIES, 'medium', 95, 'We observe that each row sum can be expressed in powers of 2.',
            "What is the sum of the numbers in the {$ord} row of Pascal's triangle?",
            "பாஸ்கல் முக்கோணத்தின் {$row}ஆவது நிரையில் உள்ள எண்களின் கூடுதல் என்ன?",
            "The row sums of Pascal's triangle are powers of 2: row x adds up to 2ˣ⁻¹. So the {$ord} row adds up to 2".$this->sup($row - 1)." = {$sum}.",
            "x ஆவது நிரையின் கூடுதல் y = 2ˣ⁻¹; எனவே {$row}ஆவது நிரையின் கூடுதல் 2".$this->sup($row - 1)." = {$sum}.",
            $sum, [2 ** $row, 2 ** ($row - 2), $row * $row, 2 * $row],
            $this->plain(), $this->plain(),
        );
    }

    private function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return $n.$suffix;
    }
}
