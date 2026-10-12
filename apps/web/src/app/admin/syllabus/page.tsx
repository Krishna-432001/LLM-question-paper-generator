"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useMemo, useState } from "react";
import {
  ApiError,
  examAdmin,
  getToken,
  syllabusAdmin,
  type ExamDetail,
  type ExamRow,
  type SyllabusDiff,
  type SyllabusTree,
} from "@/lib/api";

/**
 * The syllabus editor.
 *
 * A published syllabus never changes in place — plans and papers point at its
 * id. Change goes through a draft copied from the live version: edit it,
 * import a notification's text into it, compare it with what is live, then
 * publish, which supersedes the old version in one step.
 */

const PANEL = "rounded-xl border border-slate-800 bg-slate-900";
const FIELD =
  "rounded-md border border-slate-700 bg-slate-950 px-2.5 py-1.5 text-sm text-slate-100 outline-none focus:border-sky-500";
const BUTTON =
  "rounded-md bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500 disabled:cursor-not-allowed disabled:opacity-50";
const GHOST = "rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-300 hover:bg-slate-800";

function message(e: unknown): string {
  if (e instanceof ApiError) {
    const first = e.errors ? Object.values(e.errors)[0]?.[0] : undefined;
    return first ?? e.detail ?? e.message;
  }
  return e instanceof Error ? e.message : "Something went wrong.";
}

export default function SyllabusPage() {
  const router = useRouter();
  const [exams, setExams] = useState<ExamRow[]>([]);
  const [examId, setExamId] = useState("");
  const [exam, setExam] = useState<ExamDetail | null>(null);
  const [tree, setTree] = useState<SyllabusTree | null>(null);
  const [diff, setDiff] = useState<SyllabusDiff | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [note, setNote] = useState<string | null>(null);

  const load = useCallback(async (id: string, versionId?: string) => {
    const [detail, syllabus] = await Promise.all([examAdmin.show(id), examAdmin.syllabus(id, versionId)]);
    setExam(detail.data);
    setTree(syllabus.data);
    setDiff(null);
  }, []);

  useEffect(() => {
    if (!getToken()) {
      router.replace("/login");
      return;
    }

    (async () => {
      try {
        const { data } = await examAdmin.list();
        setExams(data);
        // Read once on mount instead of useSearchParams, so the page needs no
        // Suspense boundary.
        const fromQuery = new URLSearchParams(window.location.search).get("exam");
        const first = data.find((e) => e.id === fromQuery)?.id ?? data[0]?.id ?? "";
        setExamId(first);
        if (first) await load(first);
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) router.replace("/login");
        else setError(message(e));
      }
    })();
  }, [router, load]);

  const selected = tree?.versions.find((v) => v.id === tree.selected_version_id) ?? null;
  const draft = tree?.versions.find((v) => v.status === "draft") ?? null;
  const editable = selected?.status === "draft";
  const subjects = useMemo(
    () => exam?.active_version?.papers.flatMap((p) => p.subjects) ?? [],
    [exam],
  );
  const total = tree?.units.reduce((sum, u) => sum + u.weight, 0) ?? 0;

  const run = async (action: () => Promise<unknown>, done?: string) => {
    setError(null);
    setNote(null);
    try {
      await action();
      if (done) setNote(done);
    } catch (e) {
      setError(message(e));
    }
  };

  const reload = () => load(examId, selected?.id);

  return (
    <>
      <header className="mb-4 flex flex-wrap items-baseline gap-x-6 gap-y-2">
        <h1 className="text-lg font-semibold text-slate-100">Syllabus</h1>
        <p className="text-xs text-slate-500">Draft, compare and publish syllabus versions</p>
        <select
          className={`ml-auto ${FIELD}`}
          value={examId}
          onChange={async (e) => {
            setExamId(e.target.value);
            await run(() => load(e.target.value));
          }}
        >
          {exams.map((e) => (
            <option key={e.id} value={e.id}>
              {e.name}
            </option>
          ))}
        </select>
      </header>

      {error && (
        <p className="mb-4 rounded-lg border border-red-900 bg-red-950/60 px-4 py-3 text-sm text-red-300">{error}</p>
      )}
      {note && (
        <p className="mb-4 rounded-lg border border-emerald-900 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-300">
          {note}
        </p>
      )}

      {tree && (
        <section className={`${PANEL} mb-4 flex flex-wrap items-center gap-2 px-4 py-3`}>
          {tree.versions.map((v) => (
            <button
              key={v.id}
              onClick={() => run(() => load(examId, v.id))}
              className={`rounded-md border px-2.5 py-1 text-xs ${
                v.id === tree.selected_version_id
                  ? "border-sky-500 bg-sky-950/60 text-sky-200"
                  : "border-slate-700 text-slate-400 hover:bg-slate-800"
              }`}
            >
              v{v.version_number} · {v.status}
            </button>
          ))}

          <span className="ml-auto flex flex-wrap gap-2">
            {!draft && (
              <button
                className={BUTTON}
                onClick={() =>
                  run(async () => {
                    const { data } = await syllabusAdmin.createDraft(examId);
                    await load(examId, data.id);
                  }, "Draft created as a copy of the live syllabus.")
                }
              >
                New draft
              </button>
            )}
            {editable && selected && (
              <>
                <button
                  className={GHOST}
                  onClick={() => run(async () => setDiff((await syllabusAdmin.diff(selected.id)).data))}
                >
                  Compare with live
                </button>
                <button
                  className={GHOST}
                  onClick={() =>
                    confirm("Discard this draft? It is archived, not deleted.") &&
                    run(async () => {
                      await syllabusAdmin.discard(selected.id);
                      await load(examId);
                    }, "Draft discarded.")
                  }
                >
                  Discard
                </button>
                <button
                  className={BUTTON}
                  onClick={() =>
                    confirm("Publish this draft? It replaces the live syllabus for every plan and paper made from now on.") &&
                    run(async () => {
                      await syllabusAdmin.publish(selected.id);
                      await load(examId);
                    }, "Published. This is now the syllabus in force.")
                  }
                >
                  Publish
                </button>
              </>
            )}
          </span>
        </section>
      )}

      {diff && <DiffView diff={diff} />}

      {editable && selected && subjects.length > 0 && (
        <ImportPanel
          subjects={subjects}
          onImport={(subjectId, text, replace) =>
            run(async () => {
              const { data } = await syllabusAdmin.importText(selected.id, {
                exam_subject_id: subjectId,
                text,
                replace,
              });
              await reload();
              setNote(
                `Imported ${data.units} units and ${data.topics} topics` +
                  (data.weighted_by_counts ? ", weighted by their question counts." : ". Set the unit weights below."),
              );
            })
          }
        />
      )}

      {tree && (
        <section className={PANEL}>
          <div className="flex items-center gap-3 border-b border-slate-800 px-4 py-2.5">
            <h2 className="text-sm font-semibold text-slate-100">Units and topics</h2>
            {!editable && <span className="text-xs text-slate-500">Read-only. Start a draft to change it.</span>}
            <span
              className={`ml-auto font-mono text-[11px] ${
                Math.abs(total - 1) > 0.02 ? "text-amber-300" : "text-slate-500"
              }`}
            >
              weights total {(total * 100).toFixed(1)}%
            </span>
          </div>

          <ul className="divide-y divide-slate-800/70">
            {tree.units.map((unit) => (
              <li key={unit.id} className="px-4 py-3">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-[10px] uppercase tracking-wide text-slate-500">{unit.subject}</span>
                  {editable && selected ? (
                    <>
                      <input
                        defaultValue={unit.name}
                        className={`${FIELD} min-w-64 flex-1`}
                        onBlur={(e) =>
                          e.target.value !== unit.name &&
                          run(async () => {
                            await syllabusAdmin.updateUnit(selected.id, unit.id, { name: e.target.value });
                            await reload();
                          })
                        }
                      />
                      <input
                        defaultValue={(unit.weight * 100).toFixed(2)}
                        inputMode="decimal"
                        className={`${FIELD} w-24 text-right`}
                        onBlur={(e) => {
                          const weight = Number(e.target.value) / 100;
                          if (!Number.isNaN(weight) && Math.abs(weight - unit.weight) > 0.00005)
                            void run(async () => {
                              await syllabusAdmin.updateUnit(selected.id, unit.id, { weight });
                              await reload();
                            });
                        }}
                      />
                      <span className="text-xs text-slate-500">%</span>
                      <button
                        className="text-xs text-red-400 hover:text-red-300"
                        onClick={() =>
                          confirm(`Remove “${unit.name}” from this draft?`) &&
                          run(async () => {
                            await syllabusAdmin.removeUnit(selected.id, unit.id);
                            await reload();
                          })
                        }
                      >
                        Remove
                      </button>
                    </>
                  ) : (
                    <>
                      <span className="flex-1 text-sm text-slate-200">{unit.name}</span>
                      <span className="font-mono text-[11px] text-slate-400">{(unit.weight * 100).toFixed(1)}%</span>
                    </>
                  )}
                </div>

                <ul className="mt-2 flex flex-wrap gap-1.5">
                  {unit.topics.map((topic) => (
                    <li
                      key={topic.id}
                      className="flex items-center gap-1 rounded bg-slate-800 px-2 py-0.5 text-xs text-slate-300"
                      title={topic.canonical_slug ?? "not linked to the question bank"}
                    >
                      {topic.name}
                      {!topic.canonical_topic_id && <span className="text-amber-400">•</span>}
                      {editable && selected && (
                        <button
                          className="ml-1 text-slate-500 hover:text-red-300"
                          aria-label={`Remove ${topic.name}`}
                          onClick={() =>
                            run(async () => {
                              await syllabusAdmin.removeTopic(selected.id, topic.id);
                              await reload();
                            })
                          }
                        >
                          ×
                        </button>
                      )}
                    </li>
                  ))}
                  {editable && selected && (
                    <li>
                      <input
                        placeholder="+ add topic, Enter"
                        className="w-48 rounded bg-slate-950 px-2 py-0.5 text-xs text-slate-200 outline-none ring-1 ring-slate-700 focus:ring-sky-500"
                        onKeyDown={(e) => {
                          const value = e.currentTarget.value.trim();
                          if (e.key === "Enter" && value) {
                            const input = e.currentTarget;
                            void run(async () => {
                              await syllabusAdmin.addTopic(selected.id, unit.id, { name: value });
                              input.value = "";
                              await reload();
                            });
                          }
                        }}
                      />
                    </li>
                  )}
                </ul>
              </li>
            ))}
            {tree.units.length === 0 && (
              <li className="px-4 py-8 text-center text-sm text-slate-500">No units in this version.</li>
            )}
          </ul>

          {editable && selected && subjects.length > 0 && (
            <AddUnit
              subjects={subjects}
              onAdd={(subjectId, name, weight) =>
                run(async () => {
                  await syllabusAdmin.addUnit(selected.id, { exam_subject_id: subjectId, name, weight });
                  await reload();
                })
              }
            />
          )}
        </section>
      )}
    </>
  );
}

function AddUnit({
  subjects,
  onAdd,
}: {
  subjects: Array<{ id: string; name: string }>;
  onAdd: (subjectId: string, name: string, weight: number) => void;
}) {
  const [subjectId, setSubjectId] = useState(subjects[0]?.id ?? "");
  const [name, setName] = useState("");
  const [weight, setWeight] = useState("0");

  return (
    <div className="flex flex-wrap items-center gap-2 border-t border-slate-800 px-4 py-3">
      <select className={FIELD} value={subjectId} onChange={(e) => setSubjectId(e.target.value)}>
        {subjects.map((s) => (
          <option key={s.id} value={s.id}>
            {s.name}
          </option>
        ))}
      </select>
      <input className={`${FIELD} flex-1`} placeholder="New unit name" value={name} onChange={(e) => setName(e.target.value)} />
      <input className={`${FIELD} w-24 text-right`} inputMode="decimal" value={weight} onChange={(e) => setWeight(e.target.value)} />
      <span className="text-xs text-slate-500">%</span>
      <button
        className={GHOST}
        disabled={!name.trim()}
        onClick={() => {
          onAdd(subjectId, name.trim(), (Number(weight) || 0) / 100);
          setName("");
        }}
      >
        Add unit
      </button>
    </div>
  );
}

function ImportPanel({
  subjects,
  onImport,
}: {
  subjects: Array<{ id: string; name: string }>;
  onImport: (subjectId: string, text: string, replace: boolean) => void;
}) {
  const [open, setOpen] = useState(false);
  const [subjectId, setSubjectId] = useState(subjects[0]?.id ?? "");
  const [text, setText] = useState("");
  const [replace, setReplace] = useState(true);

  if (!open) {
    return (
      <button className={`${GHOST} mb-4`} onClick={() => setOpen(true)}>
        Import from notification text…
      </button>
    );
  }

  return (
    <section className={`${PANEL} mb-4 p-4`}>
      <h2 className="mb-1 text-sm font-semibold text-slate-100">Import from notification text</h2>
      <p className="mb-3 text-xs text-slate-500">
        Paste the syllabus. Each unit should start on its own line (“UNIT I : GENERAL SCIENCE (5 Questions)”);
        numbered or semicolon-separated items become topics. Review the result before publishing.
      </p>
      <textarea
        className={`${FIELD} h-48 w-full font-mono text-xs`}
        value={text}
        onChange={(e) => setText(e.target.value)}
      />
      <div className="mt-3 flex flex-wrap items-center gap-3">
        <select className={FIELD} value={subjectId} onChange={(e) => setSubjectId(e.target.value)}>
          {subjects.map((s) => (
            <option key={s.id} value={s.id}>
              Into: {s.name}
            </option>
          ))}
        </select>
        <label className="flex items-center gap-2 text-sm text-slate-300">
          <input type="checkbox" checked={replace} onChange={(e) => setReplace(e.target.checked)} />
          Replace this subject&apos;s units
        </label>
        <button className={`ml-auto ${GHOST}`} onClick={() => setOpen(false)}>
          Cancel
        </button>
        <button className={BUTTON} disabled={!text.trim()} onClick={() => onImport(subjectId, text, replace)}>
          Import
        </button>
      </div>
    </section>
  );
}

function DiffView({ diff }: { diff: SyllabusDiff }) {
  if (diff.unchanged) {
    return <p className={`${PANEL} mb-4 px-4 py-3 text-sm text-slate-400`}>No differences from the live version.</p>;
  }

  const pct = (w: number) => `${(w * 100).toFixed(1)}%`;

  return (
    <section className={`${PANEL} mb-4 space-y-2 p-4 text-sm`}>
      <h2 className="font-semibold text-slate-100">
        v{diff.target_version} compared with live v{diff.base_version}
      </h2>
      {diff.units_added.map((u) => (
        <p key={`a-${u.unit}`} className="text-emerald-300">
          + unit {u.unit} ({pct(u.weight)}, {u.topics.length} topics)
        </p>
      ))}
      {diff.units_removed.map((u) => (
        <p key={`r-${u.unit}`} className="text-red-300">
          − unit {u.unit} ({pct(u.weight)})
        </p>
      ))}
      {diff.weight_changes.map((c) => (
        <p key={`w-${c.unit}`} className="text-amber-300">
          ~ {c.unit}: {pct(c.from)} → {pct(c.to)}
        </p>
      ))}
      {diff.topics_added.map((t) => (
        <p key={`ta-${t.unit}-${t.topic}`} className="text-emerald-300/80">
          + {t.topic} <span className="text-slate-500">in {t.unit}</span>
        </p>
      ))}
      {diff.topics_removed.map((t) => (
        <p key={`tr-${t.unit}-${t.topic}`} className="text-red-300/80">
          − {t.topic} <span className="text-slate-500">from {t.unit}</span>
        </p>
      ))}
    </section>
  );
}
