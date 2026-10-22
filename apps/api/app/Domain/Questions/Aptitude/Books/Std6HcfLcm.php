<?php

declare(strict_types=1);

namespace App\Domain\Questions\Aptitude\Books;

use App\Domain\Questions\Aptitude\AptitudeBook;
use Random\Randomizer;

/** HCF and LCM, from the Std 6 Mathematics Term-II book, Chapter 1 (Numbers). */
final class Std6HcfLcm extends AptitudeBook
{
    private const TOPIC = 'hcf-and-lcm';

    private const HCF_QUOTE = 'As factors of a number are finite, we can think of the Highest Common Factor of numbers, shortly denoted as HCF.';

    private const LCM_QUOTE = 'As multiples of a number are infinite, we can think of the Least Common Multiple of numbers, shortly denoted as LCM.';

    public function source(): string
    {
        return 'Std 6 Mathematics Term-II (English)';
    }

    public function breadcrumb(): string
    {
        return 'Std 6 Mathematics Term-II (English) — Chapter 1: Numbers (HCF and LCM)';
    }

    public function file(): string
    {
        return 'aptitude-std6-hcf-lcm.json';
    }

    protected function templates(): array
    {
        return [
            'hcf-two' => fn (Randomizer $r): ?array => $this->hcfTwo($r),
            'hcf-three' => fn (Randomizer $r): ?array => $this->hcfThree($r),
            'lcm-two' => fn (Randomizer $r): ?array => $this->lcmTwo($r),
            'lcm-three' => fn (Randomizer $r): ?array => $this->lcmThree($r),
            'hcf-lcm-other-number' => fn (Randomizer $r): ?array => $this->otherNumber($r),
            'hcf-sharing' => fn (Randomizer $r): ?array => $this->sharing($r),
            'lcm-packets' => fn (Randomizer $r): ?array => $this->packets($r),
            'lcm-successive' => fn (Randomizer $r): ?array => $this->successive($r),
        ];
    }

    private function hcfTwo(Randomizer $r): ?array
    {
        $h = $r->getInt(2, 30);
        $a = $r->getInt(2, 15);
        $b = $r->getInt(2, 15);
        [$x, $y] = [$h * $a, $h * $b];

        if ($a === $b || $this->gcd($a, $b) !== 1 || max($x, $y) > 600) {
            return null;
        }

        return $this->draft(self::TOPIC, 'easy', 12, self::HCF_QUOTE,
            "What is the HCF of {$x} and {$y}?",
            "{$x}, {$y} ஆகியவற்றின் மீ.பெ.கா (HCF) என்ன?",
            "{$x} = {$this->factors($x)} and {$y} = {$this->factors($y)}. The HCF is the product of the common prime factors = {$h}.",
            "{$x} = {$this->factors($x)}, {$y} = {$this->factors($y)}. பொதுவான பகாக் காரணிகளின் பெருக்கற்பலனே மீ.பெ.கா = {$h}.",
            $h, [$this->lcm($x, $y), 2 * $h, min($x, $y), $h + 1],
            $this->plain(), $this->plain(),
        );
    }

    private function hcfThree(Randomizer $r): ?array
    {
        $h = $r->getInt(2, 20);
        $a = $r->getInt(2, 12);
        $b = $r->getInt(2, 12);
        $c = $r->getInt(2, 12);
        [$x, $y, $z] = [$h * $a, $h * $b, $h * $c];

        if (count(array_unique([$a, $b, $c])) < 3 || $this->gcd($this->gcd($a, $b), $c) !== 1 || max($x, $y, $z) > 400) {
            return null;
        }

        return $this->draft(self::TOPIC, 'medium', 12, self::HCF_QUOTE,
            "Find the HCF of {$x}, {$y} and {$z}.",
            "{$x}, {$y}, {$z} ஆகியவற்றின் மீ.பெ.கா (HCF) காண்க.",
            "{$x} = {$this->factors($x)}, {$y} = {$this->factors($y)}, {$z} = {$this->factors($z)}. The factor common to all three is {$h}, so the HCF = {$h}.",
            "{$x} = {$this->factors($x)}, {$y} = {$this->factors($y)}, {$z} = {$this->factors($z)}. மூன்றுக்கும் பொதுவான காரணி {$h}; எனவே மீ.பெ.கா = {$h}.",
            $h, [2 * $h, $this->gcd($x, $y), min($x, $y, $z), $h * min($a, $b, $c)],
            $this->plain(), $this->plain(),
        );
    }

    private function lcmTwo(Randomizer $r): ?array
    {
        $x = $r->getInt(4, 60);
        $y = $r->getInt(4, 60);
        $l = $this->lcm($x, $y);

        if ($x >= $y || $l > 1800 || $l === $y) {
            return null;
        }

        return $this->draft(self::TOPIC, 'easy', 15, self::LCM_QUOTE,
            "What is the LCM of {$x} and {$y}?",
            "{$x}, {$y} ஆகியவற்றின் மீ.சி.ம (LCM) என்ன?",
            "{$x} = {$this->factors($x)} and {$y} = {$this->factors($y)}. Taking each prime the greatest number of times it occurs, LCM = {$l}.",
            "{$x} = {$this->factors($x)}, {$y} = {$this->factors($y)}. ஒவ்வொரு பகா எண்ணையும் அதிகபட்சமாக வரும் முறை எடுத்தால் மீ.சி.ம = {$l}.",
            $l, [$x * $y, $this->gcd($x, $y), 2 * $l, $l / 2],
            $this->plain(), $this->plain(),
        );
    }

    private function lcmThree(Randomizer $r): ?array
    {
        $x = $r->getInt(2, 30);
        $y = $r->getInt(2, 30);
        $z = $r->getInt(2, 30);
        $l = $this->lcm($this->lcm($x, $y), $z);

        if (! ($x < $y && $y < $z) || $l > 2500 || $l === $z) {
            return null;
        }

        return $this->draft(self::TOPIC, 'medium', 15, self::LCM_QUOTE,
            "Find the LCM of {$x}, {$y} and {$z}.",
            "{$x}, {$y}, {$z} ஆகியவற்றின் மீ.சி.ம (LCM) காண்க.",
            "{$x} = {$this->factors($x)}, {$y} = {$this->factors($y)}, {$z} = {$this->factors($z)}. Taking each prime the greatest number of times it occurs, LCM = {$l}.",
            "{$x} = {$this->factors($x)}, {$y} = {$this->factors($y)}, {$z} = {$this->factors($z)}. ஒவ்வொரு பகா எண்ணையும் அதிகபட்சமாக வரும் முறை எடுத்தால் மீ.சி.ம = {$l}.",
            $l, [$x * $y * $z, $this->lcm($x, $y), 2 * $l, $l / 2],
            $this->plain(), $this->plain(),
        );
    }

    private function otherNumber(Randomizer $r): ?array
    {
        $h = $r->getInt(2, 15);
        $a = $r->getInt(2, 12);
        $b = $r->getInt(2, 12);
        [$x, $y] = [$h * $a, $h * $b];
        $l = $this->lcm($x, $y);

        if ($a === $b || $this->gcd($a, $b) !== 1 || $l > 2000) {
            return null;
        }

        return $this->draft(self::TOPIC, 'medium', 20, 'We know that, the product of the two numbers = LCM x HCF',
            "The HCF and LCM of two numbers are {$h} and {$l}. If one of the numbers is {$x}, what is the other number?",
            "இரு எண்களின் மீ.பெ.கா {$h}, மீ.சி.ம {$l}. அவற்றுள் ஓர் எண் {$x} எனில், மற்றோர் எண் எது?",
            "Product of the two numbers = HCF × LCM, so the other number = ({$h} × {$l}) ÷ {$x} = {$y}.",
            "இரு எண்களின் பெருக்கற்பலன் = மீ.பெ.கா × மீ.சி.ம; எனவே மற்றோர் எண் = ({$h} × {$l}) ÷ {$x} = {$y}.",
            $y, [$l / $x, $l - $x, $h * $x, $l / $h],
            $this->plain(), $this->plain(),
        );
    }

    private function sharing(Randomizer $r): ?array
    {
        [$enA, $taA, $enB, $taB] = $this->pick($r, [
            ['athirasams', 'அதிரசங்கள்', 'murukkus', 'முறுக்குகள்'],
            ['laddus', 'லட்டுகள்', 'jilebis', 'ஜிலேபிகள்'],
            ['pencils', 'பென்சில்கள்', 'erasers', 'அழிப்பான்கள்'],
            ['notebooks', 'நோட்டுப் புத்தகங்கள்', 'pens', 'பேனாக்கள்'],
            ['bananas', 'வாழைப்பழங்கள்', 'oranges', 'ஆரஞ்சுப் பழங்கள்'],
        ]);
        $h = $r->getInt(3, 30);
        $a = $r->getInt(2, 13);
        $b = $r->getInt(2, 13);
        [$x, $y] = [$h * $a, $h * $b];

        if ($a === $b || $this->gcd($a, $b) !== 1 || max($x, $y) > 400) {
            return null;
        }

        return $this->draft(self::TOPIC, 'medium', 12,
            'Each relative should be given the same number of athirasams and the same number of murukkus. What is the greatest number of relatives that she can distribute?',
            "Pavithra has {$x} {$enA} and {$y} {$enB}. She gives every relative the same number of {$enA} and the same number of {$enB}, using all of them. What is the greatest number of relatives she can give them to?",
            "பவித்ராவிடம் {$x} {$taA}, {$y} {$taB} உள்ளன. அனைத்தையும் பயன்படுத்தி, ஒவ்வொரு உறவினருக்கும் சம எண்ணிக்கையில் {$taA}, சம எண்ணிக்கையில் {$taB} வழங்க வேண்டும் எனில், அதிகபட்சமாக எத்தனை உறவினர்களுக்கு வழங்கலாம்?",
            "The greatest number of relatives is the HCF of {$x} and {$y} = {$h}; each gets {$a} {$enA} and {$b} {$enB}.",
            "அதிகபட்ச உறவினர் எண்ணிக்கை = {$x}, {$y} ஆகியவற்றின் மீ.பெ.கா = {$h}; ஒவ்வொருவருக்கும் {$a} {$taA}, {$b} {$taB} கிடைக்கும்.",
            $h, [$this->lcm($x, $y), $a + $b, 2 * $h, min($x, $y)],
            $this->unit('relatives', 'relative'), $this->unit('உறவினர்கள்', 'உறவினர்'),
        );
    }

    private function packets(Randomizer $r): ?array
    {
        $a = $r->getInt(3, 20);
        $b = $r->getInt(3, 20);
        $l = $this->lcm($a, $b);

        if ($a >= $b || $l === $b) {
            return null;
        }

        return $this->draft(self::TOPIC, 'medium', 15,
            'Ragi Laddus come in packets of 4 and Thattais come in packets of 6. Anu has to buy these packets so that there are the same number of Ragi Laddus and Thattais to serve at the party.',
            "Ragi laddus come in packets of {$a} and thattais in packets of {$b}. What is the least number of each that Anu must buy so that she has the same number of ragi laddus and thattais?",
            "கேழ்வரகு லட்டுகள் {$a} கொண்ட பொட்டலங்களாகவும் தட்டைகள் {$b} கொண்ட பொட்டலங்களாகவும் கிடைக்கின்றன. இரண்டும் சம எண்ணிக்கையில் இருக்க அனு ஒவ்வொன்றிலும் குறைந்தது எத்தனை வாங்க வேண்டும்?",
            "The least common number is the LCM of {$a} and {$b} = {$l}: ".($l / $a)." packets of laddus and ".($l / $b).' packets of thattais.',
            "சம எண்ணிக்கையின் மிகக் குறைந்த அளவு = {$a}, {$b} ஆகியவற்றின் மீ.சி.ம = {$l}: லட்டுகள் ".($l / $a).' பொட்டலங்கள், தட்டைகள் '.($l / $b).' பொட்டலங்கள்.',
            $l, [$a * $b, $a + $b, $this->gcd($a, $b), 2 * $l],
            $this->plain(), $this->plain(),
        );
    }

    private function successive(Randomizer $r): ?array
    {
        $n = $r->getInt(5, 60);

        return $this->draft(self::TOPIC, 'easy', 21, 'The LCM of two successive numbers is the product of the numbers.',
            'What is the LCM of the successive numbers '.$n.' and '.($n + 1).'?',
            'அடுத்தடுத்த எண்களான '.$n.', '.($n + 1).' ஆகியவற்றின் மீ.சி.ம (LCM) என்ன?',
            'Successive numbers have no common factor other than 1, so their LCM is their product: '.$n.' × '.($n + 1).' = '.($n * ($n + 1)).'.',
            'அடுத்தடுத்த எண்களுக்கு 1 தவிர வேறு பொதுக் காரணி இல்லை; எனவே மீ.சி.ம = பெருக்கற்பலன் = '.$n.' × '.($n + 1).' = '.($n * ($n + 1)).'.',
            $n * ($n + 1), [1, 2 * $n + 1, $n * ($n + 1) / 2, ($n + 1) * ($n + 1)],
            $this->plain(), $this->plain(),
        );
    }
}
