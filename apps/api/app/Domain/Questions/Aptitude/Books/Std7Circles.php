<?php

declare(strict_types=1);

namespace App\Domain\Questions\Aptitude\Books;

use App\Domain\Questions\Aptitude\AptitudeBook;
use Random\Randomizer;

/** Circumference and area of circles, and areas of pathways, from the Std 7 Mathematics Term-II book (Chapter 2). */
final class Std7Circles extends AptitudeBook
{
    private const TOPIC = 'area-and-volume';

    private const CIRCUMFERENCE = 'Circumference of a circle = 2πr units';

    public function source(): string
    {
        return 'Std 7 Mathematics Term-II (English)';
    }

    public function breadcrumb(): string
    {
        return 'Std 7 Mathematics Term-II (English) — Chapter 2: Measurements (Circles and Pathways)';
    }

    public function file(): string
    {
        return 'aptitude-std7-circles.json';
    }

    protected function templates(): array
    {
        return [
            'circumference' => fn (Randomizer $r): ?array => $this->circumference($r),
            'radius-from-circumference' => fn (Randomizer $r): ?array => $this->radius($r),
            'circle-area' => fn (Randomizer $r): ?array => $this->area($r),
            'circular-pathway' => fn (Randomizer $r): ?array => $this->ring($r),
            'rectangular-pathway' => fn (Randomizer $r): ?array => $this->path($r),
        ];
    }

    private function circumference(Randomizer $r): ?array
    {
        $k = $r->getInt(1, 30);
        $radius = 7 * $k;
        $c = 44 * $k;

        return $this->draft(self::TOPIC, 'easy', 24, self::CIRCUMFERENCE,
            "Find the circumference of a circle of radius {$radius} cm. (Take π = 22/7)",
            "ஆரம் {$radius} செ.மீ உடைய வட்டத்தின் சுற்றளவு காண்க. (π = 22/7)",
            "C = 2πr = 2 × 22/7 × {$radius} = {$c} cm.",
            "சுற்றளவு C = 2πr = 2 × 22/7 × {$radius} = {$c} செ.மீ.",
            $c, [22 * $k, 22 * 7 * $k * $k, 2 * $c, $c + 22],
            $this->with(' cm'), $this->with(' செ.மீ'),
        );
    }

    private function radius(Randomizer $r): ?array
    {
        $k = $r->getInt(1, 40);
        $c = 44 * $k;
        $radius = 7 * $k;

        return $this->draft(self::TOPIC, 'medium', 24,
            'Example 2.3 If the circumference of the circle is 132 m. Then calculate the radius and diameter of the circle.',
            "The circumference of a circle is {$c} m. Find its radius. (Take π = 22/7)",
            "ஒரு வட்டத்தின் சுற்றளவு {$c} மீ எனில், அதன் ஆரம் காண்க. (π = 22/7)",
            "2πr = {$c}, so r = {$c} × 7 ÷ 44 = {$radius} m.",
            "2πr = {$c}; எனவே r = {$c} × 7 ÷ 44 = {$radius} மீ.",
            $radius, [2 * $radius, 14 * $k, $c / 2, $radius + 7],
            $this->with(' m'), $this->with(' மீ'),
        );
    }

    private function area(Randomizer $r): ?array
    {
        $k = $r->getInt(1, 20);
        $radius = 7 * $k;
        $a = 154 * $k * $k;

        return $this->draft(self::TOPIC, 'easy', 29, 'So, the area of the circle, A = πr² sq.units.',
            "Find the area of a circle of radius {$radius} cm. (Take π = 22/7)",
            "ஆரம் {$radius} செ.மீ உடைய வட்டத்தின் பரப்பளவு காண்க. (π = 22/7)",
            "A = πr² = 22/7 × {$radius} × {$radius} = {$a} sq. cm.",
            "பரப்பளவு A = πr² = 22/7 × {$radius} × {$radius} = {$a} ச.செ.மீ.",
            $a, [44 * $k, 2 * $a, 22 * $radius, $a / 2],
            $this->with(' sq. cm'), $this->with(' ச.செ.மீ'),
        );
    }

    private function ring(Randomizer $r): ?array
    {
        $big = $r->getInt(2, 15);
        $small = $r->getInt(1, 14);

        if ($small >= $big) {
            return null;
        }

        [$outer, $inner] = [7 * $big, 7 * $small];
        $a = 154 * ($big * $big - $small * $small);

        return $this->draft(self::TOPIC, 'hard', 34,
            'Therefore, the area of the circular pathway = πR² − πr² = π(R² − r²) sq. units.',
            "A circular park of radius {$inner} m has a path around it, so that the outer radius is {$outer} m. Find the area of the path. (Take π = 22/7)",
            "ஆரம் {$inner} மீ உடைய வட்டப் பூங்காவைச் சுற்றி ஒரு பாதை உள்ளது; வெளி ஆரம் {$outer} மீ. பாதையின் பரப்பளவு காண்க. (π = 22/7)",
            "Area of the path = π(R² − r²) = 22/7 × ({$outer}² − {$inner}²) = {$a} sq. m.",
            "பாதையின் பரப்பளவு = π(R² − r²) = 22/7 × ({$outer}² − {$inner}²) = {$a} ச.மீ.",
            $a, [154 * ($big - $small) * ($big - $small), 22 / 7 * ($outer - $inner) * 2 * $outer, 154 * $big * $big, $a + 154],
            $this->with(' sq. m'), $this->with(' ச.மீ'),
        );
    }

    private function path(Randomizer $r): ?array
    {
        $l = $r->getInt(10, 80);
        $b = $r->getInt(5, 60);
        $w = $r->getInt(1, 5);

        if ($b >= $l) {
            return null;
        }

        $outerL = $l + 2 * $w;
        $outerB = $b + 2 * $w;
        $a = $outerL * $outerB - $l * $b;

        return $this->draft(self::TOPIC, 'hard', 34,
            'Here, the area of the rectangular pathway = Area of the outer rectangle − Area of the inner rectangle = (LB − lb) sq. units',
            "A rectangular park is {$l} m long and {$b} m wide. A path {$w} m wide runs around it on the outside. Find the area of the path.",
            "{$l} மீ நீளமும் {$b} மீ அகலமும் உடைய செவ்வகப் பூங்காவின் வெளிப்புறத்தைச் சுற்றி {$w} மீ அகலப் பாதை உள்ளது. பாதையின் பரப்பளவு காண்க.",
            "Outer rectangle: L = {$l} + 2 × {$w} = {$outerL} m, B = {$b} + 2 × {$w} = {$outerB} m. Area of path = LB − lb = ".($outerL * $outerB).' − '.($l * $b)." = {$a} sq. m.",
            "வெளிச் செவ்வகம்: L = {$l} + 2 × {$w} = {$outerL} மீ, B = {$b} + 2 × {$w} = {$outerB} மீ. பாதையின் பரப்பளவு = LB − lb = ".($outerL * $outerB).' − '.($l * $b)." = {$a} ச.மீ.",
            $a, [($l + $w) * ($b + $w) - $l * $b, 2 * ($l + $b) * $w, $outerL * $outerB, $a + 4 * $w * $w],
            $this->with(' sq. m'), $this->with(' ச.மீ'),
        );
    }
}
