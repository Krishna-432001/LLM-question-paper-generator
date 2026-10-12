"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useCallback, useEffect, useState } from "react";
import {
  ApiError,
  getToken,
  structure,
  topicsApi,
  type CanonicalTopicRow,
  type LessonTopicRow,
  type ChapterNode,
  type LessonNode,
  type PageRow,
  type StructureTree,
} from "@/lib/api";

/**
 * The structure editor and OCR correction screen.
 *
 * Detection is heuristics over a PDF text dump, so on a real textbook it will
 * misplace headings and merge lessons. That is not cosmetic: the page range of
 * a lesson decides which passages a question may be generated from, and its
 * title is printed in the citation. This screen is where a person fixes it.
 *
 * Two things are surfaced deliberately:
 *   - usable chunks per lesson, because a lesson below three cannot be
 *     generated from at all and the admin should see that before wondering
 *     why generation skipped it;
 *   - a standing "rebuild needed" banner, because an edit is only half done
 *     until the chunks derived from it have been rebuilt.
 */
export default function StructurePage() {
  return (
    <Suspense fallback={<p className="p-6 text-sm text-slate-400">Loading…</p>}>
      <StructureEditor />
    </Suspense>
  );
}

function StructureEditor() {
  const router = useRouter();
  const params = useSearchParams();
  const sourceId = params.get("source");

  const [tree, setTree] = useState<StructureTree | null>(null);
  const [pages, setPages] = useState<PageRow[]>([]);
  const [needsReview, setNeedsReview] = useState(0);
  const [tab, setTab] = useState<"structure" | "pages" | "topics">("structure");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [note, setNote] = useState<string | null>(null);
  const [editingPage, setEditingPage] = useState<PageRow | null>(null);
  const [pageText, setPageText] = useState("");

  const fail = useCallback((e: unknown) => {
    setError(
      e instanceof ApiError
        ? [e.detail ?? e.message, ...Object.values(e.errors ?? {}).flat()].join(" ")
        : e instanceof Error
          ? e.message
          : "Something went wrong.",
    );
    setBusy(false);
  }, []);

  const load = useCallback(async () => {
    if (!sourceId) return;

    try {
      const [t, p] = await Promise.all([
        structure.show(sourceId),
        structure.pages(sourceId, false),
      ]);
      setTree(t.data);
      setPages(p.data);
      setNeedsReview(p.meta.needs_review);
      setError(null);
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) router.replace("/login");
      else fail(e);
    }
  }, [sourceId, router, fail]);

  useEffect(() => {
    if (!getToken()) {
      router.replace("/login");
      return;
    }
    void (async () => {
      await load();
    })();
  }, [router, load]);

  async function saveLesson(lesson: LessonNode, changes: Record<string, unknown>) {
    if (!sourceId) return;
    setBusy(true);
    setNote(null);

    try {
      await structure.updateLesson(sourceId, lesson.id, changes);
      await load();
      setNote("Saved. The chunks built from this book are now out of date — rebuild below.");
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }

  async function saveChapter(chapter: ChapterNode, changes: Record<string, unknown>) {
    if (!sourceId) return;
    setBusy(true);
    setNote(null);

    try {
      await structure.updateChapter(sourceId, chapter.id, changes);
      await load();
      setNote("Saved. Rebuild below to apply it to the passages.");
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }

  async function removeLesson(lesson: LessonNode) {
    if (!sourceId) return;
    if (!confirm(`Delete “${lesson.title}”? Its passages will be rebuilt without it.`)) return;

    setBusy(true);

    try {
      await structure.deleteLesson(sourceId, lesson.id);
      await load();
      setNote("Lesson removed. Rebuild below to apply it.");
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }

  async function savePage() {
    if (!sourceId || !editingPage) return;
    setBusy(true);

    try {
      await structure.correctPage(sourceId, editingPage.id, pageText);
      setEditingPage(null);
      await load();
      setNote("Page corrected. Rebuild below so the passages use the corrected text.");
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }

  async function rebuild() {
    if (!sourceId) return;
    setBusy(true);
    setNote(null);

    try {
      await structure.reprocess(sourceId);
      setNote("Rebuilding passages and embeddings. This takes a few seconds.");

      // Poll the tree rather than the job: what the editor cares about is the
      // usable-chunk counts settling, not the job row.
      setTimeout(() => void load().then(() => setBusy(false)), 6000);
    } catch (e) {
      fail(e);
    }
  }

  if (!sourceId) {
    return (
      <div>
        <p className="rounded-xl border border-dashed border-slate-800 p-10 text-center text-sm text-slate-500">
          No book selected. Open this from the{" "}
          <a href="/admin" className="text-xs text-sky-400 hover:underline">
            admin console
          </a>
          .
        </p>
      </div>
    );
  }

  return (
    <div>
      <header className="mb-4 flex items-baseline justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-100">Check what the machine read</h1>
          <p className="text-xs text-slate-500">
            Fix chapter and lesson boundaries, and correct badly read pages.
          </p>
        </div>
        <a href="/admin" className="text-xs text-sky-400 hover:underline">
          ← Back to console
        </a>
      </header>

      {error && (
        <p className="mb-4 rounded-md border border-red-900 bg-red-950/60 p-3 text-sm text-red-300">
          {error}
        </p>
      )}
      {note && (
        <p className="mb-4 rounded-md border border-amber-600 bg-amber-950/20 p-3 text-sm text-amber-200">
          {note}
        </p>
      )}

      {tree?.needs_reprocess && (
        <div className="mb-4 flex items-center justify-between gap-3 rounded-lg border border-amber-600 bg-amber-950/20 p-4 text-sm text-amber-100">
          <p>
            <b>Rebuild needed.</b> The passages and embeddings still describe the
            structure as it was before your edits. Generation is paused until this runs.
          </p>
          <button
            onClick={rebuild}
            disabled={busy}
            className="shrink-0 rounded-md bg-amber-700 px-4 py-2 text-sm font-medium text-white hover:bg-amber-600 disabled:opacity-40"
          >
            {busy ? "Rebuilding…" : "Rebuild passages"}
          </button>
        </div>
      )}

      <div className="mb-4 flex gap-2 border-b border-slate-800">
        <Tab active={tab === "structure"} onClick={() => setTab("structure")}>
          Structure
        </Tab>
        <Tab active={tab === "pages"} onClick={() => setTab("pages")}>
          Pages {needsReview > 0 && <span className="text-red-400">({needsReview} to check)</span>}
        </Tab>
        <Tab active={tab === "topics"} onClick={() => setTab("topics")}>
          Syllabus topics
        </Tab>
      </div>

      {tab === "topics" && <LessonTopics sourceId={sourceId} />}

      {tab === "structure" && tree && (
        <div className="space-y-4">
          {tree.chapters.length === 0 && (
            <p className="rounded-xl border border-dashed border-slate-800 p-8 text-center text-sm text-slate-500">
              No structure detected. Run ingestion first.
            </p>
          )}

          {tree.chapters.map((chapter) => (
            <section key={chapter.id} className="rounded-xl border border-slate-800 bg-slate-900 p-5">
              <EditableRow
                label="Chapter"
                title={chapter.title}
                number={chapter.number}
                pageStart={chapter.page_start}
                pageEnd={chapter.page_end}
                busy={busy}
                onSave={(changes) => saveChapter(chapter, changes)}
              />

              <ul className="mt-3 space-y-1 border-l border-slate-800 pl-4">
                {chapter.lessons.map((lesson) => (
                  <li key={lesson.id} className="rounded-md border border-slate-800 p-3">
                    <EditableRow
                      label="Lesson"
                      title={lesson.title}
                      number={lesson.number}
                      pageStart={lesson.page_start}
                      pageEnd={lesson.page_end}
                      busy={busy}
                      onSave={(changes) => saveLesson(lesson, changes)}
                      onDelete={() => removeLesson(lesson)}
                    />
                    <p className="mt-2 text-xs">
                      <span
                        className={
                          lesson.usable_chunks < 3
                            ? "font-mono text-[11px] font-semibold text-red-400"
                            : "text-slate-500"
                        }
                      >
                        {lesson.usable_chunks} usable passages
                      </span>
                      <span className="text-xs text-slate-500">
                        {" "}
                        of {lesson.total_chunks}
                        {lesson.usable_chunks < 3 &&
                          " — too few to generate from; widen the page range or merge this lesson"}
                      </span>
                    </p>
                  </li>
                ))}
                {chapter.lessons.length === 0 && (
                  <li className="text-xs text-slate-500">No lessons detected in this chapter.</li>
                )}
              </ul>
            </section>
          ))}
        </div>
      )}

      {tab === "pages" && (
        <div className="space-y-2">
          <p className="text-xs text-slate-500">
            Sorted worst-confidence first. Correcting a page leaves the original
            reading intact, so a bad scan stays diagnosable.
          </p>

          {pages.map((page) => (
            <div key={page.id} className="rounded-md border border-slate-800 p-3">
              <div className="flex items-start justify-between gap-2">
                <span className="text-xs text-slate-500">
                  Page {page.page_number} · confidence{" "}
                  {page.ocr_confidence === null ? "—" : page.ocr_confidence.toFixed(2)}
                  {page.needs_review && (
                    <b className="ml-2 text-red-400">needs checking</b>
                  )}
                </span>
                <button
                  onClick={() => {
                    setEditingPage(page);
                    setPageText(page.text ?? "");
                  }}
                  className="rounded-md border border-slate-700 px-3 py-1 text-xs text-slate-200 hover:border-slate-600"
                >
                  Correct
                </button>
              </div>
              <p className="mt-1 line-clamp-2 text-xs text-slate-400">
                {page.text}
              </p>
            </div>
          ))}

          {pages.length === 0 && (
            <p className="rounded-xl border border-dashed border-slate-800 p-8 text-center text-sm text-slate-500">
              No pages extracted yet.
            </p>
          )}
        </div>
      )}

      {editingPage && (
        <div className="fixed inset-0 z-10 flex items-center justify-center bg-black/50 p-4">
          <div className="max-h-[85vh] w-full max-w-3xl overflow-y-auto rounded-xl border border-slate-800 bg-slate-900 p-5">
            <h2 className="mb-3 text-sm font-semibold text-slate-100">
              Page {editingPage.page_number}
            </h2>
            <textarea
              value={pageText}
              onChange={(e) => setPageText(e.target.value)}
              rows={18}
              className="w-full rounded-md border border-slate-700 bg-slate-950 p-3 font-mono text-xs text-slate-100 outline-none focus:border-sky-500"
            />
            <div className="mt-3 flex justify-end gap-2">
              <button onClick={() => setEditingPage(null)} className="rounded-md border border-slate-700 px-4 py-2 text-sm text-slate-200 hover:border-slate-600">
                Cancel
              </button>
              <button
                onClick={savePage}
                disabled={busy}
                className="rounded-md bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-600 disabled:opacity-40"
              >
                Save correction
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function Tab({
  active,
  onClick,
  children,
}: {
  active: boolean;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      onClick={onClick}
      className={`px-3 py-2 ${active ? "border-b-2 border-blue-600 font-semibold" : "text-slate-500"}`}
    >
      {children}
    </button>
  );
}

function EditableRow({
  label,
  title,
  number,
  pageStart,
  pageEnd,
  busy,
  onSave,
  onDelete,
}: {
  label: string;
  title: string;
  number: string | null;
  pageStart: number | null;
  pageEnd: number | null;
  busy: boolean;
  onSave: (changes: Record<string, unknown>) => void;
  onDelete?: () => void;
}) {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState({ title, number: number ?? "", pageStart, pageEnd });

  if (!open) {
    return (
      <div className="flex items-center justify-between gap-2">
        <span>
          <span className="text-xs text-slate-500">
            {label} {number}
          </span>{" "}
          <b>{title}</b>{" "}
          <span className="text-xs text-slate-500">
            pp.{pageStart}–{pageEnd}
          </span>
        </span>
        <div className="flex shrink-0 gap-1">
          <button onClick={() => setOpen(true)} className="rounded-md border border-slate-700 px-2.5 py-1 text-xs text-slate-200 hover:border-slate-600">
            Edit
          </button>
          {onDelete && (
            <button onClick={onDelete} className="rounded-md border border-slate-700 px-2.5 py-1 text-xs text-red-300 hover:border-red-800">
              Delete
            </button>
          )}
        </div>
      </div>
    );
  }

  return (
    <div className="flex flex-wrap items-end gap-2">
      <label className="flex flex-col">
        <span className="text-xs text-slate-500">No.</span>
        <input
          value={draft.number}
          onChange={(e) => setDraft({ ...draft, number: e.target.value })}
          className="w-16 rounded-md border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-slate-100 outline-none focus:border-sky-500"
        />
      </label>
      <label className="flex flex-1 flex-col">
        <span className="text-xs text-slate-500">{label} title</span>
        <input
          value={draft.title}
          onChange={(e) => setDraft({ ...draft, title: e.target.value })}
          className="w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-slate-100 outline-none focus:border-sky-500"
        />
      </label>
      <label className="flex flex-col">
        <span className="text-xs text-slate-500">From p.</span>
        <input
          type="number"
          min={1}
          value={draft.pageStart ?? ""}
          onChange={(e) => setDraft({ ...draft, pageStart: Number(e.target.value) })}
          className="w-20 rounded-md border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-slate-100 outline-none focus:border-sky-500"
        />
      </label>
      <label className="flex flex-col">
        <span className="text-xs text-slate-500">To p.</span>
        <input
          type="number"
          min={1}
          value={draft.pageEnd ?? ""}
          onChange={(e) => setDraft({ ...draft, pageEnd: Number(e.target.value) })}
          className="w-20 rounded-md border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-slate-100 outline-none focus:border-sky-500"
        />
      </label>
      <button
        disabled={busy}
        onClick={() => {
          onSave({
            title: draft.title,
            number: draft.number || null,
            page_start: draft.pageStart,
            page_end: draft.pageEnd,
          });
          setOpen(false);
        }}
        className="rounded-md bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-600 disabled:opacity-40"
      >
        Save
      </button>
      <button onClick={() => setOpen(false)} className="rounded-md border border-slate-700 px-4 py-2 text-sm text-slate-200 hover:border-slate-600">
        Cancel
      </button>
    </div>
  );
}

/**
 * Which syllabus topic each lesson is filed under. That topic is what the
 * lesson's questions count towards in the backlog and the blueprint, so a
 * wrong one quietly mis-weights every paper built from this book.
 */
function LessonTopics({ sourceId }: { sourceId: string }) {
  const [rows, setRows] = useState<LessonTopicRow[]>([]);
  const [meta, setMeta] = useState<{ lessons: number; mapped: number; needs_review: number } | null>(null);
  const [topics, setTopics] = useState<CanonicalTopicRow[]>([]);
  const [note, setNote] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [mapped, all] = await Promise.all([topicsApi.lessons(sourceId), topicsApi.canonical()]);
      setRows(mapped.data);
      setMeta(mapped.meta);
      setTopics(all.data);
    } catch (e) {
      setError(e instanceof ApiError ? (e.detail ?? e.message) : "Could not load lesson topics.");
    }
  }, [sourceId]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function assign(row: LessonTopicRow, topicId: string) {
    setError(null);
    try {
      const { data } = await topicsApi.assign(sourceId, row.lesson_id, topicId === "" ? null : topicId);
      setNote(`Saved. ${data.questions_refiled} question(s) re-filed under the new topic.`);
      await load();
    } catch (e) {
      setError(e instanceof ApiError ? (e.detail ?? e.message) : "Could not save.");
    }
  }

  async function remap() {
    setError(null);
    try {
      await topicsApi.remap(sourceId);
      setNote("Re-mapping in the background. Your own choices are kept. Refresh in a few seconds.");
      setTimeout(() => void load(), 6000);
    } catch (e) {
      setError(e instanceof ApiError ? (e.detail ?? e.message) : "Could not start re-mapping.");
    }
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        {meta && (
          <>
            <span className="rounded bg-slate-800 px-2 py-1 font-mono text-[11px] text-slate-400">
              {meta.mapped} of {meta.lessons} lessons mapped
            </span>
            {meta.needs_review > 0 && (
              <span className="rounded bg-amber-950/50 px-2 py-1 font-mono text-[11px] text-amber-300">
                {meta.needs_review} low-confidence
              </span>
            )}
          </>
        )}
        <button
          onClick={remap}
          className="ml-auto rounded-md border border-slate-700 px-3 py-1.5 text-xs text-slate-300 hover:bg-slate-800"
        >
          Re-map automatically
        </button>
      </div>

      {note && <p className="text-xs text-emerald-400">{note}</p>}
      {error && <p className="text-xs text-red-400">{error}</p>}

      <ul className="divide-y divide-slate-800/70 rounded-xl border border-slate-800 bg-slate-900">
        {rows.map((row) => (
          <li key={row.lesson_id} className="flex flex-wrap items-center gap-3 px-4 py-3">
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm text-slate-200">{row.lesson}</p>
              <p className="truncate text-[11px] text-slate-500">{row.chapter}</p>
            </div>

            <span
              className={`rounded px-1.5 py-0.5 font-mono text-[10px] ${
                row.mapped_by === "human"
                  ? "bg-emerald-950/60 text-emerald-300"
                  : row.needs_review
                    ? "bg-amber-950/50 text-amber-300"
                    : row.mapped_by
                      ? "bg-slate-800 text-slate-400"
                      : "bg-red-950/50 text-red-300"
              }`}
            >
              {row.mapped_by === null
                ? "unmapped"
                : row.mapped_by === "human"
                  ? "set by hand"
                  : `${row.mapped_by} ${Math.round((row.confidence ?? 0) * 100)}%`}
            </span>

            <select
              value={row.canonical_topic_id ?? ""}
              onChange={(e) => void assign(row, e.target.value)}
              className="w-72 max-w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1.5 text-xs text-slate-200"
            >
              <option value="">— no topic —</option>
              {topics.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                </option>
              ))}
            </select>
          </li>
        ))}
        {rows.length === 0 && (
          <li className="px-4 py-8 text-center text-sm text-slate-500">No lessons yet. Process the book first.</li>
        )}
      </ul>
    </div>
  );
}
