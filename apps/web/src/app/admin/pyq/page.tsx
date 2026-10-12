"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import {
  ApiError,
  catalogue,
  getToken,
  pyqs,
  sources,
  type Exam,
  type Language,
  type PyqPaper,
  type TopicFrequencyRow,
} from "@/lib/api";

/**
 * Previous-year papers.
 *
 * The screen follows what the data can actually support: a paper is worth
 * nothing until it is parsed, parsed questions are worth nothing until they
 * are mapped to topics, and only then does the analysis mean anything. So each
 * paper carries its own state and offers exactly the next step.
 */

const PANEL = "rounded-xl border border-slate-800 bg-slate-900";
const FIELD =
  "rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 outline-none transition-colors focus:border-sky-500";
const BTN =
  "rounded-md px-4 py-2 text-sm font-medium text-white transition-colors disabled:cursor-not-allowed disabled:opacity-40";

const TREND_TONE: Record<string, string> = {
  rising: "bg-emerald-950/60 text-emerald-300",
  stable: "bg-slate-800 text-slate-400",
  falling: "bg-amber-950/50 text-amber-300",
  dormant: "bg-red-950/50 text-red-300",
};

export default function PyqPage() {
  const router = useRouter();

  const [exams, setExams] = useState<Exam[]>([]);
  const [languages, setLanguages] = useState<Language[]>([]);
  const [examId, setExamId] = useState<string>("");
  const [papers, setPapers] = useState<PyqPaper[]>([]);
  const [analysis, setAnalysis] = useState<TopicFrequencyRow[]>([]);
  const [disclaimer, setDisclaimer] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [note, setNote] = useState<string | null>(null);

  const fail = useCallback((e: unknown) => {
    setError(
      e instanceof ApiError
        ? [e.detail ?? e.message, ...Object.values(e.errors ?? {}).flat()].join(" ")
        : e instanceof Error
          ? e.message
          : "Something went wrong.",
    );
    setBusy(null);
  }, []);

  const refresh = useCallback(
    async (exam: string) => {
      try {
        const [list, freq] = await Promise.all([pyqs.list(), pyqs.analysis(exam)]);
        setPapers(list.data.filter((p) => p.exam_id === exam));
        setAnalysis(freq.data);
        setDisclaimer(freq.meta?.disclaimer ?? null);
      } catch (e) {
        fail(e);
      }
    },
    [fail],
  );

  useEffect(() => {
    if (!getToken()) {
      router.replace("/login");
      return;
    }

    (async () => {
      try {
        const [exs, langs] = await Promise.all([catalogue.exams(), catalogue.languages()]);
        setExams(exs.data);
        setLanguages(langs.data);

        const first = exs.data[0]?.id ?? "";
        setExamId(first);

        if (first) await refresh(first);
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) router.replace("/login");
        else fail(e);
      }
    })();
  }, [router, refresh, fail]);

  /**
   * Upload and parse in one action.
   *
   * The PDF is stored as an ordinary source first, so a past paper gets the
   * same checksum, dedup and storage handling as a textbook rather than a
   * second upload path that would drift from it.
   */
  async function handleUpload(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!examId) return;

    setError(null);
    setNote(null);
    setBusy("upload");

    const form = new FormData(event.currentTarget);
    const year = Number(form.get("year"));
    const name = String(form.get("paper_name"));

    try {
      const upload = new FormData();
      upload.append("file", form.get("file") as File);
      upload.append("kind", "previous_paper");
      upload.append("title", `${name} ${year}`);
      upload.append("language_id", String(form.get("language_id")));
      upload.append("rights_basis", "govt_open");

      const source = await sources.upload(upload);

      const paper = await pyqs.create({
        exam_id: examId,
        year,
        paper_name: name,
      });

      const parsed = await pyqs.parse(paper.data.id, source.data.id);
      const r = parsed.data;

      setNote(
        `Read ${r.pages} page${r.pages === 1 ? "" : "s"}: ${r.parsed} questions, ` +
          `${r.keyed} with answers` +
          (r.skipped > 0
            ? `. ${r.skipped} could not be read confidently and were skipped.`
            : "."),
      );

      (event.target as HTMLFormElement).reset();
      await refresh(examId);
    } catch (e) {
      fail(e);
    } finally {
      setBusy(null);
    }
  }

  async function runMap(paper: PyqPaper) {
    setError(null);
    setNote(null);
    setBusy(`map:${paper.id}`);

    try {
      const { data } = await pyqs.map(paper.id);

      setNote(
        `Filed ${data.mapped} question${data.mapped === 1 ? "" : "s"} ` +
          `(${data.lexical} by name, ${data.model} by model)` +
          (data.unmapped > 0 ? `. ${data.unmapped} stayed unmapped.` : ".") +
          (data.review > 0 ? ` ${data.review} flagged for review.` : ""),
      );

      await refresh(examId);
    } catch (e) {
      fail(e);
    } finally {
      setBusy(null);
    }
  }

  async function runAnalyze(paper: PyqPaper) {
    setError(null);
    setNote(null);
    setBusy(`analyze:${paper.id}`);

    try {
      const { data } = await pyqs.analyze(paper.id);
      setNote(
        `Analysed ${data.questions} questions across ${data.years} year` +
          `${data.years === 1 ? "" : "s"} into ${data.topics} topics.`,
      );
      await refresh(examId);
    } catch (e) {
      fail(e);
    } finally {
      setBusy(null);
    }
  }

  const heaviest = analysis[0]?.frequency_score ?? 1;

  return (
    <>
      <header className="mb-4 flex flex-wrap items-baseline gap-x-6 gap-y-1">
        <h1 className="text-lg font-semibold text-slate-100">Previous-year papers</h1>
        <p className="text-xs text-slate-500">
          What the exam has actually asked — the only evidence behind the prediction weight
        </p>
        <select
          value={examId}
          onChange={async (e) => {
            setExamId(e.target.value);
            await refresh(e.target.value);
          }}
          className={`ml-auto ${FIELD}`}
        >
          {exams.map((e) => (
            <option key={e.id} value={e.id}>
              {e.name}
            </option>
          ))}
        </select>
      </header>

      {error && (
        <p className="mb-4 rounded-lg border border-red-900 bg-red-950/60 px-4 py-3 text-sm text-red-300">
          {error}
        </p>
      )}
      {note && (
        <p className="mb-4 rounded-lg border border-amber-700/70 bg-amber-950/30 px-4 py-3 text-sm text-amber-200">
          {note}
        </p>
      )}

      <div className="grid gap-5 lg:grid-cols-[340px_1fr]">
        <div className="space-y-4">
          <section className={`${PANEL} p-5`}>
            <h2 className="mb-1 text-sm font-semibold text-slate-100">Add a paper</h2>
            <p className="mb-3 text-xs leading-relaxed text-slate-500">
              The PDF is read directly. Questions it cannot read confidently are skipped
              rather than guessed, and the number skipped is reported.
            </p>

            <form onSubmit={handleUpload} className="space-y-2">
              <input
                type="file"
                name="file"
                required
                accept=".pdf"
                className="block w-full text-xs text-slate-400 file:mr-3 file:rounded-md file:border-0 file:bg-slate-800 file:px-3 file:py-1.5 file:text-xs file:text-slate-200 hover:file:bg-slate-700"
              />
              <input
                name="paper_name"
                required
                placeholder="TNPSC Group IV"
                className={`w-full ${FIELD}`}
              />
              <div className="flex gap-2">
                <input
                  name="year"
                  type="number"
                  required
                  min={1950}
                  max={2100}
                  defaultValue={new Date().getFullYear() - 1}
                  className={`w-28 ${FIELD}`}
                />
                <select name="language_id" required className={`flex-1 ${FIELD}`}>
                  {languages.map((l) => (
                    <option key={l.id} value={l.id}>
                      {l.name}
                    </option>
                  ))}
                </select>
              </div>
              <button
                disabled={busy === "upload"}
                className={`w-full ${BTN} bg-sky-600 hover:bg-sky-500`}
              >
                {busy === "upload" ? "Reading…" : "Upload and read"}
              </button>
            </form>
          </section>

          <section className={PANEL}>
            <p className="border-b border-slate-800 px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-slate-500">
              Papers · {papers.length}
            </p>
            <ul className="divide-y divide-slate-800/70">
              {papers.map((paper) => (
                <li key={paper.id} className="p-3">
                  <div className="flex items-baseline justify-between gap-2">
                    <span className="text-sm text-slate-200">
                      {paper.paper_name} {paper.year}
                    </span>
                    <span className="font-mono text-[11px] text-slate-500">
                      {paper.question_count ?? paper.parsed_questions} q
                    </span>
                  </div>
                  <p className="mt-0.5 font-mono text-[11px] text-slate-500">{paper.status}</p>

                  <div className="mt-2 flex gap-2">
                    <button
                      onClick={() => runMap(paper)}
                      disabled={busy !== null}
                      className="rounded-md border border-slate-700 px-2.5 py-1 text-xs text-slate-200 hover:border-slate-600 disabled:opacity-40"
                    >
                      {busy === `map:${paper.id}` ? "Filing…" : "Map topics"}
                    </button>
                    <button
                      onClick={() => runAnalyze(paper)}
                      disabled={busy !== null}
                      className="rounded-md border border-slate-700 px-2.5 py-1 text-xs text-slate-200 hover:border-slate-600 disabled:opacity-40"
                    >
                      {busy === `analyze:${paper.id}` ? "Analysing…" : "Re-analyse"}
                    </button>
                  </div>
                </li>
              ))}
              {papers.length === 0 && (
                <li className="px-4 py-6 text-center text-xs text-slate-500">
                  No papers yet. Add one to build the analysis.
                </li>
              )}
            </ul>
          </section>
        </div>

        <section className={`${PANEL} p-5`}>
          <h2 className="text-sm font-semibold text-slate-100">What this exam favours</h2>
          <p className="mb-4 mt-1 text-xs leading-relaxed text-slate-500">
            Frequency blended with recency. A topic asked eight times a decade ago scores
            below one asked five times last year, because the second is the better bet.
          </p>

          {analysis.length === 0 ? (
            <p className="rounded-lg border border-dashed border-slate-800 p-8 text-center text-sm text-slate-500">
              Nothing analysed yet. Add a paper, map its topics, then analyse.
            </p>
          ) : (
            <>
              <ul className="space-y-2">
                {analysis.map((row) => (
                  <li key={row.canonical_topic_id} className="flex items-center gap-3">
                    <span className="w-56 shrink-0 truncate text-sm text-slate-300">
                      {row.topic ?? "—"}
                    </span>

                    {/* The bar is relative to the heaviest topic, which is how
                        the selection engine reads the score too. */}
                    <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-800">
                      <span
                        className="block h-1.5 rounded-full bg-sky-500"
                        style={{ width: `${Math.max(2, (row.frequency_score / heaviest) * 100)}%` }}
                      />
                    </span>

                    <span className="w-16 shrink-0 text-right font-mono text-[11px] text-slate-400">
                      {row.frequency_score.toFixed(3)}
                    </span>
                    <span
                      className={`w-16 shrink-0 rounded px-2 py-0.5 text-center font-mono text-[10px] ${
                        TREND_TONE[row.trend] ?? TREND_TONE.stable
                      }`}
                    >
                      {row.trend}
                    </span>
                    <span className="w-28 shrink-0 text-right font-mono text-[11px] text-slate-500">
                      {row.total_asked}q · {row.last_asked_year ?? "—"}
                    </span>
                  </li>
                ))}
              </ul>

              {disclaimer && (
                <p className="mt-5 border-t border-slate-800 pt-3 text-xs text-slate-500">
                  {disclaimer}
                </p>
              )}
            </>
          )}
        </section>
      </div>
    </>
  );
}
