<?php

declare(strict_types=1);

namespace App\Domain\Questions\Aptitude\Books;

use App\Domain\Questions\Aptitude\AptitudeBook;
use Random\Randomizer;

/** Surface area and volume of cubes and cuboids, from the Std 9 Mathematics book (Chapter 7, Mensuration). */
final class Std9Mensuration extends AptitudeBook
{
    private const TOPIC = 'area-and-volume';

    private const VOLUME = 'Volume of a cuboid = l × b × h';

    public function source(): string
    {
        return 'Std 9 Mathematics Term-Full (English)';
    }

    public function breadcrumb(): string
    {
        return 'Std 9 Mathematics (English) — Chapter 7: Mensuration (Cube and Cuboid)';
    }

    public function file(): string
    {
        return 'aptitude-std9-mensuration.json';
    }

    protected function templates(): array
    {
        return [
            'cuboid-volume' => fn (Randomizer $r): ?array => $this->cuboidVolume($r),
            'tank-litres' => fn (Randomizer $r): ?array => $this->tankLitres($r),
            'cuboid-tsa' => fn (Randomizer $r): ?array => $this->cuboidSurface($r),
            'cube-tsa' => fn (Randomizer $r): ?array => $this->cubeSurface($r),
            'cube-volume-from-tsa' => fn (Randomizer $r): ?array => $this->cubeVolume($r),
            'small-cubes' => fn (Randomizer $r): ?array => $this->smallCubes($r),
        ];
    }

    private function cuboidVolume(Randomizer $r): ?array
    {
        $l = $r->getInt(4, 30);
        $b = $r->getInt(3, 25);
        $h = $r->getInt(2, 20);

        if (! ($l > $b && $b > $h)) {
            return null;
        }

        $v = $l * $b * $h;

        return $this->draft(self::TOPIC, 'easy', 259, self::VOLUME,
            "Find the volume of a cuboid of length {$l} cm, breadth {$b} cm and height {$h} cm.",
            "நீளம் {$l} செ.மீ, அகலம் {$b} செ.மீ, உயரம் {$h} செ.மீ உடைய கனச்செவ்வகத்தின் கனஅளவு காண்க.",
            "Volume = l × b × h = {$l} × {$b} × {$h} = {$v} cm³.",
            "கனஅளவு = l × b × h = {$l} × {$b} × {$h} = {$v} செ.மீ³.",
            $v, [2 * ($l * $b + $b * $h + $h * $l), $l * $b + $b * $h + $h * $l, $v / 2, $l + $b + $h],
            $this->with(' cm³'), $this->with(' செ.மீ³'),
        );
    }

    private function tankLitres(Randomizer $r): ?array
    {
        $l = $r->getInt(2, 12);
        $b = $r->getInt(1, 10);
        $h = $r->getInt(1, 6);

        if ($b > $l) {
            return null;
        }

        $litres = $l * $b * $h * 1000;

        return $this->draft(self::TOPIC, 'medium', 259, self::VOLUME,
            "A tank is {$l} m long, {$b} m wide and {$h} m deep. How many litres of water can it hold? (1 m³ = 1000 litres)",
            "{$l} மீ நீளம், {$b} மீ அகலம், {$h} மீ ஆழம் உடைய ஒரு தொட்டியில் எத்தனை லிட்டர் நீர் கொள்ளும்? (1 மீ³ = 1000 லிட்டர்)",
            "Volume = {$l} × {$b} × {$h} = ".($l * $b * $h)." m³ = ".($l * $b * $h)." × 1000 = {$litres} litres.",
            "கனஅளவு = {$l} × {$b} × {$h} = ".($l * $b * $h)." மீ³ = ".($l * $b * $h)." × 1000 = {$litres} லிட்டர்.",
            $litres, [$l * $b * $h * 100, $l * $b * $h, $litres * 10, $litres + 1000],
            $this->with(' litres'), $this->with(' லிட்டர்'),
        );
    }

    private function cuboidSurface(Randomizer $r): ?array
    {
        $l = $r->getInt(3, 25);
        $b = $r->getInt(2, 20);
        $h = $r->getInt(2, 15);

        if (! ($l > $b && $b > $h)) {
            return null;
        }

        $tsa = 2 * ($l * $b + $b * $h + $h * $l);

        return $this->draft(self::TOPIC, 'medium', 254, 'TSA = 2(lb + bh + lh)',
            "Find the total surface area of a cuboid of length {$l} cm, breadth {$b} cm and height {$h} cm.",
            "நீளம் {$l} செ.மீ, அகலம் {$b} செ.மீ, உயரம் {$h} செ.மீ உடைய கனச்செவ்வகத்தின் மொத்தப் புறப்பரப்பு காண்க.",
            "TSA = 2(lb + bh + lh) = 2(".($l * $b).' + '.($b * $h).' + '.($h * $l).") = {$tsa} cm².",
            "மொத்தப் புறப்பரப்பு = 2(lb + bh + lh) = 2(".($l * $b).' + '.($b * $h).' + '.($h * $l).") = {$tsa} செ.மீ².",
            $tsa, [$l * $b * $h, $tsa / 2, 2 * $h * ($l + $b), $tsa + 2 * $l * $b],
            $this->with(' cm²'), $this->with(' செ.மீ²'),
        );
    }

    private function cubeSurface(Randomizer $r): ?array
    {
        $a = $r->getInt(2, 40);
        $tsa = 6 * $a * $a;

        return $this->draft(self::TOPIC, 'easy', 256, 'Here, Total Surface Area of the cube = 486 cm²',
            "Find the total surface area of a cube of side {$a} cm.",
            "பக்க அளவு {$a} செ.மீ உடைய கனச்சதுரத்தின் மொத்தப் புறப்பரப்பு காண்க.",
            "TSA of a cube = 6a² = 6 × {$a} × {$a} = {$tsa} cm².",
            "கனச்சதுரத்தின் மொத்தப் புறப்பரப்பு = 6a² = 6 × {$a} × {$a} = {$tsa} செ.மீ².",
            $tsa, [4 * $a * $a, $a ** 3, $a * $a, 6 * $a],
            $this->with(' cm²'), $this->with(' செ.மீ²'),
        );
    }

    private function cubeVolume(Randomizer $r): ?array
    {
        $a = $r->getInt(2, 25);
        $tsa = 6 * $a * $a;

        return $this->draft(self::TOPIC, 'hard', 256, 'Here, Total Surface Area of the cube = 486 cm²',
            "The total surface area of a cube is {$tsa} cm². Find its volume.",
            "ஒரு கனச்சதுரத்தின் மொத்தப் புறப்பரப்பு {$tsa} செ.மீ² எனில், அதன் கனஅளவு காண்க.",
            "6a² = {$tsa}, so a² = ".($a * $a)." and a = {$a} cm; volume = a³ = ".($a ** 3).' cm³.',
            "6a² = {$tsa}; எனவே a² = ".($a * $a).", a = {$a} செ.மீ; கனஅளவு = a³ = ".($a ** 3).' செ.மீ³.',
            $a ** 3, [$a * $a, $tsa * $a, ($a + 1) ** 3, $a ** 3 * 6],
            $this->with(' cm³'), $this->with(' செ.மீ³'),
        );
    }

    private function smallCubes(Randomizer $r): ?array
    {
        $s = $r->getInt(2, 5);
        $l = $s * $r->getInt(2, 10);
        $b = $s * $r->getInt(2, 8);
        $h = $s * $r->getInt(1, 6);

        if (! ($l > $b && $b >= $h)) {
            return null;
        }

        $count = intdiv($l * $b * $h, $s ** 3);

        return $this->draft(self::TOPIC, 'medium', 259, self::VOLUME,
            "How many cubes of side {$s} cm can be cut from a cuboid measuring {$l} cm × {$b} cm × {$h} cm?",
            "{$l} செ.மீ × {$b} செ.மீ × {$h} செ.மீ அளவுள்ள கனச்செவ்வகத்திலிருந்து {$s} செ.மீ பக்கமுடைய எத்தனை கனச்சதுரங்களை வெட்டி எடுக்கலாம்?",
            "Number of cubes = volume of cuboid ÷ volume of one cube = ".($l * $b * $h)." ÷ ".($s ** 3)." = {$count}.",
            "கனச்சதுரங்களின் எண்ணிக்கை = கனச்செவ்வகத்தின் கனஅளவு ÷ ஒரு கனச்சதுரத்தின் கனஅளவு = ".($l * $b * $h).' ÷ '.($s ** 3)." = {$count}.",
            $count, [intdiv($l * $b * $h, $s * $s), $l * $b * $h, $count * 2, $count + $s],
            $this->plain(), $this->plain(),
        );
    }
}
