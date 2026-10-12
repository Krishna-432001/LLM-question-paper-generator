<?php

declare(strict_types=1);

namespace App\Domain\Rag;

use App\Models\SourceChunk;
use Illuminate\Support\Collection;

/**
 * Turns retrieved chunks into a labelled SOURCE_MATERIAL block.
 *
 * The [S1]..[Sn] labels are the mechanism that makes fabrication cheap to
 * detect: the schema requires each generated question to cite labels, and a
 * citation that does not resolve to a chunk we actually sent is rejected
 * before any model is asked to judge it.
 */
final class ContextPacker
{
    /**
     * @param  Collection<int, SourceChunk>  $chunks
     * @return array{block: string, map: array<string, SourceChunk>, tokens: int}
     */
    public function pack(Collection $chunks, ?int $maxTokens = null): array
    {
        $maxTokens ??= (int) config('embeddings.retrieval.max_context_tokens', 6000);

        $lines = [];
        $map = [];
        $tokens = 0;
        $label = 1;

        foreach ($chunks as $chunk) {
            $entry = sprintf(
                "[S%d] (source:%s pp.%s-%s)\n%s",
                $label,
                $chunk->getKey(),
                $chunk->page_start ?? '?',
                $chunk->page_end ?? '?',
                trim($chunk->text),
            );

            $entryTokens = (int) ceil(mb_strlen($entry) / 4);

            if ($tokens + $entryTokens > $maxTokens && $lines !== []) {
                break;   // a hard cap keeps cost and instruction-following predictable
            }

            $lines[] = $entry;
            $map['S'.$label] = $chunk;
            $tokens += $entryTokens;
            $label++;
        }

        return [
            'block' => implode("\n\n", $lines),
            'map' => $map,
            'tokens' => $tokens,
        ];
    }
}
