<?php

declare(strict_types=1);

namespace App\Domain\Pyq;

use App\Domain\Ai\AiGateway;
use App\Models\CanonicalTopic;
use App\Models\PreviousYearQuestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Places a past question onto the canonical topic tree.
 *
 * Until this runs, a parsed paper contributes nothing: frequency analysis
 * groups by topic, and a question with no topic is invisible to it. So this is
 * the step that turns a pile of parsed rows into evidence.
 *
 * Cheapest first, as everywhere else in the platform:
 *
 *   1. Lexical — the topic's own name or one of its aliases appears in the
 *      stem. Free, exact, and settles the easy third outright.
 *   2. Model — constrained classification for the rest.
 *
 * The safety property that matters is that the model cannot invent a topic.
 * Its answer is checked against the real slug list and discarded if it is not
 * there, so a hallucinated category can never enter the taxonomy — it just
 * leaves the question unmapped, which is the honest outcome.
 */
final class TopicMapper
{
    /**
     * Below this, the mapping is recorded but flagged for review rather than
     * trusted. A wrong topic is worse than no topic: it silently skews every
     * frequency score derived from it.
     */
    private const REVIEW_THRESHOLD = 0.70;

    /** Below this the answer is discarded entirely. */
    private const FLOOR = 0.45;

    /** Questions per model call. Large enough to amortise the topic list. */
    private const BATCH = 20;

    public function __construct(private readonly AiGateway $ai) {}

    /**
     * @return array{mapped: int, lexical: int, model: int, unmapped: int, review: int}
     */
    public function mapPaper(string $paperId): array
    {
        $topics = CanonicalTopic::query()->get(['id', 'slug', 'name', 'domain', 'aliases']);

        if ($topics->isEmpty()) {
            return ['mapped' => 0, 'lexical' => 0, 'model' => 0, 'unmapped' => 0, 'review' => 0];
        }

        $questions = PreviousYearQuestion::query()
            ->where('paper_id', $paperId)
            ->whereNull('canonical_topic_id')
            ->get();

        $stats = ['mapped' => 0, 'lexical' => 0, 'model' => 0, 'unmapped' => 0, 'review' => 0];
        $remaining = [];

        foreach ($questions as $question) {
            $match = $this->lexicalMatch($question->question_text, $topics);

            if ($match !== null) {
                $this->assign($question, $match['topic_id'], $match['confidence'], 'lexical');
                $stats['mapped']++;
                $stats['lexical']++;

                continue;
            }

            $remaining[] = $question;
        }

        foreach (array_chunk($remaining, self::BATCH) as $chunk) {
            $this->mapWithModel($chunk, $topics, $stats);
        }

        $stats['review'] = PreviousYearQuestion::query()
            ->where('paper_id', $paperId)
            ->whereNotNull('canonical_topic_id')
            ->where('mapping_confidence', '<', self::REVIEW_THRESHOLD)
            ->count();

        $stats['unmapped'] = PreviousYearQuestion::query()
            ->where('paper_id', $paperId)
            ->whereNull('canonical_topic_id')
            ->count();

        return $stats;
    }

    /**
     * A topic name or alias appearing verbatim in the stem is about as certain
     * as classification gets, and costs nothing.
     *
     * Only distinctive phrases count: matching on a single common word would
     * put every question mentioning "science" under general science.
     *
     * @param  Collection<int, CanonicalTopic>  $topics
     * @return array{topic_id: string, confidence: float}|null
     */
    private function lexicalMatch(string $stem, Collection $topics): ?array
    {
        $haystack = ' '.$this->normalise($stem).' ';
        $best = null;

        foreach ($topics as $topic) {
            foreach ($this->phrasesFor($topic) as $phrase) {
                $needle = $this->normalise($phrase);

                // Two words minimum, or a long single word. "Indian Polity"
                // is evidence; "history" on its own is not.
                $words = count(explode(' ', $needle));

                if ($words < 2 && mb_strlen($needle) < 9) {
                    continue;
                }

                if (! str_contains($haystack, ' '.$needle.' ')) {
                    continue;
                }

                $score = $words >= 2 ? 0.92 : 0.80;

                if ($best === null || $score > $best['confidence']) {
                    $best = ['topic_id' => $topic->getKey(), 'confidence' => $score];
                }
            }
        }

        return $best;
    }

    /**
     * @param  list<PreviousYearQuestion>  $questions
     * @param  Collection<int, CanonicalTopic>  $topics
     * @param  array<string, int>  $stats
     */
    private function mapWithModel(array $questions, Collection $topics, array &$stats): void
    {
        $allowed = $topics->keyBy('slug');

        try {
            $result = $this->ai->structured('pyq_topic_classification', [
                'topics' => $topics
                    ->map(fn (CanonicalTopic $t): string => "{$t->slug} — {$t->name} ({$t->domain})")
                    ->implode("\n"),
                'items' => collect($questions)
                    ->map(fn (PreviousYearQuestion $q): string => "{$q->getKey()} :: {$q->question_text}")
                    ->implode("\n"),
            ]);
        } catch (Throwable $e) {
            // A classifier outage must not silently mark a paper as mapped.
            // The questions stay unmapped and can be retried.
            Log::error('pyq.mapping_failed', ['error' => $e->getMessage()]);

            return;
        }

        $byId = collect($questions)->keyBy(fn (PreviousYearQuestion $q): string => $q->getKey());

        foreach ($result->get('mappings', []) as $row) {
            $question = $byId->get((string) ($row['question_id'] ?? ''));
            $slug = (string) ($row['topic_slug'] ?? '');
            $confidence = (float) ($row['confidence'] ?? 0);

            if ($question === null) {
                continue;
            }

            // The model is not permitted to introduce a topic. An unrecognised
            // slug means it invented one, and the question stays unmapped
            // rather than the taxonomy growing a category nobody defined.
            $topic = $allowed->get($slug);

            if ($topic === null || $confidence < self::FLOOR) {
                continue;
            }

            $this->assign($question, $topic->getKey(), $confidence, 'ai');
            $stats['mapped']++;
            $stats['model']++;
        }
    }

    private function assign(
        PreviousYearQuestion $question,
        string $topicId,
        float $confidence,
        string $source,
    ): void {
        $question->forceFill([
            'canonical_topic_id' => $topicId,
            'mapping_confidence' => round($confidence, 3),
            // Recorded so a reviewer can see how a question came to be filed,
            // and so a later sweep can revisit only the model's guesses.
            'mapping_source' => $confidence < self::REVIEW_THRESHOLD ? "{$source}_review" : $source,
        ])->save();
    }

    /** @return list<string> */
    private function phrasesFor(CanonicalTopic $topic): array
    {
        $aliases = is_array($topic->aliases) ? $topic->aliases : [];

        // Topic names here are compound ("Physics: Force, Motion and Energy"),
        // so each clause is a candidate phrase in its own right.
        $parts = preg_split('/[:,()\/]|\band\b/u', $topic->name) ?: [];

        return array_values(array_filter(array_map(
            static fn ($p): string => trim((string) $p),
            [...$parts, ...$aliases, $topic->name],
        )));
    }

    private function normalise(string $text): string
    {
        $lower = mb_strtolower($text);

        return trim((string) preg_replace(
            '/\s+/u',
            ' ',
            (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $lower),
        ));
    }
}
