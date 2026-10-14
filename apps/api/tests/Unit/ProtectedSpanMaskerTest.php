<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Translation\Glossary;
use App\Domain\Translation\ProtectedSpanMasker;
use App\Domain\Translation\TranslationValidator;
use PHPUnit\Framework\TestCase;

/**
 * The masker is the reason a translated question keeps its numbers. These
 * pin what it must hide, what it must leave for the translator, and that
 * everything hidden comes back exactly once.
 */
final class ProtectedSpanMaskerTest extends TestCase
{
    private ProtectedSpanMasker $masker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->masker = new ProtectedSpanMasker;
    }

    /** @return array<string, string> token → original */
    private function spans(string $text): array
    {
        $this->masker->reset();

        return array_map(static fn (array $e): string => $e['original'], $this->masker->mask($text)['map']);
    }

    public function test_indian_and_western_grouping_decimals_and_percentages_are_single_spans(): void
    {
        $spans = array_values($this->spans('A district of 1,26,000 people grew 12.5% to 1,41,750; the state has 7,21,47,030 and the world 8,000,000,000.'));

        $this->assertSame(['1,26,000', '12.5%', '1,41,750', '7,21,47,030', '8,000,000,000'], $spans);
    }

    public function test_units_travel_with_their_number_but_words_do_not(): void
    {
        $spans = array_values($this->spans('A car at 60 km/h covers 150 km in 2.5 hours at 25 °C; 5 men lift 3 kg; ₹ 1,200 was paid.'));

        // "hours" and "men" are words to translate, not units to protect.
        $this->assertSame(['60 km/h', '150 km', '2.5', '25 °C', '5', '3 kg', '₹ 1,200'], $spans);
    }

    public function test_formulas_and_whole_equations_are_masked_but_acronyms_and_roman_numerals_are_not(): void
    {
        $spans = array_values($this->spans(
            'In photosynthesis 6CO2 + 6H2O -> C6H12O6 + 6O2, and 6CO₂ + 6H₂O → C₆H₁₂O₆ + 6O₂. '
            .'Water is H2O, salt is NaCl and lime is Ca(OH)2. I think TNPSC, NASA and RBI in statement IV are fine, and so is CO.'
        ));

        $this->assertSame([
            '6CO2 + 6H2O -> C6H12O6 + 6O2',
            '6CO₂ + 6H₂O → C₆H₁₂O₆ + 6O₂',
            'H2O', 'NaCl', 'Ca(OH)2',
        ], $spans);
    }

    public function test_dates_years_and_urls_are_masked_and_a_sentence_full_stop_is_kept(): void
    {
        $this->masker->reset();
        $masked = $this->masker->mask('India became free on 15/08/1947, in the 1940s, after the 1857-58 revolt. See https://tnpsc.gov.in/notes.pdf.');

        $this->assertSame(
            ['15/08/1947', '1940', '1857-58', 'https://tnpsc.gov.in/notes.pdf'],
            array_values(array_map(static fn (array $e): string => $e['original'], $masked['map'])),
        );

        // The decade suffix stays outside for the translator; the full stop
        // after the URL stays in the sentence.
        $this->assertStringContainsString('__PH_2__s', $masked['text']);
        $this->assertStringEndsWith('__PH_4__.', $masked['text']);
    }

    public function test_masking_round_trips_exactly(): void
    {
        $text = "Assertion (A): 2H2 + O2 → 2H2O releases 572 kJ.\nReason (R): 1,26,000 molecules at 0.5 atm on 21/06/2024 — see www.example.org.";

        $this->masker->reset();
        $masked = $this->masker->mask($text);

        $this->assertDoesNotMatchRegularExpression('/\d/', preg_replace('/__PH_\d+__/', '', $masked['text']));
        $this->assertSame($text, $this->masker->unmask($masked['text'], $masked['map']));
    }

    public function test_glossary_names_come_back_in_their_fixed_target_form(): void
    {
        $names = [
            ['source' => 'Tamil Nadu', 'target' => 'தமிழ்நாடு'],
            ['source' => 'Tamil', 'target' => 'தமிழ்'],
            ['source' => 'Cauvery', 'target' => 'காவிரி'],
            ['source' => 'Gandhi', 'target' => 'காந்தி'],
        ];

        $masked = $this->masker->maskFields([
            'question_text' => 'Which river, the Cauvery, enters Tamil Nadu where Gandhi spoke in 1919?',
            'A' => 'Cauvery basin',
        ], $names);

        // Longest name wins: "Tamil Nadu" is one span, not "Tamil" + "Nadu".
        $kinds = array_count_values(array_column($masked['map'], 'kind'));
        $this->assertSame(4, $kinds['name']);
        $this->assertSame(1, $kinds['number']);

        // Tokens are unique across fields and remember which field they left.
        $this->assertCount(5, array_unique(array_keys($masked['map'])));
        $this->assertSame('A', $masked['owners'][array_key_last($masked['owners'])]);

        // A pretend Tamil translation that kept every token.
        $parts = preg_split('/(__PH_\d+__)/', $masked['fields']['question_text'], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $translated = implode('', array_map(
            static fn (string $part, int $i): string => $i % 2 === 1 ? $part : (string) preg_replace('/[A-Za-z,?]+/', 'சொல்', $part),
            $parts,
            array_keys($parts),
        ));
        $restored = $this->masker->unmask($translated, $masked['map']);

        $this->assertStringContainsString('காவிரி', $restored);
        $this->assertStringContainsString('தமிழ்நாடு', $restored);
        $this->assertStringContainsString('காந்தி', $restored);
        $this->assertStringContainsString('1919', $restored);
        $this->assertStringNotContainsString('Cauvery', $restored);
    }

    public function test_tamil_names_keep_their_case_endings_outside_the_token(): void
    {
        $this->masker->reset();
        $masked = $this->masker->mask('சென்னையில் காவிரி பாய்கிறது.', [
            ['source' => 'சென்னை', 'target' => 'Chennai'],
            ['source' => 'காவிரி', 'target' => 'Cauvery'],
        ]);

        $this->assertSame('__PH_1__யில் __PH_2__ பாய்கிறது.', $masked['text']);
        $this->assertSame('Cauvery flows in Chennai.', $this->masker->unmask('__PH_2__ flows in __PH_1__.', $masked['map']));
    }

    public function test_every_placeholder_must_survive_exactly_once(): void
    {
        $expected = ['__PH_1__', '__PH_2__', '__PH_3__'];

        $this->assertSame(
            ['missing' => [], 'duplicated' => [], 'unexpected' => []],
            $this->masker->placeholderProblems('__PH_2__ then __PH_1__ and __PH_3__', $expected),
        );

        $problems = $this->masker->placeholderProblems('__PH_1__ __PH_1__ __PH_9__', $expected);

        $this->assertSame(['__PH_2__', '__PH_3__'], $problems['missing']);
        $this->assertSame(['__PH_1__'], $problems['duplicated']);
        $this->assertSame(['__PH_9__'], $problems['unexpected']);
    }

    /** Review finding m2. */
    public function test_a_sign_or_leading_point_stays_with_its_number(): void
    {
        $this->assertSame(['-5 °C', '.5', '−3'], array_values($this->spans('It fell to -5 °C, then .5 more, then −3.')));

        // A minus between two numbers is subtraction, not a sign.
        $this->assertSame(['10', '5'], array_values($this->spans('Find 10-5.')));
    }

    /** Review finding m2. */
    public function test_a_formula_may_carry_a_tamil_case_ending(): void
    {
        $this->masker->reset();

        $this->assertSame('__PH_1__வின் அளவு', $this->masker->mask('CO₂வின் அளவு')['text']);
    }

    /** Review finding m2: CO₂ written back as CO has lost a number. */
    public function test_subscript_digits_count_as_numbers(): void
    {
        $validator = new TranslationValidator($this->masker, new Glossary);
        $tamil = ['question_text' => 'எந்த வாயு CO?', 'options' => ['A' => 'ஒன்று', 'B' => 'இரண்டு', 'C' => 'மூன்று', 'D' => 'நான்கு'], 'explanation' => ''];

        $verdict = $validator->validate(
            source: ['question_text' => 'Which gas is CO₂?', 'options' => ['A' => 'one', 'B' => 'two', 'C' => 'three', 'D' => 'four'], 'explanation' => ''],
            translated: $tamil,
            maskedTranslated: ['question_text' => $tamil['question_text']] + $tamil['options'] + ['explanation' => ''],
            owners: [],
            terms: [],
            verification: ['back_translation' => 'Which gas is CO?', 'meaning_score' => 0.95, 'model_choice' => 'A', 'confidence' => 0.9],
            correctOption: 'A',
            targetScript: 'Taml',
        );

        $this->assertContains('V1_STRUCTURE', $verdict['report']['failures']);
        $this->assertContains('The numbers differ from the source.', $verdict['report']['checks']['V1']['problems']);
    }

    public function test_markdown_mangled_tokens_are_repaired_not_lost(): void
    {
        $this->assertSame('__PH_3__ and __PH_12__', $this->masker->normalise('**PH_3** and __PH_12 __'));
        $this->assertSame([], $this->masker->placeholderProblems('**PH_1**', ['__PH_1__'])['missing']);
        $this->assertTrue($this->masker->hasLeftoverTokens('value __PH_4__ stayed'));
        $this->assertFalse($this->masker->hasLeftoverTokens('pH 7 is neutral'));
    }
}
