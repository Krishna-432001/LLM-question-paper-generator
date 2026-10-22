<?php

declare(strict_types=1);

namespace App\Domain\Questions\Aptitude\Books;

use App\Domain\Questions\Aptitude\AptitudeBook;
use Random\Randomizer;

/** Arcs and sectors, and age problems by linear equations, from the Std 8 Mathematics book (Chapters 2 and 3). */
final class Std8MeasureAlgebra extends AptitudeBook
{
    private const AREA = 'area-and-volume';

    private const RATIO = 'ratio-proportion-and-averages';

    private const SECTOR = '2.2.2 Length of the arc and Area of the sector';

    private const ANGLES = [30, 45, 60, 72, 90, 120, 135, 144, 150, 180, 210, 240, 270, 300];

    public function source(): string
    {
        return 'Std 8 Mathematics Term-Full (English)';
    }

    public function breadcrumb(): string
    {
        return 'Std 8 Mathematics (English) — Chapters 2 and 3: Measurements and Algebra';
    }

    public function file(): string
    {
        return 'aptitude-std8-measurements-algebra.json';
    }

    protected function templates(): array
    {
        return [
            'arc-length' => fn (Randomizer $r): ?array => $this->arc($r),
            'sector-area' => fn (Randomizer $r): ?array => $this->sector($r),
            'ages-sum' => fn (Randomizer $r): ?array => $this->agesSum($r),
            'ages-after' => fn (Randomizer $r): ?array => $this->agesAfter($r),
        ];
    }

    private function arc(Randomizer $r): ?array
    {
        $theta = $this->pick($r, self::ANGLES);
        $k = $r->getInt(1, 20);
        $radius = 7 * $k;
        $length = $theta / 360 * 44 * $k;

        if (! $this->clean($length)) {
            return null;
        }

        return $this->draft(self::AREA, 'medium', 54, self::SECTOR,
            "Find the length of the arc of a sector of angle {$theta}° in a circle of radius {$radius} cm. (Take π = 22/7)",
            "ஆரம் {$radius} செ.மீ உடைய வட்டத்தில் மையக்கோணம் {$theta}° உடைய வட்டக்கோணப் பகுதியின் வில்லின் நீளம் காண்க. (π = 22/7)",
            "Arc length = θ/360 × 2πr = {$theta}/360 × 2 × 22/7 × {$radius} = ".$this->n($length).' cm.',
            "வில்லின் நீளம் = θ/360 × 2πr = {$theta}/360 × 2 × 22/7 × {$radius} = ".$this->n($length).' செ.மீ.',
            $length, [$theta / 360 * 154 * $k * $k, 44 * $k, $length * 2, $length + $radius],
            $this->with(' cm'), $this->with(' செ.மீ'),
        );
    }

    private function sector(Randomizer $r): ?array
    {
        $theta = $this->pick($r, self::ANGLES);
        $k = $r->getInt(1, 12);
        $radius = 7 * $k;
        $area = $theta / 360 * 154 * $k * $k;

        if (! $this->clean($area)) {
            return null;
        }

        return $this->draft(self::AREA, 'medium', 54, self::SECTOR,
            "Find the area of a sector of angle {$theta}° in a circle of radius {$radius} cm. (Take π = 22/7)",
            "ஆரம் {$radius} செ.மீ உடைய வட்டத்தில் மையக்கோணம் {$theta}° உடைய வட்டக்கோணப் பகுதியின் பரப்பளவு காண்க. (π = 22/7)",
            "Area of the sector = θ/360 × πr² = {$theta}/360 × 22/7 × {$radius} × {$radius} = ".$this->n($area).' sq. cm.',
            "வட்டக்கோணப் பகுதியின் பரப்பளவு = θ/360 × πr² = {$theta}/360 × 22/7 × {$radius} × {$radius} = ".$this->n($area).' ச.செ.மீ.',
            $area, [$theta / 360 * 44 * $k, 154 * $k * $k, $area * 2, $area / 2],
            $this->with(' sq. cm'), $this->with(' ச.செ.மீ'),
        );
    }

    private function agesSum(Randomizer $r): ?array
    {
        $daughter = $r->getInt(3, 20);
        $times = $r->getInt(3, 6);
        $mother = $daughter * $times;
        $sum = $mother + $daughter;

        if ($mother < 22 || $mother > 70) {
            return null;
        }

        return $this->draft(self::RATIO, 'medium', 104,
            "Hence daughter's present age = 6 years; and mother's present age = 5 x = 5 × 6 = 30 years",
            "A mother is {$times} times as old as her daughter. The sum of their present ages is {$sum} years. Find the daughter's present age.",
            "ஒரு தாயின் வயது அவரது மகளின் வயதைப் போல் {$times} மடங்கு. அவர்களின் தற்போதைய வயதுகளின் கூடுதல் {$sum} ஆண்டுகள் எனில், மகளின் தற்போதைய வயது என்ன?",
            "Let the daughter's age be x; then x + {$times}x = {$sum}, so ".($times + 1)."x = {$sum} and x = {$daughter} years (mother = {$mother} years).",
            "மகளின் வயது x என்க; x + {$times}x = {$sum}; எனவே ".($times + 1)."x = {$sum}, x = {$daughter} ஆண்டுகள் (தாய் = {$mother} ஆண்டுகள்).",
            $daughter, [$sum / $times, $mother, $daughter + 2, $daughter - 2],
            $this->unit('years', 'year'), $this->unit('ஆண்டுகள்', 'ஆண்டு'),
        );
    }

    private function agesAfter(Randomizer $r): ?array
    {
        $now = $r->getInt(2, 6);
        $later = $r->getInt(2, $now - 1 >= 2 ? $now - 1 : 2);
        $years = $r->getInt(2, 20);

        if ($later >= $now) {
            return null;
        }

        // father = now × son; father + years = later × (son + years)
        $num = $years * ($later - 1);
        $den = $now - $later;

        if ($num % $den !== 0) {
            return null;
        }

        $son = intdiv($num, $den);
        $father = $now * $son;

        if ($son < 3 || $father > 75 || $father < 24) {
            return null;
        }

        return $this->draft(self::RATIO, 'hard', 104,
            "Hence daughter's present age = 6 years; and mother's present age = 5 x = 5 × 6 = 30 years",
            "A father is {$now} times as old as his son. After {$years} years, he will be {$later} times as old as his son. Find the son's present age.",
            "ஒரு தந்தையின் வயது அவரது மகனின் வயதைப் போல் {$now} மடங்கு. {$years} ஆண்டுகளுக்குப் பிறகு அவர் தம் மகனின் வயதைப் போல் {$later} மடங்கு ஆவார் எனில், மகனின் தற்போதைய வயது என்ன?",
            "Let the son's age be x: {$now}x + {$years} = {$later}(x + {$years}), so ".($now - $later)."x = ".($later * $years - $years)." and x = {$son} years (father = {$father} years).",
            "மகனின் வயது x என்க: {$now}x + {$years} = {$later}(x + {$years}); எனவே ".($now - $later)."x = ".($later * $years - $years).", x = {$son} ஆண்டுகள் (தந்தை = {$father} ஆண்டுகள்).",
            $son, [$son + $years, $father, $son + 1, $son - 1],
            $this->unit('years', 'year'), $this->unit('ஆண்டுகள்', 'ஆண்டு'),
        );
    }
}
