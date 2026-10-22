<?php

declare(strict_types=1);

namespace App\Domain\Questions\Aptitude\Books;

use App\Domain\Questions\Aptitude\AptitudeBook;
use Random\Randomizer;

/**
 * Areas of parallelograms, rhombuses and trapeziums, and direct and inverse
 * proportion, from the Std 7 Mathematics Term-I book (Chapters 2 and 4).
 */
final class Std7Term1 extends AptitudeBook
{
    private const AREA = 'area-and-volume';

    private const RATIO = 'ratio-proportion-and-averages';

    private const WORK = 'time-work-and-distance';

    private const TRAPEZIUM = 'Area of a trapezium = 1/2 x {h x (AB + DC)}';

    private const INVERSE = 'Two quantities x and y are said to be in inverse proportion if an increase in x leads to a decrease in y (or vice versa) in such a way that, the product xy remains constant.';

    public function source(): string
    {
        return 'Std 7 Mathematics Term-I (English)';
    }

    public function breadcrumb(): string
    {
        return 'Std 7 Mathematics Term-I (English) — Chapters 2 and 4: Measurements, Direct and Inverse Proportion';
    }

    public function file(): string
    {
        return 'aptitude-std7-term1.json';
    }

    protected function templates(): array
    {
        return [
            'parallelogram-area' => fn (Randomizer $r): ?array => $this->parallelogram($r),
            'rhombus-area' => fn (Randomizer $r): ?array => $this->rhombus($r),
            'rhombus-diagonal' => fn (Randomizer $r): ?array => $this->rhombusDiagonal($r),
            'trapezium-area' => fn (Randomizer $r): ?array => $this->trapezium($r),
            'trapezium-height' => fn (Randomizer $r): ?array => $this->trapeziumHeight($r),
            'direct-proportion' => fn (Randomizer $r): ?array => $this->direct($r),
            'inverse-workers' => fn (Randomizer $r): ?array => $this->inverseWorkers($r),
            'inverse-speed' => fn (Randomizer $r): ?array => $this->inverseSpeed($r),
        ];
    }

    private function parallelogram(Randomizer $r): ?array
    {
        $b = $r->getInt(4, 60);
        $h = $r->getInt(3, 40);
        $a = $b * $h;

        return $this->draft(self::AREA, 'easy', 36, 'Area of a parallelogram = b x h sq.units.',
            "Find the area of a parallelogram whose base is {$b} cm and height is {$h} cm.",
            "அடிப்பக்கம் {$b} செ.மீ, உயரம் {$h} செ.மீ உடைய இணைகரத்தின் பரப்பளவு காண்க.",
            "Area = b × h = {$b} × {$h} = {$a} sq. cm.",
            "பரப்பளவு = b × h = {$b} × {$h} = {$a} ச.செ.மீ.",
            $a, [$a / 2, 2 * ($b + $h), $b + $h, $a + $b],
            $this->with(' sq. cm'), $this->with(' ச.செ.மீ'),
        );
    }

    private function rhombus(Randomizer $r): ?array
    {
        $d1 = $r->getInt(4, 40);
        $d2 = $r->getInt(4, 40);

        if ($d1 >= $d2 || ($d1 * $d2) % 2 !== 0) {
            return null;
        }

        $a = intdiv($d1 * $d2, 2);

        return $this->draft(self::AREA, 'easy', 41, 'Calculate the area of the rhombus having diagonals equal to 6 m and 8 m.',
            "Find the area of a rhombus whose diagonals are {$d1} m and {$d2} m.",
            "மூலைவிட்டங்கள் {$d1} மீ, {$d2} மீ உடைய சாய்சதுரத்தின் பரப்பளவு காண்க.",
            "Area of a rhombus = 1/2 × d1 × d2 = 1/2 × {$d1} × {$d2} = {$a} sq. m.",
            "சாய்சதுரத்தின் பரப்பளவு = 1/2 × d1 × d2 = 1/2 × {$d1} × {$d2} = {$a} ச.மீ.",
            $a, [$d1 * $d2, $d1 + $d2, 2 * ($d1 + $d2), $a + $d1],
            $this->with(' sq. m'), $this->with(' ச.மீ'),
        );
    }

    private function rhombusDiagonal(Randomizer $r): ?array
    {
        $d1 = $r->getInt(4, 30);
        $d2 = $r->getInt(4, 40);

        if ($d1 === $d2 || ($d1 * $d2) % 2 !== 0) {
            return null;
        }

        $a = intdiv($d1 * $d2, 2);

        return $this->draft(self::AREA, 'medium', 42, 'If the area of the rhombus is 60 sq. cm and one of the diagonals is 8 cm, find the length of the other diagonal.',
            "The area of a rhombus is {$a} sq. cm and one of its diagonals is {$d1} cm. Find the other diagonal.",
            "ஒரு சாய்சதுரத்தின் பரப்பளவு {$a} ச.செ.மீ, அதன் ஒரு மூலைவிட்டம் {$d1} செ.மீ எனில், மற்றொரு மூலைவிட்டம் காண்க.",
            "1/2 × d1 × d2 = {$a}, so d2 = 2 × {$a} ÷ {$d1} = {$d2} cm.",
            "1/2 × d1 × d2 = {$a}; எனவே d2 = 2 × {$a} ÷ {$d1} = {$d2} செ.மீ.",
            $d2, [$a / $d1, $a - $d1, 2 * $d2, $d2 + 2],
            $this->with(' cm'), $this->with(' செ.மீ'),
        );
    }

    private function trapezium(Randomizer $r): ?array
    {
        $a = $r->getInt(4, 40);
        $b = $r->getInt(4, 40);
        $h = $r->getInt(3, 30);

        if ($a >= $b || (($a + $b) * $h) % 2 !== 0) {
            return null;
        }

        $area = intdiv(($a + $b) * $h, 2);

        return $this->draft(self::AREA, 'easy', 44, self::TRAPEZIUM,
            "Find the area of a trapezium whose parallel sides are {$a} cm and {$b} cm and the distance between them is {$h} cm.",
            "இணைப் பக்கங்கள் {$a} செ.மீ, {$b} செ.மீ, அவற்றுக்கு இடைப்பட்ட தொலைவு {$h} செ.மீ உடைய சரிவகத்தின் பரப்பளவு காண்க.",
            "Area = 1/2 × h × (a + b) = 1/2 × {$h} × ({$a} + {$b}) = {$area} sq. cm.",
            "பரப்பளவு = 1/2 × h × (a + b) = 1/2 × {$h} × ({$a} + {$b}) = {$area} ச.செ.மீ.",
            $area, [($a + $b) * $h, $a * $b * $h / 2, $area + $h, $b * $h],
            $this->with(' sq. cm'), $this->with(' ச.செ.மீ'),
        );
    }

    private function trapeziumHeight(Randomizer $r): ?array
    {
        $a = $r->getInt(4, 40);
        $b = $r->getInt(4, 40);
        $h = $r->getInt(3, 30);

        if ($a >= $b || (($a + $b) * $h) % 2 !== 0) {
            return null;
        }

        $area = intdiv(($a + $b) * $h, 2);

        return $this->draft(self::AREA, 'medium', 44, self::TRAPEZIUM,
            "The area of a trapezium is {$area} sq. cm and its parallel sides are {$a} cm and {$b} cm. Find the distance between the parallel sides.",
            "ஒரு சரிவகத்தின் பரப்பளவு {$area} ச.செ.மீ, இணைப் பக்கங்கள் {$a} செ.மீ, {$b} செ.மீ எனில், இணைப் பக்கங்களுக்கு இடைப்பட்ட தொலைவு காண்க.",
            "1/2 × h × ({$a} + {$b}) = {$area}, so h = 2 × {$area} ÷ ".($a + $b)." = {$h} cm.",
            "1/2 × h × ({$a} + {$b}) = {$area}; எனவே h = 2 × {$area} ÷ ".($a + $b)." = {$h} செ.மீ.",
            $h, [$area / ($a + $b), 2 * $h, $area / $b, $h + 2],
            $this->with(' cm'), $this->with(' செ.மீ'),
        );
    }

    private function direct(Randomizer $r): ?array
    {
        [$enThing, $taThing, $enUnit, $taUnit] = $this->pick($r, [
            ['shirts', 'சட்டைகள்', 'shirts', 'சட்டைகள்'],
            ['kg of sugar', 'கிகி சர்க்கரை', 'kg', 'கிகி'],
            ['metres of cloth', 'மீட்டர் துணி', 'metres', 'மீட்டர்'],
        ]);
        $x1 = $r->getInt(2, 12);
        $x2 = $r->getInt(2, 30);
        $each = $r->getInt(2, 60) * 10;

        if ($x1 === $x2) {
            return null;
        }

        $y1 = $x1 * $each;
        $y2 = $x2 * $each;

        return $this->draft(self::RATIO, 'easy', 73,
            'For example, if the cost of a shirt is ₹ 500, then the price of 3 shirts will be ₹ 1,500.',
            "The cost of {$x1} {$enThing} is ₹{$y1}. Using direct proportion, find the cost of {$x2} {$enThing}.",
            "{$x1} {$taThing} விலை ₹{$y1} எனில், நேர் விகிதத்தைப் பயன்படுத்தி {$x2} {$taThing} விலை காண்க.",
            "Cost and quantity are in direct proportion: {$y1}/{$x1} = y/{$x2}, so y = {$y1} × {$x2} ÷ {$x1} = ₹{$y2}.",
            "விலையும் அளவும் நேர் விகிதத்தில் உள்ளன: {$y1}/{$x1} = y/{$x2}; எனவே y = {$y1} × {$x2} ÷ {$x1} = ₹{$y2}.",
            $y2, [$y1 * $x1 / $x2, $y1 + ($x2 - $x1), $y2 + $each, $y2 - $each],
            $this->rupees(), $this->rupees(),
        );
    }

    private function inverseWorkers(Randomizer $r): ?array
    {
        $m = $r->getInt(2, 30);
        $d = $r->getInt(2, 40);
        $n = $r->getInt(2, 40);

        if ($m === $n || ($m * $d) % $n !== 0) {
            return null;
        }

        $answer = intdiv($m * $d, $n);

        return $this->draft(self::WORK, 'medium', 82, self::INVERSE,
            "{$m} workers can build a wall in {$d} days. In how many days can {$n} workers build the same wall?",
            "{$m} தொழிலாளர்கள் ஒரு சுவரை {$d} நாட்களில் கட்டுவர் எனில், {$n} தொழிலாளர்கள் அதே சுவரை எத்தனை நாட்களில் கட்டுவர்?",
            "Workers and days are in inverse proportion, so {$m} × {$d} = {$n} × x, and x = ".($m * $d)." ÷ {$n} = {$answer} days.",
            "தொழிலாளர் எண்ணிக்கையும் நாட்களும் எதிர் விகிதத்தில் உள்ளன: {$m} × {$d} = {$n} × x; எனவே x = ".($m * $d)." ÷ {$n} = {$answer} நாட்கள்.",
            $answer, [$d * $n / $m, $d + $m - $n, $answer + 1, $answer * 2],
            $this->unit('days', 'day'), $this->unit('நாட்கள்', 'நாள்'),
        );
    }

    private function inverseSpeed(Randomizer $r): ?array
    {
        $s1 = $r->getInt(4, 20) * 5;
        $t1 = $r->getInt(2, 12);
        $s2 = $r->getInt(4, 24) * 5;

        if ($s1 === $s2 || ($s1 * $t1) % $s2 !== 0) {
            return null;
        }

        $t2 = intdiv($s1 * $t1, $s2);

        return $this->draft(self::WORK, 'medium', 82, self::INVERSE,
            "A bus travelling at {$s1} km/h takes {$t1} hours for a journey. How long will it take at {$s2} km/h?",
            "மணிக்கு {$s1} கி.மீ வேகத்தில் செல்லும் பேருந்து ஒரு பயணத்துக்கு {$t1} மணி நேரம் எடுக்கிறது. மணிக்கு {$s2} கி.மீ வேகத்தில் அதற்கு எவ்வளவு நேரம் ஆகும்?",
            "Speed and time are in inverse proportion: {$s1} × {$t1} = {$s2} × t, so t = ".($s1 * $t1)." ÷ {$s2} = {$t2} hours.",
            "வேகமும் நேரமும் எதிர் விகிதத்தில் உள்ளன: {$s1} × {$t1} = {$s2} × t; எனவே t = ".($s1 * $t1)." ÷ {$s2} = {$t2} மணி நேரம்.",
            $t2, [$t1 * $s2 / $s1, $t1 + 1, $t2 + 1, $t2 * 2],
            $this->unit('hours', 'hour'), $this->unit('மணி நேரம்', 'மணி நேரம்'),
        );
    }
}
