<?php

declare(strict_types=1);

namespace App\Domain\Questions\Aptitude\Books;

use App\Domain\Questions\Aptitude\AptitudeBook;
use Random\Randomizer;

/** Counting principles, Fibonacci-type series and letter ciphers, from the Std 8 Mathematics book (Chapter 7, Information Processing). */
final class Std8InformationProcessing extends AptitudeBook
{
    private const REASONING = 'logical-reasoning-and-series';

    private const CAESAR = 'It is a type of substitution cipher in which each letter in the text is "shifted" a certain number of places down the alphabets.';

    /** Everyday words for the cipher questions. */
    private const WORDS = [
        'MATH', 'BOOK', 'TAMIL', 'RIVER', 'TIGER', 'PAPER', 'LIGHT', 'WATER', 'PLANT', 'SCHOOL', 'CHAIR', 'TABLE',
        'MONEY', 'HOUSE', 'NORTH', 'SOUTH', 'EARTH', 'MOON', 'STAR', 'RAIN', 'CLOUD', 'GREEN', 'BLACK', 'WHITE',
        'MANGO', 'APPLE', 'LEMON', 'TRAIN', 'TRUCK', 'CYCLE', 'CLOCK', 'HONEY', 'SUGAR', 'BREAD', 'PEACE', 'SMILE',
        'DREAM', 'BRAVE', 'FIELD', 'FARMER', 'TEMPLE', 'MARKET', 'GARDEN', 'PENCIL', 'WINDOW', 'FLOWER', 'SILVER', 'GOLDEN',
        'OCEAN', 'ISLAND', 'FOREST', 'DESERT', 'VALLEY', 'BRIDGE', 'TOWER', 'PALACE', 'NATION', 'VOTER', 'COURT', 'LETTER',
        'NUMBER', 'SQUARE', 'CIRCLE', 'ANGLE', 'MIRROR', 'ROCKET', 'PLANET', 'COMET', 'SPEED', 'POWER', 'METAL', 'STONE',
    ];

    /** Pairs of things to choose between, with Tamil. */
    private const CHOICES = [
        [['shirts', 'சட்டைகள்'], ['trousers', 'கால்சட்டைகள்'], ['a shirt and a pair of trousers', 'ஒரு சட்டையும் ஒரு கால்சட்டையும்'], ['a shirt or a pair of trousers', 'ஒரு சட்டை அல்லது ஒரு கால்சட்டை']],
        [['Tamil novels', 'தமிழ்ப் புதினங்கள்'], ['English novels', 'ஆங்கிலப் புதினங்கள்'], ['one Tamil novel and one English novel', 'ஒரு தமிழ்ப் புதினமும் ஒரு ஆங்கிலப் புதினமும்'], ['one novel', 'ஒரு புதினம்']],
        [['tiffin items', 'சிற்றுண்டி வகைகள்'], ['meals items', 'உணவு வகைகள்'], ['one tiffin item and one meals item', 'ஒரு சிற்றுண்டியும் ஒரு உணவு வகையும்'], ['either a tiffin item or a meals item', 'ஒரு சிற்றுண்டி அல்லது ஒரு உணவு வகை']],
        [['buses', 'பேருந்துகள்'], ['trains', 'தொடர்வண்டிகள்'], ['a bus to go and a train to return', 'செல்லப் பேருந்தும் திரும்பத் தொடர்வண்டியும்'], ['either a bus or a train', 'ஒரு பேருந்து அல்லது ஒரு தொடர்வண்டி']],
        [['boys', 'மாணவர்கள்'], ['girls', 'மாணவிகள்'], ['one boy and one girl', 'ஒரு மாணவனும் ஒரு மாணவியும்'], ['one student, a boy or a girl,', 'ஒரு மாணவர் அல்லது மாணவி']],
    ];

    public function source(): string
    {
        return 'Std 8 Mathematics Term-Full (English)';
    }

    public function breadcrumb(): string
    {
        return 'Std 8 Mathematics (English) — Chapter 7: Information Processing';
    }

    public function file(): string
    {
        return 'aptitude-std8-information-processing.json';
    }

    protected function templates(): array
    {
        return [
            'counting-either' => fn (Randomizer $r): ?array => $this->either($r),
            'counting-both' => fn (Randomizer $r): ?array => $this->both($r),
            'counting-three' => fn (Randomizer $r): ?array => $this->three($r),
            'fibonacci-type' => fn (Randomizer $r): ?array => $this->fibonacci($r),
            'caesar-encode' => fn (Randomizer $r): ?array => $this->encode($r),
            'caesar-decode' => fn (Randomizer $r): ?array => $this->decode($r),
            'additive-cipher' => fn (Randomizer $r): ?array => $this->additive($r),
        ];
    }

    private function either(Randomizer $r): ?array
    {
        [$a, $b, , $or] = $this->pick($r, self::CHOICES);
        $m = $r->getInt(3, 40);
        $n = $r->getInt(3, 40);

        return $this->draft(self::REASONING, 'easy', 234, 'then either of the two selections can be done in (m + n) ways.',
            "There are {$m} {$a[0]} and {$n} {$b[0]}. In how many ways can {$or[0]} be chosen?",
            "{$m} {$a[1]}, {$n} {$b[1]} உள்ளன. {$or[1]} தேர்ந்தெடுக்க எத்தனை வழிகள் உள்ளன?",
            "Only one is chosen, from one group or the other, so by the addition principle there are {$m} + {$n} = ".($m + $n).' ways.',
            "ஏதேனும் ஒரு குழுவிலிருந்து ஒன்று மட்டுமே தேர்ந்தெடுக்கப்படுகிறது; கூட்டல் கொள்கைப்படி {$m} + {$n} = ".($m + $n).' வழிகள்.',
            $m + $n, [$m * $n, abs($m - $n) ?: $m + $n + 2, $m + $n + 1, $m + $n - 1],
            $this->unit('ways', 'way'), $this->unit('வழிகள்', 'வழி'),
        );
    }

    private function both(Randomizer $r): ?array
    {
        [$a, $b, $and] = $this->pick($r, self::CHOICES);
        $m = $r->getInt(2, 25);
        $n = $r->getInt(2, 25);

        return $this->draft(self::REASONING, 'easy', 235, 'If a selection can be performed in m ways, following which another selection can be performed in n ways',
            "There are {$m} {$a[0]} and {$n} {$b[0]}. In how many ways can {$and[0]} be chosen?",
            "{$m} {$a[1]}, {$n} {$b[1]} உள்ளன. {$and[1]} தேர்ந்தெடுக்க எத்தனை வழிகள் உள்ளன?",
            "Both choices are made together, so by the multiplication principle there are {$m} × {$n} = ".($m * $n).' ways.',
            "இரண்டும் சேர்ந்து தேர்ந்தெடுக்கப்படுகின்றன; பெருக்கல் கொள்கைப்படி {$m} × {$n} = ".($m * $n).' வழிகள்.',
            $m * $n, [$m + $n, $m * $n - 1, $m * ($n + 1), $m * $n * 2],
            $this->unit('ways', 'way'), $this->unit('வழிகள்', 'வழி'),
        );
    }

    private function three(Randomizer $r): ?array
    {
        $s = $r->getInt(2, 12);
        $p = $r->getInt(2, 12);
        $f = $r->getInt(2, 8);
        $ways = $s * $p * $f;

        return $this->draft(self::REASONING, 'medium', 235, 'If a selection can be performed in m ways, following which another selection can be performed in n ways',
            "Arun has {$s} shirts, {$p} pairs of trousers and {$f} pairs of shoes. In how many different ways can he dress, wearing one of each?",
            "அருணிடம் {$s} சட்டைகள், {$p} கால்சட்டைகள், {$f} சோடி காலணிகள் உள்ளன. ஒவ்வொன்றிலும் ஒன்றை அணிந்து அவர் எத்தனை வெவ்வேறு வழிகளில் உடை அணியலாம்?",
            "By the multiplication principle: {$s} × {$p} × {$f} = {$ways} ways.",
            "பெருக்கல் கொள்கைப்படி: {$s} × {$p} × {$f} = {$ways} வழிகள்.",
            $ways, [$s + $p + $f, $s * $p + $f, $s * $p, $ways * 2],
            $this->unit('ways', 'way'), $this->unit('வழிகள்', 'வழி'),
        );
    }

    private function fibonacci(Randomizer $r): ?array
    {
        $a = $r->getInt(1, 15);
        $b = $r->getInt(1, 20);
        $terms = [$a, $b];
        while (count($terms) < 7) {
            $terms[] = $terms[count($terms) - 1] + $terms[count($terms) - 2];
        }
        $next = array_pop($terms);
        $list = implode(', ', $terms);
        [$p, $q] = [$terms[4], $terms[5]];

        return $this->draft(self::REASONING, 'medium', 247, 'We observe that the 3rd term of the Fibonacci sequence',
            "Find the next number: {$list}, ?",
            "அடுத்த எண் எது? {$list}, ?",
            "As in the Fibonacci sequence, each number is the sum of the two before it: {$p} + {$q} = {$next}.",
            "ஃபிபோனச்சி தொடரில் உள்ளதுபோல், ஒவ்வோர் எண்ணும் அதற்கு முந்தைய இரண்டு எண்களின் கூடுதல்: {$p} + {$q} = {$next}.",
            $next, [$q + ($q - $p), 2 * $q, $next + $p, $next - 1],
            $this->plain(), $this->plain(),
        );
    }

    private function shift(string $word, int $k): string
    {
        return implode('', array_map(fn (string $c): string => chr(65 + ((ord($c) - 65 + $k) % 26 + 26) % 26), str_split($word)));
    }

    private function encode(Randomizer $r): ?array
    {
        $word = $this->pick($r, self::WORDS);
        $k = $r->getInt(1, 12);
        $options = [$this->shift($word, $k), $this->shift($word, -$k), $this->shift($word, $k + 1), $this->shift($word, $k - 1 ?: $k + 2), $this->shift($word, $k + 3)];

        return $this->textDraft(self::REASONING, 'medium', 255, self::CAESAR,
            "In a code, each letter is replaced by the letter {$k} places after it in the alphabet (after Z, count on from A). How is {$word} written in this code?",
            "ஒரு குறியீட்டு முறையில், ஒவ்வோர் எழுத்தும் ஆங்கில அகரவரிசையில் அதற்கு {$k} இடங்கள் பின்னால் உள்ள எழுத்தால் மாற்றப்படுகிறது (Z க்குப் பின் A யிலிருந்து தொடர்க). இம்முறையில் {$word} எவ்வாறு எழுதப்படும்?",
            "Shift every letter {$k} places forward: {$word} → {$options[0]}.",
            "ஒவ்வோர் எழுத்தையும் {$k} இடங்கள் முன்னோக்கி நகர்த்துக: {$word} → {$options[0]}.",
            $options,
        );
    }

    private function decode(Randomizer $r): ?array
    {
        $word = $this->pick($r, self::WORDS);
        $k = $r->getInt(1, 12);
        $coded = $this->shift($word, $k);
        $options = [$word, $this->shift($coded, $k), $this->shift($word, 1), $this->shift($word, -1), $this->shift($coded, 1 - $k)];

        if ($k === 1) {
            $options[2] = $this->shift($word, 2);
        }

        return $this->textDraft(self::REASONING, 'medium', 255, self::CAESAR,
            "In a code, each letter is replaced by the letter {$k} places after it in the alphabet (after Z, count on from A). A word is written as {$coded} in this code. What is the word?",
            "ஒரு குறியீட்டு முறையில், ஒவ்வோர் எழுத்தும் ஆங்கில அகரவரிசையில் அதற்கு {$k} இடங்கள் பின்னால் உள்ள எழுத்தால் மாற்றப்படுகிறது (Z க்குப் பின் A யிலிருந்து தொடர்க). இம்முறையில் ஒரு சொல் {$coded} என எழுதப்படுகிறது எனில், அச்சொல் எது?",
            "To decode, shift every letter {$k} places back: {$coded} → {$word}.",
            "குறியீட்டை விடுவிக்க, ஒவ்வோர் எழுத்தையும் {$k} இடங்கள் பின்னோக்கி நகர்த்துக: {$coded} → {$word}.",
            $options,
        );
    }

    private function additive(Randomizer $r): ?array
    {
        $word = strtolower($this->pick($r, array_values(array_filter(self::WORDS, fn (string $w): bool => strlen($w) <= 4))));
        $key = $r->getInt(1, 25);
        $code = fn (int $k): string => implode(' ', array_map(fn (string $c): string => sprintf('%02d', (ord($c) - 97 + $k) % 26), str_split($word)));
        $plain = implode(' ', array_map(fn (string $c): string => sprintf('%02d', ord($c) - 97 + $key), str_split($word)));
        $options = [$code($key), $code($key + 1), $code($key - 1 + 26), $plain, $code($key + 2)];
        $first = sprintf('%02d', $key);
        $second = sprintf('%02d', ($key + 1) % 26);
        $last = sprintf('%02d', ($key + 25) % 26);

        return $this->textDraft(self::REASONING, 'hard', 256, 'As per key number, we have to start and fill a = 05, b = 06 ........ z = 04 respectively.',
            "In an additive cipher with key {$key}, the letters are numbered a = {$first}, b = {$second}, …, z = {$last} (after 25, the numbers start again from 00). What is the cipher number for \"{$word}\"?",
            "திறவுகோல் {$key} உடைய கூட்டல் மறைக்குறியீட்டில் எழுத்துகளுக்கு a = {$first}, b = {$second}, …, z = {$last} என எண்கள் தரப்படுகின்றன (25 க்குப் பின் மீண்டும் 00 இலிருந்து தொடங்கும்). \"{$word}\" என்பதன் மறைக்குறியீட்டு எண் என்ன?",
            "Plain numbers a = 00, b = 01, … are increased by the key {$key}, wrapping after 25: \"{$word}\" → {$options[0]}.",
            "a = 00, b = 01, … என்ற எண்களுடன் திறவுகோல் {$key} கூட்டப்பட்டு, 25 க்குப் பின் மீண்டும் 00 இலிருந்து தொடர்கிறது: \"{$word}\" → {$options[0]}.",
            $options,
        );
    }
}
