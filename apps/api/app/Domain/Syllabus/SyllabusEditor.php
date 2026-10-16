<?php

declare(strict_types=1);

namespace App\Domain\Syllabus;

use App\Domain\Audit\AuditRecorder;
use App\Models\CanonicalTopic;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\ExamTopic;
use App\Models\ExamUnit;
use App\Models\Syllabus;
use App\Models\SyllabusVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Drafts, edits and publishes syllabus versions.
 *
 * A published version is immutable: plans and tests point at its id, so a
 * student preparing under last year's syllabus keeps a coherent plan when
 * this year's lands. Change therefore always goes through a draft — copied
 * from the version in force — which is edited, compared, and then published,
 * superseding its predecessor in one transaction.
 *
 * Canonical topics are the join point to the question bank. Copying a version
 * keeps every topic's canonical id, so existing questions stay reachable; a
 * topic added by name is matched to an existing canonical topic before a new
 * one is created.
 */
final class SyllabusEditor
{
    /** Published unit weights must sum to 1 within this tolerance. */
    private const WEIGHT_TOLERANCE = 0.02;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly SyllabusTextParser $parser,
    ) {}

    public function createDraft(Exam $exam, ?string $fromVersionId = null): SyllabusVersion
    {
        $existing = $this->versionsOf($exam)->firstWhere('status', 'draft');

        if ($existing !== null) {
            throw new ConflictHttpException(
                "Version {$existing->version_number} is already a draft. Publish or discard it first."
            );
        }

        return DB::transaction(function () use ($exam, $fromVersionId): SyllabusVersion {
            $syllabus = Syllabus::query()->firstOrCreate(
                ['exam_id' => $exam->getKey()],
                ['name' => "{$exam->name} Syllabus"],
            );

            $from = $fromVersionId !== null
                ? $this->versionsOf($exam)->firstWhere('id', $fromVersionId)
                : $this->versionsOf($exam)->firstWhere('status', 'active');

            $examVersion = $exam->activeVersion()
                ?? throw new UnprocessableEntityHttpException('The exam has no active version to attach a syllabus to.');

            $draft = SyllabusVersion::query()->create([
                'syllabus_id' => $syllabus->getKey(),
                'exam_version_id' => $examVersion->getKey(),
                'version_number' => ((int) SyllabusVersion::query()->where('syllabus_id', $syllabus->getKey())->max('version_number')) + 1,
                'effective_from' => now()->toDateString(),
                'status' => 'draft',
            ]);

            if ($from !== null) {
                $this->copyTree($from, $draft);
            }

            $this->audit->record('syllabus.draft_created', $draft, [
                'exam_id' => $exam->getKey(),
                'copied_from' => $from?->getKey(),
            ]);

            return $draft;
        });
    }

    /** Drafts are archived, not deleted: nothing in the platform is. */
    public function discard(SyllabusVersion $draft): void
    {
        $this->assertDraft($draft);
        $draft->forceFill(['status' => 'archived'])->save();
        $this->audit->record('syllabus.draft_discarded', $draft);
    }

    /** @param  array{name: string, exam_subject_id: string, weight?: float, code?: string|null}  $data */
    public function addUnit(SyllabusVersion $draft, array $data): ExamUnit
    {
        $this->assertDraft($draft);

        return ExamUnit::query()->create([
            'syllabus_version_id' => $draft->getKey(),
            'exam_subject_id' => $data['exam_subject_id'],
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'weight' => $data['weight'] ?? 0,
            'sequence' => ((int) ExamUnit::query()->where('syllabus_version_id', $draft->getKey())->max('sequence')) + 1,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function updateUnit(ExamUnit $unit, array $data): ExamUnit
    {
        $this->assertDraft($unit->syllabusVersion);
        $unit->fill(collect($data)->only(['name', 'code', 'weight', 'sequence', 'exam_subject_id'])->all())->save();

        return $unit;
    }

    public function removeUnit(ExamUnit $unit): void
    {
        $this->assertDraft($unit->syllabusVersion);
        $unit->delete();
    }

    /**
     * A topic of the unit, or — with parent_id — a sub-topic of one of its
     * topics. A topic with sub-topics is no longer a leaf, so plans schedule
     * its sub-topics instead of it; a topic-wise test on it still takes them
     * all, through the path.
     *
     * @param  array{name: string, parent_id?: string|null, canonical_topic_id?: string|null, weight?: float, estimated_minutes?: int}  $data
     */
    public function addTopic(ExamUnit $unit, array $data): ExamTopic
    {
        $draft = $unit->syllabusVersion;
        $this->assertDraft($draft);

        $parent = isset($data['parent_id'])
            ? ExamTopic::query()->where('exam_unit_id', $unit->getKey())->findOrFail($data['parent_id'])
            : null;

        $canonicalId = $data['canonical_topic_id'] ?? $this->canonicalFor($data['name'], $unit)->getKey();
        $sequence = ((int) ExamTopic::query()->where('exam_unit_id', $unit->getKey())->max('sequence')) + 1;

        return DB::transaction(function () use ($draft, $unit, $parent, $canonicalId, $sequence, $data): ExamTopic {
            $parent?->forceFill(['is_leaf' => false])->save();

            return ExamTopic::query()->create([
                'syllabus_version_id' => $draft->getKey(),
                'exam_unit_id' => $unit->getKey(),
                'parent_id' => $parent?->getKey(),
                'canonical_topic_id' => $canonicalId,
                'name' => $data['name'],
                'path' => $parent === null
                    ? $this->path($unit->name, $data['name'], $sequence)
                    : $parent->path.'.'.$this->label($data['name'], 'sub_'.$sequence),
                'depth' => $parent === null ? 1 : (int) $parent->depth + 1,
                'is_leaf' => true,
                'weight' => $data['weight'] ?? 0,
                'estimated_minutes' => $data['estimated_minutes'] ?? 45,
                'sequence' => $sequence,
            ]);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updateTopic(ExamTopic $topic, array $data): ExamTopic
    {
        $this->assertDraft($topic->syllabusVersion);
        $topic->fill(collect($data)->only(['name', 'canonical_topic_id', 'weight', 'estimated_minutes', 'sequence'])->all())->save();

        return $topic;
    }

    public function removeTopic(ExamTopic $topic): void
    {
        $this->assertDraft($topic->syllabusVersion);

        DB::transaction(function () use ($topic): void {
            $parentId = $topic->parent_id;

            // Its own sub-topics go with it (the foreign key cascades).
            $topic->delete();

            // A parent left with no sub-topics is a leaf again, so plans
            // schedule it once more.
            if ($parentId !== null && ! ExamTopic::query()->where('parent_id', $parentId)->exists()) {
                ExamTopic::query()->whereKey($parentId)->update(['is_leaf' => true]);
            }
        });
    }

    /**
     * Imports parsed text into the draft under one subject. Unit weights come
     * from question counts in the headings when every unit has one; otherwise
     * they are left equal for the operator to set.
     *
     * @return array{units: int, topics: int, weighted_by_counts: bool}
     */
    public function importText(SyllabusVersion $draft, ExamSubject $subject, string $text, bool $replace): array
    {
        $this->assertDraft($draft);
        $parsed = $this->parser->parse($text);

        if ($parsed === []) {
            throw new UnprocessableEntityHttpException(
                'No units were recognised. Each unit should start on its own line, e.g. "UNIT I : GENERAL SCIENCE".'
            );
        }

        return DB::transaction(function () use ($draft, $subject, $parsed, $replace): array {
            if ($replace) {
                ExamUnit::query()
                    ->where('syllabus_version_id', $draft->getKey())
                    ->where('exam_subject_id', $subject->getKey())
                    ->delete();
            }

            $counts = array_column($parsed, 'questions');
            $byCounts = ! in_array(null, $counts, true) && array_sum($counts) > 0;
            $total = $byCounts ? array_sum($counts) : count($parsed);
            $topicCount = 0;

            foreach ($parsed as $row) {
                $unit = $this->addUnit($draft, [
                    'name' => $row['name'],
                    'exam_subject_id' => $subject->getKey(),
                    // A share of this subject; publish() asks for the whole
                    // syllabus to sum to 1, so multi-subject exams scale here.
                    'weight' => round(((float) $subject->weight ?: 1.0) * ($byCounts ? $row['questions'] : 1) / $total, 4),
                ]);

                $each = $row['topics'] === [] ? 0 : round(1 / count($row['topics']), 4);

                foreach ($row['topics'] as $name) {
                    $this->addTopic($unit, ['name' => $name, 'weight' => $each]);
                    $topicCount++;
                }
            }

            $this->audit->record('syllabus.imported', $draft, [
                'subject' => $subject->name,
                'units' => count($parsed),
                'topics' => $topicCount,
                'replace' => $replace,
            ]);

            return ['units' => count($parsed), 'topics' => $topicCount, 'weighted_by_counts' => $byCounts];
        });
    }

    /**
     * What changed from $base to $target, matching units by name (ignoring
     * their "Unit IV —" numbering) and topics by canonical topic.
     *
     * @return array<string, mixed>
     */
    public function diff(SyllabusVersion $base, SyllabusVersion $target): array
    {
        $a = $this->snapshot($base);
        $b = $this->snapshot($target);

        $unitsAdded = array_values(array_diff_key($b, $a));
        $unitsRemoved = array_values(array_diff_key($a, $b));
        $weightChanges = [];
        $topicsAdded = [];
        $topicsRemoved = [];

        foreach (array_intersect_key($b, $a) as $key => $unit) {
            $before = $a[$key];

            if (abs($before['weight'] - $unit['weight']) >= 0.0001) {
                $weightChanges[] = ['unit' => $unit['name'], 'from' => $before['weight'], 'to' => $unit['weight']];
            }

            foreach (array_diff_key($unit['topics'], $before['topics']) as $name) {
                $topicsAdded[] = ['unit' => $unit['name'], 'topic' => $name];
            }

            foreach (array_diff_key($before['topics'], $unit['topics']) as $name) {
                $topicsRemoved[] = ['unit' => $unit['name'], 'topic' => $name];
            }
        }

        return [
            'base_version' => $base->version_number,
            'target_version' => $target->version_number,
            'units_added' => array_map(fn ($u) => ['unit' => $u['name'], 'weight' => $u['weight'], 'topics' => array_values($u['topics'])], $unitsAdded),
            'units_removed' => array_map(fn ($u) => ['unit' => $u['name'], 'weight' => $u['weight'], 'topics' => array_values($u['topics'])], $unitsRemoved),
            'weight_changes' => $weightChanges,
            'topics_added' => $topicsAdded,
            'topics_removed' => $topicsRemoved,
            'unchanged' => $unitsAdded === [] && $unitsRemoved === [] && $weightChanges === [] && $topicsAdded === [] && $topicsRemoved === [],
        ];
    }

    /**
     * Makes the draft the syllabus in force. Refuses a draft that would give
     * the selector or the planner nothing sound to work with.
     */
    public function publish(SyllabusVersion $draft, ?string $userId): SyllabusVersion
    {
        $this->assertDraft($draft);

        $units = ExamUnit::query()->where('syllabus_version_id', $draft->getKey())->withCount('topics')->get();
        $problems = [];

        if ($units->isEmpty()) {
            $problems[] = 'The draft has no units.';
        }

        foreach ($units as $unit) {
            if ($unit->topics_count === 0) {
                $problems[] = "\"{$unit->name}\" has no topics.";
            }
        }

        $sum = round((float) $units->sum('weight'), 4);

        if ($units->isNotEmpty() && abs($sum - 1.0) > self::WEIGHT_TOLERANCE) {
            $problems[] = sprintf('Unit weights add up to %.1f%%, not 100%%.', $sum * 100);
        }

        $unmapped = ExamTopic::query()->where('syllabus_version_id', $draft->getKey())->whereNull('canonical_topic_id')->count();

        if ($unmapped > 0) {
            $problems[] = "{$unmapped} topic(s) are not linked to the question bank.";
        }

        if ($problems !== []) {
            throw new UnprocessableEntityHttpException('Cannot publish yet: '.implode(' ', $problems));
        }

        return DB::transaction(function () use ($draft, $userId): SyllabusVersion {
            $previous = SyllabusVersion::query()
                ->where('syllabus_id', $draft->syllabus_id)
                ->where('status', 'active')
                ->get();

            foreach ($previous as $old) {
                $old->forceFill(['status' => 'superseded', 'effective_to' => now()->toDateString()])->save();
            }

            $draft->forceFill([
                'status' => 'active',
                'published_by' => $userId,
                'published_at' => now(),
                'effective_from' => now()->toDateString(),
            ])->save();

            $this->audit->record('syllabus.published', $draft, [
                'version_number' => $draft->version_number,
                'superseded' => $previous->pluck('version_number')->all(),
                'diff' => $previous->isNotEmpty() ? $this->diffSummary($previous->first(), $draft) : null,
            ]);

            return $draft;
        });
    }

    /** @return Collection<int, SyllabusVersion> */
    public function versionsOf(Exam $exam): Collection
    {
        return SyllabusVersion::query()
            ->whereIn('syllabus_id', Syllabus::query()->where('exam_id', $exam->getKey())->select('id'))
            ->orderByDesc('version_number')
            ->get();
    }

    private function copyTree(SyllabusVersion $from, SyllabusVersion $to): void
    {
        $units = ExamUnit::query()->where('syllabus_version_id', $from->getKey())->orderBy('sequence')->get();

        foreach ($units as $unit) {
            $copy = $unit->replicate(['id', 'created_at', 'updated_at']);
            $copy->syllabus_version_id = $to->getKey();
            $copy->save();

            // Topics keep their canonical ids: that is what keeps the bank's
            // questions reachable through the new version. Sub-topics come
            // too, parents first, each pointed at its parent's copy.
            $topics = ExamTopic::query()->where('exam_unit_id', $unit->getKey())->orderBy('depth')->orderBy('sequence')->get();
            $copied = [];

            foreach ($topics as $topic) {
                $t = $topic->replicate(['id', 'created_at', 'updated_at']);
                $t->syllabus_version_id = $to->getKey();
                $t->exam_unit_id = $copy->getKey();
                $t->parent_id = $topic->parent_id === null ? null : ($copied[$topic->parent_id] ?? null);
                $t->save();
                $copied[$topic->getKey()] = $t->getKey();
            }
        }
    }

    /**
     * An existing canonical topic with the same slug or name, else a new one.
     * Matching first is what lets a re-typed "Indian National Movement" join
     * the questions already filed under it instead of starting from zero.
     */
    private function canonicalFor(string $name, ExamUnit $unit): CanonicalTopic
    {
        $slug = $this->slug($name);

        $existing = CanonicalTopic::query()
            ->where('slug', $slug)
            ->orWhereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $subject = (string) ExamSubject::query()->whereKey($unit->exam_subject_id)->value('name');
        $domain = match (true) {
            str_contains(mb_strtolower($subject), 'aptitude') => 'aptitude',
            str_contains(mb_strtolower($subject), 'tamil'),
            str_contains(mb_strtolower($subject), 'english'),
            str_contains(mb_strtolower($subject), 'language') => 'language',
            default => 'general_studies',
        };

        return CanonicalTopic::query()->create([
            'slug' => $slug,
            'name' => $name,
            'domain' => $domain,
            'path' => $domain.'.'.str_replace('-', '_', $slug),
            'depth' => 2,
        ]);
    }

    /**
     * Slugs must be ASCII for the ltree path. A Tamil-only name transliterates
     * to nothing, so it gets a stable hash-based slug instead.
     */
    private function slug(string $name): string
    {
        $slug = Str::slug($name);

        if ($slug === '' || strlen($slug) < 3) {
            $slug = 'topic-'.substr(hash('sha256', $name), 0, 12);
        }

        return substr($slug, 0, 80);
    }

    private function path(string $unitName, string $topicName, int $sequence): string
    {
        return $this->label($unitName, 'unit').'.'.$this->label($topicName, 'topic_'.$sequence);
    }

    /** One ltree label: the name as a slug, or the fallback for a name with no Latin letters (Tamil). */
    private function label(string $name, string $fallback): string
    {
        $label = substr(str_replace('-', '_', Str::slug($name)), 0, 60);

        return $label !== '' ? $label : $fallback;
    }

    /** @return array<string, array{name: string, weight: float, topics: array<string, string>}> */
    private function snapshot(SyllabusVersion $version): array
    {
        $units = ExamUnit::query()->where('syllabus_version_id', $version->getKey())->get();
        $topics = ExamTopic::query()->where('syllabus_version_id', $version->getKey())->get()->groupBy('exam_unit_id');
        $out = [];

        foreach ($units as $unit) {
            // By subject and by name without its numbering: a renumbered unit
            // is a change, not a removal plus an addition.
            $key = $unit->exam_subject_id.'|'.mb_strtolower(trim((string) preg_replace('/^(unit|part|section)\s+[ivxlcdm\d]+\s*[-–—:.]?\s*/iu', '', $unit->name)));
            $out[$key] = [
                'name' => $unit->name,
                'weight' => round((float) $unit->weight, 4),
                'topics' => ($topics[$unit->getKey()] ?? collect())
                    ->mapWithKeys(fn (ExamTopic $t) => [($t->canonical_topic_id ?? 'name:'.mb_strtolower($t->name)) => $t->name])
                    ->all(),
            ];
        }

        return $out;
    }

    /** @return array<string, int> */
    private function diffSummary(SyllabusVersion $base, SyllabusVersion $target): array
    {
        $diff = $this->diff($base, $target);

        return [
            'units_added' => count($diff['units_added']),
            'units_removed' => count($diff['units_removed']),
            'weight_changes' => count($diff['weight_changes']),
            'topics_added' => count($diff['topics_added']),
            'topics_removed' => count($diff['topics_removed']),
        ];
    }

    private function assertDraft(?SyllabusVersion $version): void
    {
        if ($version === null) {
            throw new RuntimeException('Syllabus version not found.');
        }

        if (! $version->isEditable()) {
            throw new ConflictHttpException(
                "Version {$version->version_number} is {$version->status}; only a draft can change. Create a new draft instead."
            );
        }
    }
}
