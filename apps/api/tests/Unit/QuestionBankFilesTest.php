<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The TNPSC Group 4 question files in the repository: every question is
 * well formed, and no question is in the bank twice — not word for word, not
 * with only the wrong options changed, and not with a word or two reworded.
 */
final class QuestionBankFilesTest extends TestCase
{
    private const DIR = __DIR__.'/../../database/content/questions/tnpsc-g4';

    /** @var list<array{at: string, q: array<string, mixed>}>|null */
    private static ?array $questions = null;

    /** @return list<array{at: string, q: array<string, mixed>}> */
    private function questions(): array
    {
        if (self::$questions === null) {
            self::$questions = [];
            foreach (glob(self::DIR.'/*.json') ?: [] as $file) {
                $data = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
                foreach ($data['questions'] as $i => $q) {
                    self::$questions[] = ['at' => basename($file).'#'.$i, 'q' => $q];
                }
            }
        }

        return self::$questions;
    }

    /** Letters (with Tamil vowel signs), digits and nothing else, in lower case. */
    private function plain(string $text): string
    {
        return (string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '', mb_strtolower($text));
    }

    /** @param  array<string, mixed>  $q */
    private function answer(array $q, string $language): string
    {
        $option = trim($q[$language]['options'][array_search($q['answer'], ['A', 'B', 'C', 'D'], true)]);

        // Answers made only of signs ("( - )", "{ }") are compared as written.
        return $this->plain($option) !== '' ? $this->plain($option) : $option;
    }

    public function test_the_bank_files_are_there(): void
    {
        $this->assertGreaterThan(50000, count($this->questions()));
    }

    public function test_every_question_is_well_formed(): void
    {
        $problems = [];

        foreach ($this->questions() as ['at' => $at, 'q' => $q]) {
            if (! in_array($q['answer'] ?? null, ['A', 'B', 'C', 'D'], true)) {
                $problems[] = "$at: answer is not A–D";
            }
            if (! isset($q['en']) && ! isset($q['ta'])) {
                $problems[] = "$at: no English or Tamil text";
            }
            foreach (['en', 'ta'] as $language) {
                if (! isset($q[$language])) {
                    continue;
                }
                $options = $q[$language]['options'] ?? [];
                if (trim((string) ($q[$language]['q'] ?? '')) === '') {
                    $problems[] = "$at/$language: empty question";
                }
                if (count($options) !== 4 || in_array('', array_map(static fn ($o): string => trim((string) $o), $options), true)) {
                    $problems[] = "$at/$language: needs four filled options";
                } elseif (count(array_unique(array_map('trim', $options))) !== 4) {
                    $problems[] = "$at/$language: two options are the same";
                }
                if (trim((string) ($q[$language]['explanation'] ?? '')) === '') {
                    $problems[] = "$at/$language: no explanation";
                }
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_no_question_is_asked_twice(): void
    {
        $seen = [];
        $repeats = [];

        foreach ($this->questions() as ['at' => $at, 'q' => $q]) {
            foreach (['en', 'ta'] as $language) {
                if (! isset($q[$language])) {
                    continue;
                }
                $key = $language."\0".$this->plain($q[$language]['q'])."\0".$this->answer($q, $language);
                if (isset($seen[$key])) {
                    $repeats[] = "$at repeats {$seen[$key]}";
                }
                $seen[$key] ??= $at;
            }
        }

        $this->assertSame([], array_values(array_unique($repeats)));
    }

    public function test_no_question_is_a_near_copy_of_another(): void
    {
        // Same answer, same numbers, and at least 90% the same wording.
        $groups = [];

        foreach ($this->questions() as ['at' => $at, 'q' => $q]) {
            $language = isset($q['en']) ? 'en' : 'ta';
            $stem = $this->plain($q[$language]['q']);
            preg_match_all('/\p{N}+/u', $q[$language]['q'], $numbers);
            $groups[$language."\0".$this->answer($q, $language)."\0".implode(' ', $numbers[0])][] = [
                'at' => $at,
                'stem' => $stem,
                'chars' => array_count_values(mb_str_split($stem)),
                'length' => mb_strlen($stem),
            ];
        }

        $copies = [];

        foreach ($groups as $group) {
            $count = count($group);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    [$a, $b] = [$group[$i], $group[$j]];
                    $total = $a['length'] + $b['length'];
                    if ($a['stem'] === $b['stem'] || $total === 0 || abs($a['length'] - $b['length']) > 0.2 * max($a['length'], $b['length'])) {
                        continue;
                    }
                    // Cheap upper bound first: shared characters, order ignored.
                    $shared = 0;
                    foreach ($a['chars'] as $char => $n) {
                        $shared += min($n, $b['chars'][$char] ?? 0);
                    }
                    if (2 * $shared / $total < 0.9) {
                        continue;
                    }
                    similar_text($a['stem'], $b['stem'], $percent);
                    if ($percent >= 90) {
                        $copies[] = "{$b['at']} is nearly {$a['at']}";
                    }
                }
            }
        }

        $this->assertSame([], $copies);
    }
}
