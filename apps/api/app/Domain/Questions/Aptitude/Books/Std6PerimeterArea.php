<?php

declare(strict_types=1);

namespace App\Domain\Questions\Aptitude\Books;

use App\Domain\Questions\Aptitude\AptitudeBook;
use Random\Randomizer;

/** Perimeter and area of rectangles and squares, from the Std 6 Mathematics Term-III book (Chapter 3). */
final class Std6PerimeterArea extends AptitudeBook
{
    private const TOPIC = 'area-and-volume';

    private const RECT_PERIMETER = 'Perimeter of the rectangle, P= 2 x (l + b) units';

    private const RECT_AREA = 'Area of the rectangle A = (l x b) sq. units.';

    public function source(): string
    {
        return 'Std 6 Mathematics Term-III (English)';
    }

    public function breadcrumb(): string
    {
        return 'Std 6 Mathematics Term-III (English) — Chapter 3: Perimeter and Area';
    }

    public function file(): string
    {
        return 'aptitude-std6-perimeter-area.json';
    }

    protected function templates(): array
    {
        return [
            'rectangle-perimeter' => fn (Randomizer $r): ?array => $this->rectanglePerimeter($r),
            'rectangle-area' => fn (Randomizer $r): ?array => $this->rectangleArea($r),
            'rectangle-breadth' => fn (Randomizer $r): ?array => $this->rectangleBreadth($r),
            'square-side' => fn (Randomizer $r): ?array => $this->squareSide($r),
            'square-area-from-perimeter' => fn (Randomizer $r): ?array => $this->squareArea($r),
            'fencing-cost' => fn (Randomizer $r): ?array => $this->fencing($r),
        ];
    }

    private function rectanglePerimeter(Randomizer $r): ?array
    {
        [$u, $tu] = $this->pick($r, [['cm', 'செ.மீ'], ['m', 'மீ']]);
        $l = $r->getInt(5, 90);
        $b = $r->getInt(3, 60);

        if ($b >= $l) {
            return null;
        }

        $p = 2 * ($l + $b);

        return $this->draft(self::TOPIC, 'easy', 39, self::RECT_PERIMETER,
            "Find the perimeter of a rectangle of length {$l} {$u} and breadth {$b} {$u}.",
            "நீளம் {$l} {$tu}, அகலம் {$b} {$tu} உடைய செவ்வகத்தின் சுற்றளவு காண்க.",
            "P = 2 × (l + b) = 2 × ({$l} + {$b}) = {$p} {$u}.",
            "சுற்றளவு P = 2 × (l + b) = 2 × ({$l} + {$b}) = {$p} {$tu}.",
            $p, [$l * $b, $l + $b, 2 * $l + $b, 4 * ($l + $b)],
            $this->with(' '.$u), $this->with(' '.$tu),
        );
    }

    private function rectangleArea(Randomizer $r): ?array
    {
        [$u, $tu] = $this->pick($r, [['cm', 'செ.மீ'], ['m', 'மீ']]);
        $l = $r->getInt(4, 60);
        $b = $r->getInt(3, 40);

        if ($b >= $l) {
            return null;
        }

        $a = $l * $b;

        return $this->draft(self::TOPIC, 'easy', 44, self::RECT_AREA,
            "Find the area of a rectangle of length {$l} {$u} and breadth {$b} {$u}.",
            "நீளம் {$l} {$tu}, அகலம் {$b} {$tu} உடைய செவ்வகத்தின் பரப்பளவு காண்க.",
            "A = l × b = {$l} × {$b} = {$a} sq. {$u}.",
            "பரப்பளவு A = l × b = {$l} × {$b} = {$a} ச.{$tu}.",
            $a, [2 * ($l + $b), $l + $b, $a / 2, $a + $l],
            $this->with(' sq. '.$u), $this->with(' ச.'.$tu),
        );
    }

    private function rectangleBreadth(Randomizer $r): ?array
    {
        $l = $r->getInt(6, 50);
        $b = $r->getInt(3, 40);

        if ($b >= $l) {
            return null;
        }

        $a = $l * $b;

        return $this->draft(self::TOPIC, 'medium', 44, self::RECT_AREA,
            "The area of a rectangle is {$a} sq. cm and its length is {$l} cm. Find its breadth.",
            "ஒரு செவ்வகத்தின் பரப்பளவு {$a} ச.செ.மீ, நீளம் {$l} செ.மீ எனில், அதன் அகலம் காண்க.",
            "A = l × b, so b = A ÷ l = {$a} ÷ {$l} = {$b} cm.",
            "A = l × b; எனவே b = A ÷ l = {$a} ÷ {$l} = {$b} செ.மீ.",
            $b, [$a - $l, $a / 2 - $l, 2 * $b, $b + 2],
            $this->with(' cm'), $this->with(' செ.மீ'),
        );
    }

    private function squareSide(Randomizer $r): ?array
    {
        $s = $r->getInt(3, 120);
        $p = 4 * $s;

        return $this->draft(self::TOPIC, 'easy', 47, 'Perimeter of a square, P = (4 × s) units',
            "The perimeter of a square is {$p} cm. Find the length of its side.",
            "ஒரு சதுரத்தின் சுற்றளவு {$p} செ.மீ எனில், அதன் பக்க நீளம் காண்க.",
            "P = 4 × s, so s = {$p} ÷ 4 = {$s} cm.",
            "P = 4 × s; எனவே s = {$p} ÷ 4 = {$s} செ.மீ.",
            $s, [$p / 2, 2 * $s, $s * $s, $s + 4],
            $this->with(' cm'), $this->with(' செ.மீ'),
        );
    }

    private function squareArea(Randomizer $r): ?array
    {
        $s = $r->getInt(3, 60);
        $p = 4 * $s;

        return $this->draft(self::TOPIC, 'medium', 44, 'Therefore area of a square = (s x s) sq. units.',
            "The perimeter of a square is {$p} m. Find its area.",
            "ஒரு சதுரத்தின் சுற்றளவு {$p} மீ எனில், அதன் பரப்பளவு காண்க.",
            "Side = {$p} ÷ 4 = {$s} m, so area = s × s = {$s} × {$s} = ".($s * $s).' sq. m.',
            "பக்கம் = {$p} ÷ 4 = {$s} மீ; எனவே பரப்பளவு = s × s = {$s} × {$s} = ".($s * $s).' ச.மீ.',
            $s * $s, [$p * $p, $p * $s, 4 * $s * $s, $s * $s / 2],
            $this->with(' sq. m'), $this->with(' ச.மீ'),
        );
    }

    private function fencing(Randomizer $r): ?array
    {
        $l = $r->getInt(10, 120);
        $b = $r->getInt(5, 80);
        $rate = $this->pick($r, [5, 8, 10, 12, 15, 18, 20, 25, 30, 40, 50]);

        if ($b >= $l) {
            return null;
        }

        $cost = 2 * ($l + $b) * $rate;

        return $this->draft(self::TOPIC, 'medium', 39, self::RECT_PERIMETER,
            "A rectangular field is {$l} m long and {$b} m wide. Find the cost of fencing it at ₹{$rate} per metre.",
            "{$l} மீ நீளமும் {$b} மீ அகலமும் உடைய ஒரு செவ்வக வயலுக்கு மீட்டருக்கு ₹{$rate} வீதம் வேலி அமைக்க ஆகும் செலவு எவ்வளவு?",
            'Length of fence = perimeter = 2 × ('.$l.' + '.$b.') = '.(2 * ($l + $b))." m, so cost = ".(2 * ($l + $b))." × {$rate} = ₹{$cost}.",
            'வேலியின் நீளம் = சுற்றளவு = 2 × ('.$l.' + '.$b.') = '.(2 * ($l + $b)).' மீ; செலவு = '.(2 * ($l + $b))." × {$rate} = ₹{$cost}.",
            $cost, [$l * $b * $rate, ($l + $b) * $rate, $cost + $rate * 10, 2 * $l * $rate],
            $this->rupees(), $this->rupees(),
        );
    }
}
