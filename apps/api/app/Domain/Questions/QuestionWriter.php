<?php

declare(strict_types=1);

namespace App\Domain\Questions;

use App\Domain\Ingestion\Contracts\EmbeddingProvider;
use App\Domain\Questions\DTOs\QuestionCandidate;
use App\Models\Question;
use App\Models\QuestionExamMapping;
use App\Models\QuestionOption;
use App\Models\QuestionRejection;
use App\Models\QuestionSource;
use App\Models\QuestionTranslation;
use App\Models\QuestionValidation;
use App\Support\Database\VectorSupport;
use Illuminate\Support\Facades\DB;

/**
 * Persists a scored candidate as a question — or as a retained rejection.
 *
 * Rejected questions are never deleted. They tune the overgeneration factor,
 * train reviewers, and expose bad OCR pages (a page producing many UNSUPPORTED
 * rejections is usually a page with broken text, not a bad prompt).
 */
final class QuestionWriter
{
    public function __construct(private readonly EmbeddingProvider $embeddings) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function write(QuestionCandidate $candidate, string $status, array $meta): Question
    {
        return DB::transaction(function () use ($candidate, $status, $meta): Question {
            $question = Question::query()->create([
                'organization_id' => $meta['organization_id'] ?? null,
                // Taken from the candidate, not hardcoded: the bank is meant to
                // hold every shape a real paper uses.
                'format' => $candidate->format,
                'origin' => 'ai_generated',
                // Rejected candidates are stored as REJECTED, not dropped.
                'status' => $status,
                'master_language_id' => $meta['language_id'],
                'difficulty' => $candidate->difficulty,
                'bloom_level' => $candidate->bloomLevel,
                'canonical_topic_id' => $meta['canonical_topic_id'] ?? null,
                'quality_score' => $candidate->qualityScore,
                'ai_confidence' => $candidate->confidence,
                'generated_by_job_id' => $meta['ai_job_id'] ?? null,
                'created_by' => $meta['user_id'] ?? null,
            ]);

            $this->writeOptions($question, $candidate);
            $this->writeMasterTranslation($question, $candidate, $meta);
            $this->writeCitations($question, $candidate);
            $this->writeExamMappings($question, $meta);
            $this->writeChecks($question, $candidate);
            $this->writeRejections($question, $candidate);

            if ($status !== 'REJECTED') {
                $this->writeEmbedding($question, $candidate, $meta);
            }

            return $question;
        });
    }

    private function writeOptions(Question $question, QuestionCandidate $candidate): void
    {
        $position = 1;

        foreach ($candidate->options as $key => $text) {
            QuestionOption::query()->create([
                'question_id' => $question->getKey(),
                'option_key' => $key,
                'position' => $position++,
                // The answer lives here and only here — language-neutral, so
                // no translation can ever contradict it.
                'is_correct' => $key === $candidate->correctOption,
            ]);
        }
    }

    /** @param  array<string, mixed>  $meta */
    private function writeMasterTranslation(Question $question, QuestionCandidate $candidate, array $meta): void
    {
        QuestionTranslation::query()->create([
            'question_id' => $question->getKey(),
            'language_id' => $meta['language_id'],
            // The master rendering is a translation row like any other. That
            // symmetry is what makes the sixth language cost nothing.
            'is_master' => true,
            'source_language_id' => $meta['language_id'],
            'question_text' => $candidate->questionText,
            'option_a' => $candidate->options['A'] ?? null,
            'option_b' => $candidate->options['B'] ?? null,
            'option_c' => $candidate->options['C'] ?? null,
            'option_d' => $candidate->options['D'] ?? null,
            'explanation' => $candidate->explanation,
            'translation_status' => 'HUMAN_APPROVED',
            'quality_score' => $candidate->qualityScore,
        ]);
    }

    /**
     * Links the question to the exams it can serve.
     *
     * Without this the question is unreachable: the selection engine filters
     * on exam mappings, so an approved question with none can never appear on
     * a paper. One row per exam — the question itself is never duplicated.
     *
     * @param  array<string, mixed>  $meta
     */
    private function writeExamMappings(Question $question, array $meta): void
    {
        foreach (array_filter((array) ($meta['exam_ids'] ?? [])) as $examId) {
            QuestionExamMapping::query()->updateOrCreate(
                [
                    'question_id' => $question->getKey(),
                    'exam_id' => $examId,
                    'syllabus_version_id' => $meta['syllabus_version_id'] ?? null,
                ],
                [
                    'suitability' => 1.0,
                    'difficulty_for_exam' => $question->difficulty,
                    'mapped_by' => 'ai',
                    'is_active' => true,
                ],
            );
        }
    }

    private function writeCitations(Question $question, QuestionCandidate $candidate): void
    {
        foreach ($candidate->citedChunks as $index => $chunk) {
            QuestionSource::query()->create([
                'question_id' => $question->getKey(),
                'source_id' => $chunk->source_id,
                'book_id' => $chunk->book_id,
                'book_lesson_id' => $chunk->book_lesson_id,
                'source_chunk_id' => $chunk->getKey(),
                // Snapshotted, not read through the relation at render time:
                // re-chunking an edited book deletes these chunks and the FK
                // nulls out, which would silently turn a printed citation into
                // "Unknown source".
                'breadcrumb' => $chunk->breadcrumb,
                'page_start' => $chunk->page_start,
                'page_end' => $chunk->page_end,
                'supporting_quote' => $index === 0 ? $candidate->supportingQuote : null,
                'is_primary' => $index === 0,
            ]);
        }
    }

    private function writeChecks(Question $question, QuestionCandidate $candidate): void
    {
        foreach ($candidate->checks as $code => $check) {
            QuestionValidation::query()->create([
                'question_id' => $question->getKey(),
                'check_code' => $code,
                'passed' => $check['passed'],
                'score' => $check['score'],
                'detail' => $check['detail'],
            ]);
        }
    }

    private function writeRejections(Question $question, QuestionCandidate $candidate): void
    {
        foreach ($candidate->rejections as $rejection) {
            QuestionRejection::query()->create([
                'question_id' => $question->getKey(),
                'stage' => $rejection['stage'],
                'code' => $rejection['code'],
                'detail' => $rejection['detail'],
            ]);
        }
    }

    /** @param  array<string, mixed>  $meta */
    private function writeEmbedding(Question $question, QuestionCandidate $candidate, array $meta): void
    {
        $vector = $candidate->embedding
            ?? $this->embeddings->embed([$candidate->embeddingText()])[0];

        DB::insert(
            'INSERT INTO question_embeddings (question_id, language_id, embedding_model, embedding)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (question_id, language_id, embedding_model) DO NOTHING',
            [
                $question->getKey(),
                $meta['language_id'],
                $this->embeddings->modelKey(),
                VectorSupport::toBinding($vector),
            ],
        );
    }
}
