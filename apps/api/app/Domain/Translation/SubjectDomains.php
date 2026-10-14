<?php

declare(strict_types=1);

namespace App\Domain\Translation;

use App\Models\Question;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which glossary subjects a question belongs to.
 *
 * Read from the question's canonical topic (its name and path) and the exam
 * units that topic sits under — "Physics: Force, Motion and Energy" is
 * physics, "Union and State Executive" is polity. The answer decides which
 * subject-specific glossary entries are enforced: "motion" is இயக்கம் in a
 * physics question and தீர்மானம் in a polity one.
 *
 * A question with no topic, or a topic that names no subject, gets null:
 * unknown, so only entries that are safe anywhere are enforced.
 */
final class SubjectDomains
{
    /** Glossary domain → words in a topic or unit name that indicate it. */
    private const KEYWORDS = [
        'physics' => ['physics', 'force', 'motion', 'energy', 'electric', 'magnet', 'optics', 'light', 'sound', 'heat', 'scientific'],
        'chemistry' => ['chemistry', 'chemical', 'elements', 'compounds', 'acids', 'matter', 'metals', 'scientific'],
        'biology' => ['biology', 'botany', 'zoology', 'physiology', 'plant', 'animal', 'human body', 'health', 'disease', 'nutrition', 'life science', 'scientific'],
        'environment' => ['environment', 'ecology', 'pollution', 'biodiversity', 'disaster'],
        'polity' => ['polity', 'constitution', 'fundamental rights', 'executive', 'parliament', 'judiciary', 'self government', 'panchayat', 'election', 'governance'],
        'geography' => ['geography', 'physical features', 'climate', 'natural resources', 'agriculture', 'transport', 'disaster', 'earth', 'solar system'],
        'history' => ['history', 'civilization', 'civilisation', 'empires', 'national movement', 'freedom struggle', 'culture', 'heritage', 'socio-political', 'archaeolog', 'sangam', 'dynasty'],
        'economy' => ['economy', 'economic', 'planning', 'welfare', 'social justice', 'budget', 'banking', 'development administration'],
        'aptitude' => ['aptitude', 'mental ability', 'simplification', 'percentage', 'ratio', 'proportion', 'average', 'time, work', 'time and work', 'distance', 'reasoning', 'series', 'interest', 'arithmetic', 'mathematics', 'geometry', 'mensuration'],
    ];

    /**
     * @param  Collection<int, Question>  $questions
     * @return array<string, list<string>|null>  question id → domains, or null when unknown
     */
    public function forQuestions(Collection $questions): array
    {
        $topicsByQuestion = [];

        foreach ($questions as $question) {
            $topicsByQuestion[$question->getKey()] = array_filter([$question->canonical_topic_id]);
        }

        $mapped = DB::table('question_topic_mappings as m')
            ->leftJoin('exam_topics as t', 't.id', '=', 'm.exam_topic_id')
            ->whereIn('m.question_id', array_keys($topicsByQuestion))
            ->selectRaw('m.question_id, coalesce(m.canonical_topic_id, t.canonical_topic_id) as canonical_topic_id')
            ->get();

        foreach ($mapped as $row) {
            if ($row->canonical_topic_id !== null) {
                $topicsByQuestion[$row->question_id][] = $row->canonical_topic_id;
            }
        }

        $topicIds = array_values(array_unique(array_merge([], ...array_values($topicsByQuestion))));
        $texts = [];

        if ($topicIds !== []) {
            $rows = DB::table('canonical_topics as c')
                ->leftJoin('exam_topics as t', 't.canonical_topic_id', '=', 'c.id')
                ->leftJoin('exam_units as u', 'u.id', '=', 't.exam_unit_id')
                ->whereIn('c.id', $topicIds)
                ->select('c.id', 'c.name', 'c.path', 'c.domain', 'u.name as unit')
                ->get();

            foreach ($rows as $row) {
                $texts[$row->id] = ($texts[$row->id] ?? '').' '.implode(' ', array_filter([
                    $row->name, str_replace(['_', '.'], ' ', (string) $row->path), $row->domain, $row->unit,
                ]));
            }
        }

        $out = [];

        foreach ($topicsByQuestion as $questionId => $ids) {
            $haystack = mb_strtolower(implode(' ', array_map(static fn (string $id): string => $texts[$id] ?? '', $ids)));
            $out[$questionId] = $this->domainsIn($haystack);
        }

        return $out;
    }

    /** @return list<string>|null */
    public function domainsIn(string $text): ?array
    {
        $domains = [];

        foreach (self::KEYWORDS as $domain => $words) {
            foreach ($words as $word) {
                if (preg_match('/(?<![\p{L}])'.preg_quote($word, '/').'/iu', $text) === 1) {
                    $domains[] = $domain;

                    break;
                }
            }
        }

        return $domains === [] ? null : $domains;
    }
}
