<?php

declare(strict_types=1);

namespace App\Domain\Questions\Aptitude\Books;

use App\Domain\Questions\Aptitude\AptitudeBook;
use Random\Randomizer;

/** Surface area and volume of cylinders, cones and spheres, from the Std 10 Mathematics book (Chapter 7, Mensuration). */
final class Std10Mensuration extends AptitudeBook
{
    private const TOPIC = 'area-and-volume';

    /** Pythagorean triples (radius, height, slant height). */
    private const TRIPLES = [[3, 4, 5], [4, 3, 5], [5, 12, 13], [12, 5, 13], [8, 15, 17], [15, 8, 17], [7, 24, 25], [24, 7, 25], [20, 21, 29], [21, 20, 29], [9, 40, 41], [12, 35, 37]];

    public function source(): string
    {
        return 'Std 10 Mathematics Term-Full (English)';
    }

    public function breadcrumb(): string
    {
        return 'Std 10 Mathematics (English) — Chapter 7: Mensuration (Cylinder, Cone and Sphere)';
    }

    public function file(): string
    {
        return 'aptitude-std10-mensuration.json';
    }

    protected function templates(): array
    {
        return [
            'cylinder-csa' => fn (Randomizer $r): ?array => $this->cylinderSurface($r),
            'cylinder-volume' => fn (Randomizer $r): ?array => $this->cylinderVolume($r),
            'cone-volume' => fn (Randomizer $r): ?array => $this->coneVolume($r),
            'cone-slant-height' => fn (Randomizer $r): ?array => $this->slantHeight($r),
            'cone-csa' => fn (Randomizer $r): ?array => $this->coneSurface($r),
            'sphere-surface' => fn (Randomizer $r): ?array => $this->sphereSurface($r),
            'sphere-volume' => fn (Randomizer $r): ?array => $this->sphereVolume($r),
        ];
    }

    private function cylinderSurface(Randomizer $r): ?array
    {
        $k = $r->getInt(1, 10);
        $radius = 7 * $k;
        $h = $r->getInt(2, 40);
        $csa = 44 * $k * $h;

        return $this->draft(self::TOPIC, 'easy', 271, 'C.S.A. of a right circular cylinder = 2πrh sq. units.',
            "Find the curved surface area of a right circular cylinder of radius {$radius} cm and height {$h} cm. (Take π = 22/7)",
            "ஆரம் {$radius} செ.மீ, உயரம் {$h} செ.மீ உடைய நேர்வட்ட உருளையின் வளைபரப்பு காண்க. (π = 22/7)",
            "C.S.A. = 2πrh = 2 × 22/7 × {$radius} × {$h} = {$csa} cm².",
            "வளைபரப்பு = 2πrh = 2 × 22/7 × {$radius} × {$h} = {$csa} செ.மீ².",
            $csa, [22 * $k * $h, $csa + 2 * 154 * $k * $k, 154 * $k * $k * $h, $csa * 2],
            $this->with(' cm²'), $this->with(' செ.மீ²'),
        );
    }

    private function cylinderVolume(Randomizer $r): ?array
    {
        $k = $r->getInt(1, 8);
        $radius = 7 * $k;
        $h = $r->getInt(2, 30);
        $v = 154 * $k * $k * $h;

        return $this->draft(self::TOPIC, 'medium', 282, "The volume of a right circular cylinder of base radius 'r' and height 'h'",
            "Find the volume of a right circular cylinder of radius {$radius} cm and height {$h} cm. (Take π = 22/7)",
            "ஆரம் {$radius} செ.மீ, உயரம் {$h} செ.மீ உடைய நேர்வட்ட உருளையின் கனஅளவு காண்க. (π = 22/7)",
            "V = πr²h = 22/7 × {$radius} × {$radius} × {$h} = {$v} cm³.",
            "கனஅளவு V = πr²h = 22/7 × {$radius} × {$radius} × {$h} = {$v} செ.மீ³.",
            $v, [44 * $k * $h, $v / 3, 22 * $radius * $h, $v * 2],
            $this->with(' cm³'), $this->with(' செ.மீ³'),
        );
    }

    private function coneVolume(Randomizer $r): ?array
    {
        $k = $r->getInt(1, 8);
        $radius = 7 * $k;
        $j = $r->getInt(1, 10);
        $h = 3 * $j;
        $v = 154 * $k * $k * $j;

        return $this->draft(self::TOPIC, 'medium', 285, '7.3.3 Volume of a right circular cone Let r and h be the radius and height of a cone then its volume',
            "Find the volume of a right circular cone of radius {$radius} cm and height {$h} cm. (Take π = 22/7)",
            "ஆரம் {$radius} செ.மீ, உயரம் {$h} செ.மீ உடைய நேர்வட்டக் கூம்பின் கனஅளவு காண்க. (π = 22/7)",
            "V = 1/3 × πr²h = 1/3 × 22/7 × {$radius} × {$radius} × {$h} = {$v} cm³.",
            "கனஅளவு V = 1/3 × πr²h = 1/3 × 22/7 × {$radius} × {$radius} × {$h} = {$v} செ.மீ³.",
            $v, [3 * $v, $v / 2, 44 * $k * $h, $v + 154],
            $this->with(' cm³'), $this->with(' செ.மீ³'),
        );
    }

    private function slantHeight(Randomizer $r): ?array
    {
        [$a, $b, $c] = $this->pick($r, self::TRIPLES);
        $m = $r->getInt(1, 6);
        [$radius, $h, $l] = [$a * $m, $b * $m, $c * $m];

        return $this->draft(self::TOPIC, 'medium', 274, 'Curved Surface Area of the cone = Area of the Sector = πrl sq. units.',
            "Find the slant height of a right circular cone of radius {$radius} cm and height {$h} cm.",
            "ஆரம் {$radius} செ.மீ, உயரம் {$h} செ.மீ உடைய நேர்வட்டக் கூம்பின் சாயுயரம் காண்க.",
            "l = √(r² + h²) = √(".($radius * $radius).' + '.($h * $h).') = √'.($l * $l)." = {$l} cm.",
            "சாயுயரம் l = √(r² + h²) = √(".($radius * $radius).' + '.($h * $h).') = √'.($l * $l)." = {$l} செ.மீ.",
            $l, [$radius + $h, abs($h - $radius) ?: $l + 2, $l + $m, $l - $m],
            $this->with(' cm'), $this->with(' செ.மீ'),
        );
    }

    private function coneSurface(Randomizer $r): ?array
    {
        [$a, $b, $c] = $this->pick($r, self::TRIPLES);
        $m = 7 * $r->getInt(1, 3);

        if ($a * $m > 100) {
            return null;
        }

        [$radius, $h, $l] = [$a * $m, $b * $m, $c * $m];
        $csa = 22 * intdiv($radius, 7) * $l;

        return $this->draft(self::TOPIC, 'hard', 274, 'Curved Surface Area of the cone = Area of the Sector = πrl sq. units.',
            "Find the curved surface area of a right circular cone of radius {$radius} cm and height {$h} cm. (Take π = 22/7)",
            "ஆரம் {$radius} செ.மீ, உயரம் {$h} செ.மீ உடைய நேர்வட்டக் கூம்பின் வளைபரப்பு காண்க. (π = 22/7)",
            "Slant height l = √(r² + h²) = {$l} cm, so C.S.A. = πrl = 22/7 × {$radius} × {$l} = {$csa} cm².",
            "சாயுயரம் l = √(r² + h²) = {$l} செ.மீ; வளைபரப்பு = πrl = 22/7 × {$radius} × {$l} = {$csa} செ.மீ².",
            $csa, [22 * intdiv($radius, 7) * $h, $csa * 2, $csa + 154 * intdiv($radius, 7) * intdiv($radius, 7), $csa / 2],
            $this->with(' cm²'), $this->with(' செ.மீ²'),
        );
    }

    private function sphereSurface(Randomizer $r): ?array
    {
        $k = $r->getInt(1, 12);
        $radius = 7 * $k;
        $s = 616 * $k * $k;

        return $this->draft(self::TOPIC, 'easy', 277, 'Surface area of sphere = curved surface area of cylinder',
            "Find the surface area of a sphere of radius {$radius} cm. (Take π = 22/7)",
            "ஆரம் {$radius} செ.மீ உடைய கோளத்தின் புறப்பரப்பு காண்க. (π = 22/7)",
            "Surface area = 4πr² = 4 × 22/7 × {$radius} × {$radius} = {$s} cm².",
            "புறப்பரப்பு = 4πr² = 4 × 22/7 × {$radius} × {$radius} = {$s} செ.மீ².",
            $s, [154 * $k * $k, 2 * 154 * $k * $k, $s * 2, 3 * 154 * $k * $k],
            $this->with(' cm²'), $this->with(' செ.மீ²'),
        );
    }

    private function sphereVolume(Randomizer $r): ?array
    {
        // Radii that give an exact volume with π = 22/7.
        $radius = $this->pick($r, [10.5, 21, 31.5, 42, 52.5, 63]);
        $half = $r->getInt(0, 1) === 1;
        $v = ($half ? 2 / 3 : 4 / 3) * 22 / 7 * $radius ** 3;

        if (! $this->clean($v)) {
            return null;
        }

        $enShape = $half ? 'hemisphere' : 'sphere';
        $taShape = $half ? 'அரைக்கோளத்தின்' : 'கோளத்தின்';
        $fraction = $half ? '2/3' : '4/3';
        $r3 = $this->n($radius).' × '.$this->n($radius).' × '.$this->n($radius);

        return $this->draft(self::TOPIC, 'hard', 287, 'Volume of a sphere = 4/3 πr³ cu. units',
            "Find the volume of a {$enShape} of radius ".$this->n($radius).' cm. (Take π = 22/7)',
            'ஆரம் '.$this->n($radius)." செ.மீ உடைய {$taShape} கனஅளவு காண்க. (π = 22/7)",
            "V = {$fraction} πr³ = {$fraction} × 22/7 × {$r3} = ".$this->n($v).' cm³.',
            "கனஅளவு V = {$fraction} πr³ = {$fraction} × 22/7 × {$r3} = ".$this->n($v).' செ.மீ³.',
            $v, [$half ? $v * 2 : $v / 2, $v * 3 / 4, 4 * 22 / 7 * $radius ** 2, $v + 1000],
            $this->with(' cm³'), $this->with(' செ.மீ³'),
        );
    }
}
