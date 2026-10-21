"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import {
  ApiError,
  chapterReviews,
  getToken,
  reviews,
  type ChapterRow,
  type ChapterSampleQuestion,
} from "@/lib/api";

/**
 * Approve written questions a chapter at a time.
 *
 * Reading thousands of questions one by one is the bottleneck between the bank
 * and a usable test. Here a reviewer reads a random handful of a chapter — in
 * English and Tamil, against the book's own words — rejects anything wrong,
 * and approves the rest of the chapter on the strength of that sample.
 */

const PANEL = "rounded-xl border border-slate-800 bg-slate-900";
const BUTTON =
  "rounded-md px-3 py-1.5 text-xs font-medium disabled:cursor-not-allowed disabled:opacity-40";

export default function ChapterReviewPage() {
  const router = useRouter();

  const [chapters, setChapters] = useState<ChapterRow[]>([]);
  const [pendingTotal, setPendingTotal] = useState(0);
  const [selected, setSelected] = useState<ChapterRow | null>(null);
  const [sample, setSample] = useState<ChapterSampleQuestion[]>([]);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const fail = useCallback(
    (e: unknown, fallback: string) => {
      if (e instanceof ApiError && e.status === 401) router.replace("/login");
      else setError(e instanceof ApiError ? e.detail ?? e.message : e instanceof Error ? e.message : fallback);
    },
    [router],
  );

  const loadChapters = useCallback(async () => {
    const { data, meta } = await chapterReviews.list();
    setChapters(data);
    setPendingTotal(meta.pending);
    return data;
  }, []);

  useEffect(() => {
    if (!getToken()) {
      router.replace("/login");
      return;
    }

    (async () => {
      try {
        await loadChapters();
      } catch (e) {
        fail(e, "Could not load the chapters.");
      }
    })();
  }, [router, loadChapters, fail]);

  async function open(chapter: ChapterRow) {
    setSelected(chapter);
    setSample([]);
    setNotice(null);
    setError(null);
    try {
      const { data } = await chapterReviews.sample(chapter.key, 5);
      setSample(data.questions);
    } catch (e) {
      fail(e, "Could not load a sample.");
    }
  }

  async function more() {
    if (!selected) return;
    try {
      const { data } = await chapterReviews.sample(selected.key, 5);
      setSample((current) => {
        const seen = new Set(current.map((q) => q.question_id));
        return [...current, ...data.questions.filter((q) => !seen.has(q.question_id))];
      });
    } catch (e) {
      fail(e, "Could not load more questions.");
    }
  }

  async function reject(question: ChapterSampleQuestion) {
    if (!question.review_id) return;
    const reason = window.prompt("What is wrong with this question? (saved with the rejection)");
    if (!reason?.trim()) return;

    try {
      await reviews.decide(question.review_id, { decision: "reject", reason: `Spot-check: ${reason.trim()}` });
      setSample((current) => current.filter((q) => q.question_id !== question.question_id));
      const fresh = await loadChapters();
      setSelected(fresh.find((c) => c.key === selected?.key) ?? null);
    } catch (e) {
      fail(e, "Could not reject the question.");
    }
  }

  async function approveChapter() {
    if (!selected || sample.length === 0) return;
    if (!window.confirm(`Approve all ${selected.pending} waiting questions in this chapter?`)) return;

    setBusy(true);
    setError(null);
    const checked = sample.map((q) => q.question_id);
    let approved = 0;
    let skipped = 0;

    try {
      // A book chapter is one batch; the aptitude set takes several.
      for (;;) {
        const { data } = await chapterReviews.approve(selected.key, checked);
        approved += data.approved;
        skipped = data.skipped;
        setNotice(`Approved ${approved} so far…`);
        if (data.remaining === 0 || data.approved === 0) break;
      }

      setNotice(
        `Approved ${approved} question${approved === 1 ? "" : "s"} in ${selected.breadcrumb}.` +
          (skipped > 0 ? ` ${skipped} left alone because another reviewer has them open.` : ""),
      );
      setSelected(null);
      setSample([]);
      await loadChapters();
    } catch (e) {
      fail(e, "Could not approve the chapter.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <header className="mb-4 flex flex-wrap items-baseline gap-x-6 gap-y-1">
        <h1 className="text-lg font-semibold text-slate-100">Approve by chapter</h1>
        <p className="text-xs text-slate-500">
          Read a few questions from a chapter; if they are right, approve the whole chapter
        </p>
        <span className="ml-auto rounded bg-amber-950/50 px-2 py-1 font-mono text-[11px] text-amber-300">
          {pendingTotal} waiting in {chapters.length} chapters
        </span>
      </header>

      {error && (
        <p className="mb-4 rounded-lg border border-red-900 bg-red-950/60 px-4 py-3 text-sm text-red-300">{error}</p>
      )}
      {notice && (
        <p className="mb-4 rounded-lg border border-emerald-900 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-300">
          {notice}
        </p>
      )}

      <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
        <section className={`${PANEL} max-h-[75vh] overflow-y-auto`}>
          <ul className="divide-y divide-slate-800/70">
            {chapters.map((c) => (
              <li key={c.key}>
                <button
                  onClick={() => open(c)}
                  className={`flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-slate-800/60 ${
                    selected?.key === c.key ? "bg-slate-800" : ""
                  }`}
                >
                  <span className="flex-1">
                    <span className="block text-sm text-slate-100">{c.breadcrumb}</span>
                    <span className="block text-[11px] text-slate-500">{c.source}</span>
                  </span>
                  <span className="shrink-0 text-right font-mono text-[11px]">
                    <span className="block text-amber-300">{c.pending} waiting</span>
                    {c.approved > 0 && <span className="block text-emerald-400">{c.approved} approved</span>}
                  </span>
                </button>
              </li>
            ))}
            {chapters.length === 0 && (
              <li className="px-4 py-10 text-center text-sm text-slate-500">Nothing is waiting for review.</li>
            )}
          </ul>
        </section>

        <section className={PANEL}>
          {!selected ? (
            <p className="px-4 py-10 text-center text-sm text-slate-500">
              Pick a chapter to read a random sample of its questions.
            </p>
          ) : (
            <>
              <div className="flex flex-wrap items-center gap-2 border-b border-slate-800 px-4 py-3">
                <p className="flex-1 text-sm font-medium text-slate-100">{selected.breadcrumb}</p>
                <button onClick={more} disabled={busy} className={`${BUTTON} bg-slate-800 text-slate-200 hover:bg-slate-700`}>
                  Show 5 more
                </button>
                <button
                  onClick={approveChapter}
                  disabled={busy || sample.length === 0}
                  className={`${BUTTON} bg-emerald-600 text-white hover:bg-emerald-500`}
                >
                  {busy ? "Approving…" : `Approve all ${selected.pending} in this chapter`}
                </button>
              </div>

              <ol className="divide-y divide-slate-800/70">
                {sample.map((q, i) => (
                  <li key={q.question_id} className="space-y-3 px-4 py-4">
                    <div className="flex flex-wrap items-center gap-2 font-mono text-[11px] text-slate-500">
                      <span>#{i + 1}</span>
                      <span>{q.format}</span>
                      <span>{q.difficulty}</span>
                      {q.page != null && <span>book p.{q.page}</span>}
                      {q.topic && <span className="text-slate-400">{q.topic}</span>}
                      <button
                        onClick={() => reject(q)}
                        disabled={busy || !q.review_id}
                        className={`${BUTTON} ml-auto bg-red-950 text-red-300 hover:bg-red-900`}
                      >
                        Wrong — reject
                      </button>
                    </div>

                    <Version label="Question" version={q.master} correct={q.correct_option} />
                    {q.tamil && <Version label="தமிழ்" version={q.tamil} correct={q.correct_option} />}

                    {q.quote && (
                      <blockquote className="border-l-2 border-sky-700 pl-3 text-xs italic text-slate-400">
                        Book: “{q.quote}”
                      </blockquote>
                    )}
                  </li>
                ))}
              </ol>
            </>
          )}
        </section>
      </div>
    </>
  );
}

function Version({
  label,
  version,
  correct,
}: {
  label: string;
  version: ChapterSampleQuestion["master"];
  correct: string | null;
}) {
  return (
    <div>
      <p className="mb-1 text-[11px] uppercase tracking-wide text-slate-500">{label}</p>
      <p className="whitespace-pre-line text-sm text-slate-100">{version.question_text}</p>
      <ul className="mt-2 grid gap-1 sm:grid-cols-2">
        {Object.entries(version.options ?? {}).map(([key, text]) => (
          <li
            key={key}
            className={`rounded px-2 py-1 text-xs ${
              key === correct ? "bg-emerald-950/70 text-emerald-200 ring-1 ring-emerald-700" : "bg-slate-950 text-slate-300"
            }`}
          >
            <span className="mr-1 font-mono">{key}.</span>
            {text}
          </li>
        ))}
      </ul>
      {version.explanation && <p className="mt-2 text-xs text-slate-400">{version.explanation}</p>}
    </div>
  );
}
