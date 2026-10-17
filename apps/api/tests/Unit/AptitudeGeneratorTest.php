<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Questions\Aptitude\AptitudeGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Number-varied aptitude questions: correct by construction, the same every
 * time for the same seed, and growable without changing what was made.
 */
final class AptitudeGeneratorTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function questions(int $perTemplate = 20, int $seed = 7): array
    {
        return (new AptitudeGenerator)->generate($perTemplate, $seed);
    }

    /** @param array<string, mixed> $q */
    private function correct(array $q): string
    {
        return $q['en']['options'][array_search($q['answer'], ['A', 'B', 'C', 'D'], true)];
    }

    public function test_the_same_seed_gives_the_same_questions_and_a_bigger_count_only_adds(): void
    {
        $small = $this->questions(5);

        $this->assertSame($small, $this->questions(5));

        $bigger = [];
        foreach ($this->questions(10) as $q) {
            $bigger[$q['en']['q']] = $q;
        }

        foreach ($small as $q) {
            $this->assertSame($q, $bigger[$q['en']['q']] ?? null, 'A bigger file keeps every question, letter and all.');
        }
    }

    public function test_every_question_has_four_different_options_in_both_languages_and_no_repeats(): void
    {
        $questions = $this->questions();

        $this->assertCount(count($questions), array_unique(array_map(static fn (array $q): string => $q['en']['q'], $questions)));

        foreach ($questions as $q) {
            $this->assertContains($q['answer'], ['A', 'B', 'C', 'D']);
            foreach (['en', 'ta'] as $language) {
                $this->assertCount(4, array_unique($q[$language]['options']), $q[$language]['q']);
                $this->assertNotSame('', trim($q[$language]['explanation']));
            }
            $this->assertIsInt($q['page']);
            $this->assertNotSame('', $q['quote']);
        }
    }

    public function test_answers_are_computed_correctly(): void
    {
        $checked = 0;

        foreach ($this->questions(30) as $q) {
            $text = $q['en']['q'];

            if (preg_match('/^What is (\d+)% of (\d+)\?$/', $text, $m)) {
                $this->assertSame((string) ($m[1] * $m[2] / 100), $this->correct($q));
                $checked++;
            } elseif (preg_match('/^Find the simple interest on ₹(\d+) for (\d+) years? at (\d+)% per annum\.$/u', $text, $m)) {
                $this->assertSame('₹'.($m[1] * $m[2] * $m[3] / 100), $this->correct($q));
                $checked++;
            } elseif (preg_match('/^A can complete a work in (\d+) days and B in (\d+) days\./', $text, $m)) {
                $this->assertSame(($m[1] * $m[2] / ($m[1] + $m[2])).' days', $this->correct($q));
                $checked++;
            } elseif (preg_match('/^Find the compound interest on ₹(\d+) for (\d+) years at (\d+)% per annum/u', $text, $m)) {
                $ci = $m[1] * (((100 + $m[3]) / 100) ** $m[2]) - $m[1];
                $this->assertSame('₹'.round($ci), $this->correct($q));
                $checked++;
            }
        }

        $this->assertGreaterThan(80, $checked, 'Enough questions were checked to mean something.');
    }

    public function test_the_correct_letter_is_spread_across_a_to_d(): void
    {
        $letters = array_count_values(array_column($this->questions(30), 'answer'));
        $total = array_sum($letters);

        foreach (['A', 'B', 'C', 'D'] as $letter) {
            $this->assertGreaterThan($total * 0.18, $letters[$letter] ?? 0, "Too few answers are {$letter}.");
        }
    }
}
