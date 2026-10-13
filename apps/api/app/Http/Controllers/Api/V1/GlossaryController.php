<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\GlossaryTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The board's terminology, editable by the person who knows it.
 *
 * Nothing is deleted: removing a term deactivates it, so a translation that
 * was checked against it can still be explained later. Entries are unique per
 * language pair, source term (ignoring case) and domain — the same word may
 * legitimately have a different board term in physics and in polity.
 */
final class GlossaryController extends Controller
{
    private const KINDS = ['term', 'name'];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeEditor($request);

        $data = $request->validate([
            'source_language_id' => ['nullable', 'uuid'],
            'target_language_id' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:200'],
            'kind' => ['nullable', 'in:term,name'],
            'domain' => ['nullable', 'string', 'max:50'],
            'include_inactive' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $q = trim((string) ($data['q'] ?? ''));
        $like = '%'.addcslashes($q, '%_\\').'%';

        $query = GlossaryTerm::query()
            ->when($data['source_language_id'] ?? null, fn ($w, $id) => $w->where('source_language_id', $id))
            ->when($data['target_language_id'] ?? null, fn ($w, $id) => $w->where('target_language_id', $id))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s
                ->where('source_term', 'ilike', $like)
                ->orWhere('target_term', 'ilike', $like)))
            ->when($data['kind'] ?? null, fn ($w, $kind) => $w->where('kind', $kind))
            ->when($data['domain'] ?? null, fn ($w, $domain) => $w->where('domain', $domain))
            ->when(! $request->boolean('include_inactive'), fn ($w) => $w->where('is_active', true));

        $total = (clone $query)->count();

        $rows = $query
            ->orderByDesc('is_active')
            ->orderByRaw('lower(source_term)')
            ->offset((int) ($data['offset'] ?? 0))
            ->limit((int) ($data['limit'] ?? 100))
            ->get();

        return response()->json([
            'data' => $rows->map($this->shape(...))->all(),
            'meta' => [
                'total' => $total,
                'domains' => GlossaryTerm::query()->whereNotNull('domain')->distinct()->orderBy('domain')->pluck('domain')->all(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeEditor($request);

        $data = $request->validate([
            'source_language_id' => ['required', 'uuid', 'exists:languages,id'],
            'target_language_id' => ['required', 'uuid', 'exists:languages,id', 'different:source_language_id'],
            'source_term' => ['required', 'string', 'max:300'],
            'target_term' => ['required', 'string', 'max:300'],
            'kind' => ['nullable', 'in:term,name'],
            'domain' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $data = $this->clean($data);
        $existing = $this->find($data['source_language_id'], $data['target_language_id'], $data['source_term'], $data['domain']);

        if ($existing !== null && $existing->is_active) {
            abort(409, "'{$data['source_term']}' is already in the glossary for this language pair"
                .($data['domain'] !== null ? " ({$data['domain']})" : '').'. Edit that entry instead.');
        }

        if ($existing !== null) {
            // Re-adding a deactivated term brings it back rather than
            // failing on a duplicate the user cannot see.
            $before = $existing->getAttributes();
            $existing->forceFill([
                'target_term' => $data['target_term'],
                'kind' => $data['kind'],
                'notes' => $data['notes'] ?? $existing->notes,
                'is_active' => true,
            ])->save();

            $this->audit->recordChange('glossary.reactivated', $existing, $before);

            return response()->json(['data' => $this->shape($existing->fresh())]);
        }

        $term = GlossaryTerm::query()->create($data + ['is_active' => true, 'created_by' => $request->user()->getKey()]);

        $this->audit->record('glossary.created', $term, [
            'source_term' => $term->source_term,
            'target_term' => $term->target_term,
            'kind' => $term->kind,
            'domain' => $term->domain,
        ]);

        return response()->json(['data' => $this->shape($term->fresh())], 201);
    }

    public function update(Request $request, string $termId): JsonResponse
    {
        $this->authorizeEditor($request);

        $term = GlossaryTerm::query()->findOrFail($termId);

        $data = $request->validate([
            'source_term' => ['sometimes', 'string', 'max:300'],
            'target_term' => ['sometimes', 'string', 'max:300'],
            'kind' => ['sometimes', 'in:term,name'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:50'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        foreach (['source_term', 'target_term'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = trim((string) $data[$field]);
                abort_if($data[$field] === '', 422, 'Terms cannot be blank.');
            }
        }

        if (array_key_exists('domain', $data)) {
            $data['domain'] = $this->domain($data['domain']);
        }

        $clash = $this->find(
            $term->source_language_id,
            $term->target_language_id,
            $data['source_term'] ?? $term->source_term,
            array_key_exists('domain', $data) ? $data['domain'] : $term->domain,
        );

        abort_if($clash !== null && $clash->getKey() !== $term->getKey(), 409,
            'Another entry already uses that source term in this domain. Edit that one instead.');

        $before = $term->getAttributes();
        $term->forceFill($data)->save();

        $this->audit->recordChange('glossary.updated', $term, $before);

        return response()->json(['data' => $this->shape($term->fresh())]);
    }

    /** Deactivates. A term is never deleted; see the class comment. */
    public function destroy(Request $request, string $termId): JsonResponse
    {
        $this->authorizeEditor($request);

        $term = GlossaryTerm::query()->findOrFail($termId);

        if ($term->is_active) {
            $term->forceFill(['is_active' => false])->save();

            $this->audit->record('glossary.deactivated', $term, [
                'source_term' => $term->source_term,
                'target_term' => $term->target_term,
            ]);
        }

        return response()->json(['data' => $this->shape($term->fresh())]);
    }

    /**
     * Bulk load from pasted CSV: "source,target[,kind[,domain]]" per line.
     * Quoted fields may contain commas. Existing entries are updated in place
     * rather than duplicated, so re-importing a corrected list is safe.
     */
    public function import(Request $request): JsonResponse
    {
        $this->authorizeEditor($request);

        $data = $request->validate([
            'source_language_id' => ['required', 'uuid', 'exists:languages,id'],
            'target_language_id' => ['required', 'uuid', 'exists:languages,id', 'different:source_language_id'],
            'csv' => ['required', 'string', 'max:500000'],
        ]);

        $lines = preg_split('/\r\n|\n|\r/', $data['csv']) ?: [];
        abort_if(count($lines) > 5000, 422, 'Import at most 5,000 lines at a time.');

        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $errors = [];
        $userId = $request->user()->getKey();

        DB::transaction(function () use ($lines, $data, $userId, &$counts, &$errors): void {
            foreach ($lines as $index => $line) {
                $number = $index + 1;
                $line = trim((string) $line);

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                $cols = array_map(static fn ($c): string => trim((string) $c), str_getcsv($line, ',', '"', '\\'));

                if ($this->isHeader($cols)) {
                    continue;
                }

                $source = $cols[0] ?? '';
                $target = $cols[1] ?? '';
                $kind = mb_strtolower(($cols[2] ?? '') !== '' ? $cols[2] : 'term');
                $domain = $this->domain($cols[3] ?? null);

                if ($source === '' || $target === '') {
                    $counts['skipped']++;
                    $errors[] = "Line {$number}: needs both a source and a target term.";

                    continue;
                }

                if (! in_array($kind, self::KINDS, true)) {
                    $counts['skipped']++;
                    $errors[] = "Line {$number}: kind must be 'term' or 'name', not '{$kind}'.";

                    continue;
                }

                if (mb_strlen($source) > 300 || mb_strlen($target) > 300) {
                    $counts['skipped']++;
                    $errors[] = "Line {$number}: a term is longer than 300 characters.";

                    continue;
                }

                $existing = $this->find($data['source_language_id'], $data['target_language_id'], $source, $domain);

                if ($existing === null) {
                    GlossaryTerm::query()->create([
                        'source_language_id' => $data['source_language_id'],
                        'target_language_id' => $data['target_language_id'],
                        'source_term' => $source,
                        'target_term' => $target,
                        'kind' => $kind,
                        'domain' => $domain,
                        'is_active' => true,
                        'created_by' => $userId,
                    ]);
                    $counts['created']++;

                    continue;
                }

                if ($existing->target_term === $target && $existing->kind === $kind && $existing->is_active) {
                    $counts['skipped']++;

                    continue;
                }

                $existing->forceFill(['target_term' => $target, 'kind' => $kind, 'is_active' => true])->save();
                $counts['updated']++;
            }

            $this->audit->record('glossary.imported', null, $counts + [
                'source_language_id' => $data['source_language_id'],
                'target_language_id' => $data['target_language_id'],
                'lines' => count($lines),
            ]);
        });

        return response()->json(['data' => $counts + ['errors' => array_slice($errors, 0, 50)]]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        $data['source_term'] = trim((string) $data['source_term']);
        $data['target_term'] = trim((string) $data['target_term']);
        $data['kind'] = $data['kind'] ?? 'term';
        $data['domain'] = $this->domain($data['domain'] ?? null);

        abort_if($data['source_term'] === '' || $data['target_term'] === '', 422, 'Terms cannot be blank.');

        return $data;
    }

    private function domain(mixed $domain): ?string
    {
        $domain = mb_strtolower(trim((string) $domain));

        return $domain === '' ? null : $domain;
    }

    /** Matches the unique index: pair, lower(source_term), coalesce(domain, ''). */
    private function find(string $sourceLanguageId, string $targetLanguageId, string $sourceTerm, ?string $domain): ?GlossaryTerm
    {
        return GlossaryTerm::query()
            ->where('source_language_id', $sourceLanguageId)
            ->where('target_language_id', $targetLanguageId)
            ->whereRaw('lower(source_term) = lower(?)', [$sourceTerm])
            ->whereRaw("coalesce(domain, '') = ?", [$domain ?? ''])
            ->first();
    }

    /** @param  list<string>  $cols */
    private function isHeader(array $cols): bool
    {
        return in_array(mb_strtolower($cols[0] ?? ''), ['source', 'source_term', 'english', 'en'], true)
            && in_array(mb_strtolower($cols[1] ?? ''), ['target', 'target_term', 'tamil', 'ta'], true);
    }

    /** @return array<string, mixed> */
    private function shape(GlossaryTerm $term): array
    {
        return [
            'id' => $term->getKey(),
            'source_language_id' => $term->source_language_id,
            'target_language_id' => $term->target_language_id,
            'kind' => $term->kind,
            'domain' => $term->domain,
            'source_term' => $term->source_term,
            'target_term' => $term->target_term,
            'notes' => $term->notes,
            'is_active' => (bool) $term->is_active,
            'updated_at' => $term->updated_at?->toIso8601String(),
        ];
    }

    private function authorizeEditor(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user !== null && ($user->isPlatformAdmin() || $user->can('questions.edit')),
            403,
            'You do not have permission to edit the glossary.',
        );
    }
}
