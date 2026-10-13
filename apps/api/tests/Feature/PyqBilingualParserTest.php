<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pyq\PyqParser;
use Tests\TestCase;

/**
 * TNPSC prints each question twice, in Tamil and in English, inside one
 * numbered block. Both versions are kept when each reads as a whole question;
 * when one half is incomplete the block is read as one language, exactly as
 * before, rather than patched together.
 */
final class PyqBilingualParserTest extends TestCase
{
    private function parser(): PyqParser
    {
        return app(PyqParser::class);
    }

    public function test_a_block_printed_in_both_languages_returns_both_versions(): void
    {
        $parsed = $this->parser()->parse([<<<'TXT'
        1. Which is the smallest planet?
        சூரியக் குடும்பத்தின் மிகச்சிறிய கோள் எது?
        (A) Mercury
        (B) Mars
        (C) Venus
        (D) Earth
        (A) புதன்
        (B) செவ்வாய்
        (C) வெள்ளி
        (D) பூமி
        TXT]);

        $this->assertCount(1, $parsed);
        $question = $parsed[0];

        $this->assertTrue($question->isBilingual());
        $this->assertSame('en', $question->language, 'The language printed first is the master.');
        $this->assertSame(['en', 'ta'], $question->languages());
        $this->assertSame('Which is the smallest planet?', $question->stem);
        $this->assertSame('Earth', $question->options['D']);
        $this->assertSame('சூரியக் குடும்பத்தின் மிகச்சிறிய கோள் எது?', $question->translations['ta']['stem']);
        $this->assertSame('புதன்', $question->translations['ta']['options']['A']);
        $this->assertSame('பூமி', $question->translations['ta']['options']['D']);
    }

    public function test_tamil_first_with_tamil_labels_makes_tamil_the_master(): void
    {
        $parsed = $this->parser()->parse([<<<'TXT'
        2. 'திருக்குறள்' நூலை எழுதியவர் யார்?
        அ) கம்பர் ஆ) திருவள்ளுவர் இ) இளங்கோ ஈ) ஔவையார்
        Who wrote 'Thirukkural'?
        (A) Kambar (B) Thiruvalluvar (C) Ilango (D) Avvaiyar
        TXT]);

        $this->assertCount(1, $parsed);
        $this->assertSame('ta', $parsed[0]->language);
        $this->assertSame('திருவள்ளுவர்', $parsed[0]->options['B']);
        $this->assertSame("Who wrote 'Thirukkural'?", $parsed[0]->translations['en']['stem']);
        $this->assertSame('Thiruvalluvar', $parsed[0]->translations['en']['options']['B']);
    }

    public function test_an_option_set_printed_once_serves_both_stems(): void
    {
        // Years have no language. Printing them twice would be pointless, so
        // the paper does not, and both versions share the one set.
        $parsed = $this->parser()->parse([<<<'TXT'
        3. In which year did India become a Republic?
        இந்தியா எந்த ஆண்டு குடியரசானது?
        (A) 1947 (B) 1950 (C) 1952 (D) 1962
        TXT]);

        $this->assertCount(1, $parsed);
        $this->assertTrue($parsed[0]->isBilingual());
        $this->assertSame('1950', $parsed[0]->translations['en']['options']['B']);
        $this->assertSame('1950', $parsed[0]->translations['ta']['options']['B']);
    }

    public function test_a_tamil_option_set_without_a_tamil_stem_is_not_a_translation(): void
    {
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
        $this->assertFalse($parsed[0]->isBilingual(), 'Half a translation is skipped, not patched.');
        // The second set ends option D instead of being swallowed by it.
        $this->assertSame('Earth', $parsed[0]->options['D']);
    }

    public function test_a_both_a_and_b_option_is_not_cut_short(): void
    {
        $parsed = $this->parser()->parse([<<<'TXT'
        6. Which of these are primary colours of light?
        (A) Red (B) Green (C) Yellow (D) Both (A) and (B)
        TXT]);

        $this->assertSame('Both (A) and (B)', $parsed[0]->options['D']);
    }

    public function test_a_tamil_question_with_an_english_term_stays_one_tamil_question(): void
    {
        $parsed = $this->parser()->parse([<<<'TXT'
        4. ஒளிச்சேர்க்கை (Photosynthesis) நடைபெறும் இடம் எது?
        (A) இலை
        (B) வேர்
        (C) தண்டு
        (D) பூ
        5. மரபுப் பண்புகளைக் கடத்துவது எது?
        (A) DNA
        (B) ATP
        (C) ADP
        (D) NADP
        TXT]);

        $this->assertCount(2, $parsed);
        $this->assertSame('ta', $parsed[0]->language);
        $this->assertFalse($parsed[0]->isBilingual());
        // English-only options under a Tamil stem are still that question's options.
        $this->assertSame('ta', $parsed[1]->language);
        $this->assertSame('DNA', $parsed[1]->options['A']);
        $this->assertFalse($parsed[1]->isBilingual());
    }

    public function test_question_stems_opening_with_a_are_not_read_as_an_answer_key(): void
    {
        // "1. A train…" looks exactly like the key entry "1 - A". Five such
        // stems once keyed a paper that never printed a key.
        $text = '';

        foreach (['train leaves', 'man walks', 'cat sits', 'dog barks', 'bird sings'] as $i => $stem) {
            $n = $i + 1;
            $text .= "{$n}. A {$stem} somewhere today.\n(A) one{$n} (B) two{$n} (C) three{$n} (D) four{$n}\n";
        }

        $parsed = $this->parser()->parse([$text]);

        $this->assertCount(5, $parsed);

        foreach ($parsed as $question) {
            $this->assertNull($question->correctOption);
        }
    }

    public function test_a_typed_key_reads_every_common_shape(): void
    {
        $this->assertSame(
            [1 => 'A', 2 => 'C', 3 => 'B', 4 => 'D', 5 => 'D', 10 => 'A'],
            $this->parser()->readAnswerKey("1-A 2-c, 3 (B)\n4D 5 ஈ 10:a"),
        );
        $this->assertSame([], $this->parser()->readAnswerKey('no answers here'));
    }
}
