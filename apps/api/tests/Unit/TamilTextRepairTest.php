<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ingestion\TextNormalizer;
use App\Infrastructure\Ocr\TesseractOcrEngine;
use PHPUnit\Framework\TestCase;

/**
 * The ways Tamil comes out of a PDF or an OCR engine damaged, and the repairs
 * that must leave correct Tamil exactly as it was.
 */
final class TamilTextRepairTest extends TestCase
{
    public function test_vowel_signs_stored_in_drawing_order_go_back_after_their_letter(): void
    {
        $normalizer = new TextNormalizer;

        $this->assertSame(
            'இந்தியாவின் தேசிய விலங்கு எது? சென்னை தலைநகரம் வைகை',
            $normalizer->normalizePage('இந்தியாவின் ேதசிய விலங்கு எது? ெசன்ைன தைலநகரம் ைவைக'),
        );
    }

    public function test_the_two_part_vowels_are_rebuilt_as_one_letter(): void
    {
        // ே + க + ா in drawing order is கோ, stored as the single U+0B95 U+0BCB.
        $this->assertSame("\u{0B95}\u{0BCB}\u{0BA4}\u{0BBE}\u{0BB5}\u{0BB0}\u{0BBF}", (new TextNormalizer)->normalizePage('ேகாதாவரி'));
        $this->assertSame('கொடி', (new TextNormalizer)->normalizePage('ெகாடி'));
    }

    public function test_correct_tamil_is_left_exactly_as_it_was(): void
    {
        $normalizer = new TextNormalizer;
        $text = 'தமிழ்நாட்டின் தலைநகரம் சென்னை. கோதாவரி, வைகை, கெண்டை மீன், தேசிய விலங்கு புலி.';

        $this->assertFalse($normalizer->tamilInVisualOrder($text));
        $this->assertSame($text, $normalizer->normalizePage($text));
    }

    public function test_a_table_of_vowel_signs_does_not_turn_a_page_around(): void
    {
        // A grammar page shows the signs on their own; none opens a word.
        $text = "உயிர்மெய் குறியீடுகள்: ா, ி, ீ, ு, ூ, ெ, ே, ை\nகெ கே கை என்று எழுதுக.";

        $this->assertFalse((new TextNormalizer)->tamilInVisualOrder($text));
        $this->assertSame($text, (new TextNormalizer)->normalizePage($text));
    }

    public function test_the_joiner_ocr_writes_after_every_pulli_is_dropped(): void
    {
        $this->assertSame(
            'இந்தியாவின் தேசிய விலங்கு யார்?',
            (new TextNormalizer)->normalizePage("இந்தியாவின்\u{200C} தேசிய விலங்கு யார்\u{200C}?"),
        );
    }

    public function test_a_lost_option_line_shows_as_uneven_labels(): void
    {
        $whole = "1. Which?\n(A) Lion\n(B) Tiger\n(C) Elephant\n(D) Peacock\n(A) சிங்கம் (B) புலி (C) யானை (D) மயில்";
        $lost = "1. Which?\n(A) Lion\nElephant\nPeacock";

        $this->assertSame([2, 2, 2, 2], TesseractOcrEngine::optionLabels($whole));
        $this->assertFalse(TesseractOcrEngine::optionLabelsUneven($whole));
        $this->assertTrue(TesseractOcrEngine::optionLabelsUneven($lost));

        // Tamil labels count in the same slots; a page with no options is not uneven.
        $this->assertSame([1, 1, 1, 1], TesseractOcrEngine::optionLabels("அ) ஒன்று ஆ) இரண்டு இ) மூன்று ஈ) நான்கு"));
        $this->assertFalse(TesseractOcrEngine::optionLabelsUneven('A page of prose with no options at all.'));
    }
}
