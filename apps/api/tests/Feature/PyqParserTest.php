<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pyq\PyqParser;
use Tests\TestCase;

/**
 * The parser reads the one corpus that must stay verbatim, so its failure mode
 * matters more than its hit rate: a question it cannot read confidently must be
 * skipped, never guessed. Every case here is a shape a real TNPSC paper uses.
 */
final class PyqParserTest extends TestCase
{
    private function parser(): PyqParser
    {
        return app(PyqParser::class);
    }

    public function test_it_reads_a_plain_english_paper(): void
    {
        $parsed = $this->parser()->parse([<<<'TXT'
        1. Which Article of the Constitution guarantees the Right to Equality?
        (A) Article 14
        (B) Article 19
        (C) Article 21
        (D) Article 32

        2. Who is the head of the Union Executive in India?
        (A) The Prime Minister
        (B) The President
        (C) The Chief Justice
        (D) The Speaker
        TXT]);

        $this->assertCount(2, $parsed);
        $this->assertSame(1, $parsed[0]->number);
        $this->assertSame(
            'Which Article of the Constitution guarantees the Right to Equality?',
            $parsed[0]->stem,
        );
        $this->assertSame('Article 14', $parsed[0]->options['A']);
        $this->assertSame('Article 32', $parsed[0]->options['D']);
        $this->assertSame('The President', $parsed[1]->options['B']);
    }

    public function test_it_reads_tamil_option_labels(): void
    {
        // அ ஆ இ ஈ, not A B C D. A Latin-only parser returns the stem with every
        // option still attached to it and calls that a question.
        $parsed = $this->parser()->parse([<<<'TXT'
        1. தமிழ் மொழியின் தொன்மையை உணர்த்தும் நூல் எது?
        அ) தொல்காப்பியம்
        ஆ) திருக்குறள்
        இ) சிலப்பதிகாரம்
        ஈ) மணிமேகலை
        TXT]);

        $this->assertCount(1, $parsed);
        $this->assertSame('தமிழ் மொழியின் தொன்மையை உணர்த்தும் நூல் எது?', $parsed[0]->stem);
        $this->assertSame('தொல்காப்பியம்', $parsed[0]->options['A']);
        $this->assertSame('மணிமேகலை', $parsed[0]->options['D']);
    }

    public function test_it_applies_the_answer_grid_printed_at_the_end(): void
    {
        $parsed = $this->parser()->parse([
            <<<'TXT'
            1. Which planet is closest to the Sun?
            (A) Venus (B) Mercury (C) Mars (D) Earth

            2. Which gas do plants absorb?
            (A) Oxygen (B) Nitrogen (C) Carbon dioxide (D) Helium

            3. Who wrote Silappathikaram?
            (A) Ilango Adigal (B) Kambar (C) Valluvar (D) Bharathi

            4. What is the capital of Tamil Nadu?
            (A) Madurai (B) Coimbatore (C) Chennai (D) Salem

            5. Which is the longest river in India?
            (A) Kaveri (B) Ganga (C) Yamuna (D) Godavari
            TXT,
            <<<'TXT'
            ANSWER KEY
            1 - B    2 - C    3 - A    4 - C    5 - B
            TXT,
        ]);

        $this->assertCount(5, $parsed);
        $this->assertSame('B', $parsed[0]->correctOption);
        $this->assertSame('C', $parsed[1]->correctOption);
        $this->assertSame('A', $parsed[2]->correctOption);
    }

    public function test_a_few_stray_matches_are_not_mistaken_for_an_answer_key(): void
    {
        // "Article 21 - a" reads exactly like a key entry. One or two such
        // coincidences must not silently key the whole paper wrongly.
        $parsed = $this->parser()->parse([<<<'TXT'
        1. Article 21 - a fundamental right - protects which of the following?
        (A) Life and liberty
        (B) Property
        (C) Trade
        (D) Religion
        TXT]);

        $this->assertCount(1, $parsed);
        $this->assertNull($parsed[0]->correctOption);
    }

    public function test_it_keeps_a_question_that_runs_across_a_page_break(): void
    {
        $parsed = $this->parser()->parse([
            "7. Which of the following states shares the longest border with",
            "Tamil Nadu?\n(A) Kerala\n(B) Karnataka\n(C) Andhra Pradesh\n(D) Odisha",
        ]);

        $this->assertCount(1, $parsed);
        $this->assertStringContainsString('longest border with Tamil Nadu', $parsed[0]->stem);
        $this->assertSame('Kerala', $parsed[0]->options['A']);
    }

    public function test_it_skips_blocks_that_are_not_four_option_questions(): void
    {
        $parsed = $this->parser()->parse([<<<'TXT'
        PART A - GENERAL STUDIES

        1. Instructions: answer all questions.

        2. Which metal is liquid at room temperature?
        (A) Mercury
        (B) Iron
        (C) Copper
        (D) Zinc

        3. This question has only three options.
        (A) One
        (B) Two
        (C) Three
        TXT]);

        // Only the real four-option question survives. A three-option block is
        // a misread, and inventing a fourth choice would corrupt the corpus.
        $this->assertCount(1, $parsed);
        $this->assertSame(2, $parsed[0]->number);
        $this->assertSame('Mercury', $parsed[0]->options['A']);
    }

    public function test_a_bilingual_paper_keeps_the_first_language_options(): void
    {
        // TNPSC prints the English option set then repeats it in Tamil. Taking
        // the later match returns the translation as though it were the option.
        $parsed = $this->parser()->parse([<<<'TXT'
        1. Which is the smallest planet?
        (A) Mercury
        (B) Mars
        (C) Venus
        (D) Earth
        அ) புதன்
        ஆ) செவ்வாய்
        இ) வெள்ளி
        ஈ) பூமி
        TXT]);

        $this->assertCount(1, $parsed);
        $this->assertSame('Mercury', $parsed[0]->options['A']);
    }

    public function test_an_empty_paper_yields_nothing_rather_than_failing(): void
    {
        $this->assertSame([], $this->parser()->parse([]));
        $this->assertSame([], $this->parser()->parse(['Cover page. No questions here.']));
    }
}
