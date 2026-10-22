<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Questions\Aptitude\AptitudeBook;
use App\Domain\Questions\Aptitude\AptitudeBooks;
use App\Domain\Questions\Aptitude\AptitudeGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The textbook aptitude sets: each one correct by construction, the same
 * every time for the same seed, and growable without changing what was made.
 */
final class AptitudeBooksTest extends TestCase
{
    /** @param array<string, mixed> $q */
    private function correct(array $q): string
    {
        return $q['en']['options'][array_search($q['answer'], ['A', 'B', 'C', 'D'], true)];
    }

    private function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : $this->gcd($b, $a % $b);
    }

    /** @return list<array<string, mixed>> */
    private function everything(int $perTemplate): array
    {
        return array_merge(...array_map(static fn (AptitudeBook $book): array => $book->generate($perTemplate, 7), AptitudeBooks::all()));
    }

    public function test_every_book_has_its_own_file_and_breadcrumb(): void
    {
        $books = AptitudeBooks::all();

        $this->assertCount(count($books), array_unique(array_map(static fn (AptitudeBook $b): string => $b->file(), $books)));
        $this->assertCount(count($books) + 1, array_unique(AptitudeBooks::breadcrumbs()));
        $this->assertTrue(AptitudeBooks::isGenerated(AptitudeGenerator::BREADCRUMB));
        $this->assertTrue(AptitudeBooks::isGenerated($books[0]->breadcrumb()));
        $this->assertFalse(AptitudeBooks::isGenerated('Std 7 Social Science Term-I (English) — History Unit 1: Sources of Medieval India'));
        $this->assertFalse(AptitudeBooks::isGenerated(null));
    }

    public function test_the_same_seed_gives_the_same_questions_and_a_bigger_count_only_adds(): void
    {
        foreach (AptitudeBooks::all() as $book) {
            $small = $book->generate(4, 7);

            $this->assertSame($small, $book->generate(4, 7));

            $bigger = [];
            foreach ($book->generate(8, 7) as $q) {
                $bigger[$q['en']['q']] = $q;
            }

            foreach ($small as $q) {
                $this->assertSame($q, $bigger[$q['en']['q']] ?? null, $book->file().': a bigger file keeps every question, letter and all.');
            }
        }
    }

    public function test_every_method_makes_questions_with_four_different_options_in_both_languages(): void
    {
        foreach (AptitudeBooks::all() as $book) {
            $questions = $book->generate(15, 7);

            $this->assertGreaterThanOrEqual($book->methodCount() * 10, count($questions), $book->file().': every method should make questions.');
            $this->assertCount(count($questions), array_unique(array_map(static fn (array $q): string => $q['en']['q'], $questions)));

            foreach ($questions as $q) {
                $this->assertContains($q['answer'], ['A', 'B', 'C', 'D']);
                $this->assertSame('mcq4', $q['format']);
                foreach (['en', 'ta'] as $language) {
                    $this->assertCount(4, array_unique($q[$language]['options']), $q[$language]['q']);
                    $this->assertNotSame('', trim($q[$language]['q']));
                    $this->assertNotSame('', trim($q[$language]['explanation']));
                }
                $this->assertIsInt($q['page']);
                $this->assertNotSame('', $q['quote']);
            }
        }
    }

    public function test_wrong_options_beside_a_whole_number_answer_are_whole_numbers_too(): void
    {
        foreach ($this->everything(15) as $q) {
            $right = $this->correct($q);

            if (! preg_match('/^₹?\d+(?:[^\d.].*)?$/u', $right)) {
                continue;
            }

            foreach ($q['en']['options'] as $option) {
                $this->assertDoesNotMatchRegularExpression('/^₹?-?\d+\.\d/u', $option, $q['en']['q']);
            }
        }
    }

    public function test_answers_are_computed_correctly(): void
    {
        $checked = 0;

        foreach ($this->everything(30) as $q) {
            $text = $q['en']['q'];
            $right = $this->correct($q);

            if (preg_match('/^What is the HCF of (\d+) and (\d+)\?$/', $text, $m)) {
                $this->assertSame((string) $this->gcd((int) $m[1], (int) $m[2]), $right);
            } elseif (preg_match('/^What is the LCM of (\d+) and (\d+)\?$/', $text, $m)) {
                $this->assertSame((string) intdiv($m[1] * $m[2], $this->gcd((int) $m[1], (int) $m[2])), $right);
            } elseif (preg_match('/^Find the area of a rectangle of length (\d+) (\w+) and breadth (\d+) \w+\.$/', $text, $m)) {
                $this->assertSame(($m[1] * $m[3])." sq. {$m[2]}", $right);
            } elseif (preg_match('/^Find the area of a rhombus whose diagonals are (\d+) (\w+) and (\d+) \w+\.$/', $text, $m)) {
                $this->assertSame(($m[1] * $m[3] / 2)." sq. {$m[2]}", $right);
            } elseif (preg_match('/^Find the area of a circle of radius (\d+) (\w+)\./', $text, $m)) {
                $this->assertSame((22 * $m[1] * $m[1] / 7)." sq. {$m[2]}", $right);
            } elseif (preg_match('/^Find the amount when ₹(\d+) is lent at (\d+)% per annum simple interest for (\d+) years\.$/u', $text, $m)) {
                $this->assertSame('₹'.($m[1] + $m[1] * $m[2] * $m[3] / 100), $right);
            } elseif (preg_match('/^Find the square root of (\d+)\.$/', $text, $m)) {
                $this->assertSame((string) (int) sqrt((float) $m[1]), $right);
            } elseif (preg_match('/^Find the volume of a cuboid of length (\d+) cm, breadth (\d+) cm and height (\d+) cm\.$/', $text, $m)) {
                $this->assertSame(($m[1] * $m[2] * $m[3]).' cm³', $right);
            } elseif (preg_match('/^Find the total surface area of a cube of side (\d+) cm\.$/', $text, $m)) {
                $this->assertSame((6 * $m[1] * $m[1]).' cm²', $right);
            } elseif (preg_match('/^Find the volume of a right circular cylinder of radius (\d+) cm and height (\d+) cm\./', $text, $m)) {
                $this->assertSame((22 * $m[1] * $m[1] * $m[2] / 7).' cm³', $right);
            } elseif (preg_match('/^Find the slant height of a right circular cone of radius (\d+) cm and height (\d+) cm\.$/', $text, $m)) {
                $this->assertSame(((int) sqrt($m[1] ** 2 + $m[2] ** 2)).' cm', $right);
            } elseif (preg_match('/^If (\d+) : (\d+) :: (\d+) : x, find x\.$/', $text, $m)) {
                $this->assertSame((string) ($m[2] * $m[3] / $m[1]), $right);
            } elseif (preg_match('/^Find the unit digit of (\d+)([⁰¹²³⁴⁵⁶⁷⁸⁹]+)\.$/u', $text, $m)) {
                $power = (int) strtr($m[2], ['⁰' => '0', '¹' => '1', '²' => '2', '³' => '3', '⁴' => '4', '⁵' => '5', '⁶' => '6', '⁷' => '7', '⁸' => '8', '⁹' => '9']);
                $digit = 1;
                for ($i = 0; $i < $power; $i++) {
                    $digit = $digit * ((int) $m[1] % 10) % 10;
                }
                $this->assertSame((string) $digit, $right, $text);
            } elseif (preg_match('/^How many minutes are there in (\d+) hours (\d+) minutes\?$/', $text, $m)) {
                $this->assertSame(($m[1] * 60 + $m[2]).' minutes', $right);
            } elseif (preg_match('/^In a code, each letter is replaced by the letter (\d+) places after it .* How is ([A-Z]+) written in this code\?$/', $text, $m)) {
                $shifted = implode('', array_map(static fn (string $c): string => chr(65 + (ord($c) - 65 + (int) $m[1]) % 26), str_split($m[2])));
                $this->assertSame($shifted, $right);
            } elseif (preg_match('/^Find the next number: ([\d, ]+), \?$/', $text, $m)) {
                $terms = array_map('intval', explode(', ', $m[1]));
                $this->assertSame((string) (array_pop($terms) + array_pop($terms)), $right);
            } else {
                continue;
            }

            $checked++;
        }

        $this->assertGreaterThan(200, $checked, 'Enough questions were checked to mean something.');
    }

    public function test_the_correct_letter_is_spread_across_a_to_d(): void
    {
        $letters = array_count_values(array_column($this->everything(30), 'answer'));
        $total = array_sum($letters);

        foreach (['A', 'B', 'C', 'D'] as $letter) {
            $this->assertGreaterThan($total * 0.2, $letters[$letter] ?? 0, "Too few answers are {$letter}.");
        }
    }
}
