<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pyq\DTOs\ParsedPyq;
use App\Domain\Pyq\PyqParser;
use Tests\TestCase;

/**
 * Real papers are laid out in ways a line-by-line reader gets wrong, and here
 * a wrong read is not cosmetic: a key that leaks into option D shows the
 * answers during the test, and a key pinned to the wrong question makes the
 * score wrong. Every case is a layout a real paper uses.
 */
final class PyqPaperLayoutTest extends TestCase
{
    private const FIVE = <<<'TXT'
    1. Which Article guarantees the Right to Equality?
    (A) Article 14 (B) Article 19 (C) Article 21 (D) Article 32
    2. Who is the head of the Union Executive?
    (A) The Prime Minister (B) The President (C) The Chief Justice (D) The Speaker
    3. Which planet is closest to the Sun?
    (A) Venus (B) Mercury (C) Mars (D) Earth
    4. Which gas do plants absorb?
    (A) Oxygen (B) Nitrogen (C) Carbon dioxide (D) Helium
    5. Who wrote Silappathikaram?
    (A) Ilango Adigal (B) Kambar (C) Valluvar (D) Bharathi
    TXT;

    private function parser(): PyqParser
    {
        return app(PyqParser::class);
    }

    /**
     * Nothing a student sees during the test may carry the key.
     *
     * @param  list<ParsedPyq>  $questions
     */
    private function assertNoKeyText(array $questions): void
    {
        foreach ($questions as $question) {
            foreach ($question->translations as $version) {
                foreach ([$version['stem'], ...array_values($version['options'])] as $text) {
                    $this->assertDoesNotMatchRegularExpression(
                        '/answer|key|விடை|\b\d{1,3}\s*[-–:.)]\s*\(?[A-D]\)?(\s|$)/iu',
                        $text,
                        "Question {$question->number} shows key text: {$text}",
                    );
                }
            }
        }
    }

    /** @param  list<ParsedPyq>  $questions */
    private function keys(array $questions): array
    {
        return array_map(static fn (ParsedPyq $q): ?string => $q->correctOption, $questions);
    }

    public function test_a_key_page_after_the_last_question_does_not_leak_into_option_d(): void
    {
        $paper = $this->parser()->read([self::FIVE, "ANSWER KEY\n1 - A   2 - B   3 - B   4 - C   5 - A"]);

        $this->assertCount(5, $paper->questions);
        $this->assertSame('Bharathi', $paper->questions[4]->options['D']);
        $this->assertSame(['A', 'B', 'B', 'C', 'A'], $this->keys($paper->questions));
        $this->assertNoKeyText($paper->questions);
    }

    public function test_a_key_grid_straight_after_the_last_question_is_cut_off(): void
    {
        $paper = $this->parser()->read([self::FIVE."\n1 - A 2 - B 3 - B 4 - C 5 - A"]);

        $this->assertSame('Bharathi', $paper->questions[4]->options['D']);
        $this->assertSame(['A', 'B', 'B', 'C', 'A'], $this->keys($paper->questions));
        $this->assertNoKeyText($paper->questions);
    }

    public function test_an_inline_key_heading_is_cut_off_and_read(): void
    {
        $paper = $this->parser()->read([self::FIVE."\nAnswer key: 1-A 2-B 3-B 4-C 5-A"]);

        $this->assertSame('Bharathi', $paper->questions[4]->options['D']);
        $this->assertSame(['A', 'B', 'B', 'C', 'A'], $this->keys($paper->questions));
        $this->assertNoKeyText($paper->questions);
    }

    public function test_an_answer_line_under_each_question_is_its_key_and_is_not_shown(): void
    {
        $paper = $this->parser()->read([<<<'TXT'
        1. Which Article guarantees the Right to Equality?
        (A) Article 14 (B) Article 19 (C) Article 21 (D) Article 32
        Answer: (A)
        2. Who is the head of the Union Executive?
        (A) The Prime Minister (B) The President (C) The Chief Justice (D) The Speaker
        Ans. B
        3. சூரியனுக்கு அருகில் உள்ள கோள் எது?
        (அ) வெள்ளி (ஆ) புதன் (இ) செவ்வாய் (ஈ) பூமி
        விடை: ஆ
        TXT]);

        $this->assertCount(3, $paper->questions);
        $this->assertSame(['A', 'B', 'B'], $this->keys($paper->questions));
        $this->assertSame('Article 32', $paper->questions[0]->options['D']);
        $this->assertSame('The Speaker', $paper->questions[1]->options['D']);
        $this->assertSame('பூமி', $paper->questions[2]->options['D']);
        $this->assertNoKeyText($paper->questions);
    }

    public function test_numbered_statements_in_the_middle_of_a_paper_stay_inside_their_question(): void
    {
        $paper = $this->parser()->read([<<<'TXT'
        1. Which planet is closest to the Sun?
        (A) Venus (B) Mercury (C) Mars (D) Earth
        2. Which gas do plants absorb?
        (A) Oxygen (B) Nitrogen (C) Carbon dioxide (D) Helium
        3. Consider the following statements:
        1. The Sun is a star.
        2. The Moon is a planet.
        Which of the above is/are correct?
        (A) 1 only (B) 2 only (C) Both 1 and 2 (D) Neither 1 nor 2
        4. Who wrote Silappathikaram?
        (A) Ilango Adigal (B) Kambar (C) Valluvar (D) Bharathi
        5. Which is the national animal of India?
        (A) Lion (B) Tiger (C) Elephant (D) Peacock
        TXT, "ANSWER KEY\n1-B 2-C 3-A 4-A 5-B"]);

        $this->assertSame([1, 2, 3, 4, 5], array_map(static fn (ParsedPyq $q): int => $q->number, $paper->questions));
        $this->assertStringContainsString('1. The Sun is a star. 2. The Moon is a planet.', $paper->questions[2]->stem);
        // Each answer on its own question: a dropped question would shift these.
        $this->assertSame(['B', 'C', 'A', 'A', 'B'], $this->keys($paper->questions));
        $this->assertSame(1, $paper->sections(), 'Statements numbered 1 and 2 are not a new part.');
    }

    public function test_statements_under_the_first_question_are_not_read_as_questions_two_and_three(): void
    {
        $paper = $this->parser()->read([<<<'TXT'
        1. Consider the following statements:
        1. The Sun is a star.
        2. The Moon is a planet.
        3. Mars is red.
        Which of these are correct?
        (A) 1 only (B) 1 and 3 (C) 2 only (D) All of them
        2. Which gas do plants absorb?
        (A) Oxygen (B) Nitrogen (C) Carbon dioxide (D) Helium
        3. Who wrote Silappathikaram?
        (A) Ilango Adigal (B) Kambar (C) Valluvar (D) Bharathi
        TXT]);

        $this->assertCount(3, $paper->questions);
        $this->assertStringContainsString('3. Mars is red.', $paper->questions[0]->stem);
        $this->assertSame('Which gas do plants absorb?', $paper->questions[1]->stem);
    }

    public function test_q_prefixed_numbering_is_read(): void
    {
        $paper = $this->parser()->read([<<<'TXT'
        Q.1 Which planet is closest to the Sun?
        (A) Venus (B) Mercury (C) Mars (D) Earth
        Q2. Which gas do plants absorb?
        (A) Oxygen (B) Nitrogen (C) Carbon dioxide (D) Helium
        Question 3 Who wrote Silappathikaram?
        (A) Ilango Adigal (B) Kambar (C) Valluvar (D) Bharathi
        TXT]);

        $this->assertSame([1, 2, 3], array_map(static fn (ParsedPyq $q): int => $q->number, $paper->questions));
        $this->assertSame('Who wrote Silappathikaram?', $paper->questions[2]->stem);
    }

    public function test_the_answer_not_known_choice_is_not_part_of_option_d(): void
    {
        $paper = $this->parser()->read([<<<'TXT'
        1. Which planet is closest to the Sun?
        (A) Venus (B) Mercury (C) Mars (D) Earth (E) Answer not known
        2. சூரியனுக்கு அருகில் உள்ள கோள் எது?
        (அ) வெள்ளி (ஆ) புதன் (இ) செவ்வாய் (ஈ) பூமி (உ) விடை தெரியவில்லை
        TXT]);

        $this->assertCount(2, $paper->questions);
        $this->assertSame('Earth', $paper->questions[0]->options['D']);
        $this->assertSame('பூமி', $paper->questions[1]->options['D']);
        $this->assertSame(['A', 'B', 'C', 'D'], array_keys($paper->questions[0]->options));
    }

    public function test_parts_that_restart_their_numbering_are_all_kept_and_keyed_part_by_part(): void
    {
        $pages = [<<<'TXT'
        PART A - GENERAL STUDIES
        1. Which planet is closest to the Sun?
        (A) Venus (B) Mercury (C) Mars (D) Earth
        2. Which gas do plants absorb?
        (A) Oxygen (B) Nitrogen (C) Carbon dioxide (D) Helium
        PART B - GENERAL ENGLISH
        1. Choose the synonym of 'happy'.
        (A) Sad (B) Glad (C) Angry (D) Tired
        2. Choose the antonym of 'tall'.
        (A) Short (B) High (C) Long (D) Big
        TXT, "ANSWER KEY\n1-B 2-C\n1-B 2-A"];

        $paper = $this->parser()->read($pages);

        $this->assertCount(4, $paper->questions);
        $this->assertSame([1, 1, 2, 2], array_map(static fn (ParsedPyq $q): int => $q->section, $paper->questions));
        $this->assertSame('Helium', $paper->questions[1]->options['D'], 'The PART B heading is not part of an option.');
        $this->assertSame(['B', 'C', 'B', 'A'], $this->keys($paper->questions));
        $this->assertContains(['code' => 'sections', 'params' => ['count' => 2]], $paper->notes);

        // The PYQ analysis stores one row per printed number, as it always has:
        // parse() keeps the first part's question for a repeated number.
        $legacy = $this->parser()->parse($pages);
        $this->assertSame([1, 2], array_map(static fn (ParsedPyq $q): int => $q->number, $legacy));
        $this->assertSame('Which planet is closest to the Sun?', $legacy[0]->stem);
    }

    public function test_a_single_key_for_a_paper_in_parts_is_matched_to_the_first_part_with_a_note(): void
    {
        $paper = $this->parser()->read([<<<'TXT'
        1. Which planet is closest to the Sun?
        (A) Venus (B) Mercury (C) Mars (D) Earth
        2. Which gas do plants absorb?
        (A) Oxygen (B) Nitrogen (C) Carbon dioxide (D) Helium
        PART B
        1. Choose the synonym of 'happy'.
        (A) Sad (B) Glad (C) Angry (D) Tired
        2. Choose the antonym of 'tall'.
        (A) Short (B) High (C) Long (D) Big
        TXT, "ANSWER KEY\n1-B 2-C"]);

        $this->assertSame(['B', 'C', null, null], $this->keys($paper->questions));
        $this->assertContains(['code' => 'key_first_section_only', 'params' => ['sections' => 2]], $paper->notes);
    }

    public function test_a_whole_tamil_part_followed_by_the_english_part_becomes_one_bilingual_set(): void
    {
        $paper = $this->parser()->read([<<<'TXT'
        1. சூரியனுக்கு அருகில் உள்ள கோள் எது?
        (அ) வெள்ளி (ஆ) புதன் (இ) செவ்வாய் (ஈ) பூமி
        2. தாவரங்கள் உறிஞ்சும் வாயு எது?
        (அ) ஆக்சிஜன் (ஆ) நைட்ரஜன் (இ) கார்பன் டை ஆக்சைடு (ஈ) ஹீலியம்
        1. Which planet is closest to the Sun?
        (A) Venus (B) Mercury (C) Mars (D) Earth
        2. Which gas do plants absorb?
        (A) Oxygen (B) Nitrogen (C) Carbon dioxide (D) Helium
        TXT]);

        $this->assertCount(2, $paper->questions);
        $this->assertSame(1, $paper->sections());
        $this->assertSame('ta', $paper->questions[0]->language);
        $this->assertSame('Which planet is closest to the Sun?', $paper->questions[0]->translations['en']['stem']);
    }

    public function test_match_the_following_lists_do_not_become_the_options(): void
    {
        $paper = $this->parser()->read([<<<'TXT'
        1. Match the following:
        a. Kambar - 1. Ramayanam
        b. Ilango - 2. Silappathikaram
        c. Valluvar - 3. Thirukkural
        d. Sekkizhar - 4. Periyapuranam
        (A) 1 2 3 4 (B) 2 1 3 4 (C) 1 3 2 4 (D) 4 3 2 1
        2. Which gas do plants absorb?
        (A) Oxygen (B) Nitrogen (C) Carbon dioxide (D) Helium
        TXT]);

        $this->assertCount(2, $paper->questions);
        $this->assertSame(['A' => '1 2 3 4', 'B' => '2 1 3 4', 'C' => '1 3 2 4', 'D' => '4 3 2 1'], $paper->questions[0]->options);
        $this->assertStringContainsString('d. Sekkizhar - 4. Periyapuranam', $paper->questions[0]->stem);
    }

    public function test_a_bracketed_key_on_a_short_paper_is_read(): void
    {
        $paper = $this->parser()->read([<<<'TXT'
        1. Which planet is closest to the Sun?
        (A) Venus (B) Mercury (C) Mars (D) Earth
        2. Which gas do plants absorb?
        (A) Oxygen (B) Nitrogen (C) Carbon dioxide (D) Helium
        3. Who wrote Silappathikaram?
        (A) Ilango Adigal (B) Kambar (C) Valluvar (D) Bharathi
        1. (B) 2. (C) 3. (A)
        TXT]);

        $this->assertCount(3, $paper->questions);
        $this->assertSame(['B', 'C', 'A'], $this->keys($paper->questions));
        $this->assertNoKeyText($paper->questions);
    }

    public function test_a_typed_key_that_restarts_is_split_into_parts(): void
    {
        $this->assertSame(
            [[1 => 'B', 2 => 'C'], [1 => 'B', 2 => 'A']],
            $this->parser()->readAnswerKeySections('1-B 2-C 1-B 2-A'),
        );
    }
}
