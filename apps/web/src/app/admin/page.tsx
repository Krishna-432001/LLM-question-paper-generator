"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState } from "react";
import {
  ApiError,
  catalogue,
  downloadDocument,
  getToken,
  jobs,
  questions,
  sources,
  type Exam,
  type JobRow,
  type Language,
  type PaperResult,
  type PendingQuestion,
  type SourceDetail,
  type SourceRow,
} from "@/lib/api";

/**
 * The admin console.
 *
 * Every operator here is doing the same job: pushing a book down a pipeline
 * until it comes out as a PDF. So the screen is built around the pipeline, not
 * around the forms.
 *
 * Three rules hold the design together:
 *
 *   1. The stage is DERIVED from the data, never chosen by the user. A book
 *      that has been read but has no questions is at "generate", and the UI
 *      says so — the operator never has to work out what to click.
 *   2. Exactly one stage is open at a time. Finished stages collapse to a
 *      one-line receipt carrying the number that mattered; later stages are
 *      not shown at all, because an empty disabled form is noise.
 *   3. A blocked stage explains itself in the same place it blocks. "Build
 *      PDF" never sits greyed out with no reason next to it.
 */

const KINDS = [
  ["textbook", "Textbook"],
  ["reference_book", "Reference book"],
  ["previous_paper", "Previous year paper"],
  ["syllabus", "Syllabus"],
  ["government_document", "Government document"],
  ["notes", "Notes"],
  ["current_affairs", "Current affairs"],
  ["other", "Other"],
] as const;

const RIGHTS = [
  ["govt_open", "Government / open"],
  ["public_domain", "Public domain"],
  ["licensed", "Licensed"],
  ["owned", "Owned by us"],
  ["fair_use", "Fair use"],
] as const;

/**
 * Question shapes a real paper uses. All four are four options with exactly
 * one correct answer, so the answer key and the PDF are identical whichever
 * you pick — only the stem is laid out differently.
 */
const FORMATS = [
  ["mcq4", "Choose the best answer", "A direct question with four options."],
  ["statement_based", "Statement based", "Numbered statements, then which are correct."],
  ["assertion_reason", "Assertion & Reason", "A and R on separate lines, standard verdicts."],
  ["match_the_following", "Match the following", "Two lists; options are orderings."],
] as const;

const DONE = ["COMPLETED", "FAILED", "CANCELLED"];

const STEPS = ["read", "generate", "approve", "publish"] as const;
type Step = (typeof STEPS)[number];

const STEP_LABEL: Record<Step, string> = {
  read: "Read",
  generate: "Generate",
  approve: "Approve",
  publish: "Publish",
};

const PANEL = "rounded-xl border border-slate-800 bg-slate-900";
const FIELD =
  "rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 outline-none transition-colors focus:border-sky-500";
const BTN =
  "rounded-md px-4 py-2 text-sm font-medium text-white transition-colors disabled:cursor-not-allowed disabled:opacity-40";

/** Where a book has actually got to. Derived, never stored. */
function stepFor(source: SourceDetail | null): Step {
  if (!source) return "read";
  if (source.status !== "ready") return "read";
  if (source.questions.total === 0) return "generate";
  if (source.questions.approved === 0) return "approve";
  return "publish";
}

export default function AdminPage() {
  const router = useRouter();

  const [languages, setLanguages] = useState<Language[]>([]);
  const [exams, setExams] = useState<Exam[]>([]);
  const [list, setList] = useState<SourceRow[]>([]);
  const [selected, setSelected] = useState<SourceDetail | null>(null);
  const [job, setJob] = useState<JobRow | null>(null);
  const [paper, setPaper] = useState<PaperResult | null>(null);
  const [pending, setPending] = useState<PendingQuestion[]>([]);
  const [expanded, setExpanded] = useState<string | null>(null);
  const [openStep, setOpenStep] = useState<Step | null>(null);
  const [uploading, setUploading] = useState(false);
  const [formats, setFormats] = useState<string[]>(["mcq4"]);
  const [format, setFormat] = useState("pdf");
  const [withKey, setWithKey] = useState(true);
  const [withExplanations, setWithExplanations] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [note, setNote] = useState<string | null>(null);

  const poller = useRef<ReturnType<typeof setInterval> | null>(null);

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

  const refreshList = useCallback(async () => {
    const { data } = await sources.list();
    setList(data);
  }, []);

  const openSource = useCallback(
    async (id: string) => {
      try {
        const [detail, pendingList] = await Promise.all([
          sources.show(id),
          questions.list({ source_id: id, status: "PENDING_REVIEW", limit: 100 }),
        ]);
        setSelected(detail.data);
        setPending(pendingList.data);
        setPaper(null);
        setError(null);
        // Follow the book: whatever stage it is at becomes the open one.
        setOpenStep(null);
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
        const [langs, exs] = await Promise.all([catalogue.languages(), catalogue.exams()]);
        setLanguages(langs.data);
        setExams(exs.data);
        await refreshList();
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) router.replace("/login");
        else fail(e);
      }
    })();
  }, [router, refreshList, fail]);

  useEffect(
    () => () => {
      if (poller.current) clearInterval(poller.current);
    },
    [],
  );

  const watch = useCallback(
    (jobId: string, sourceId: string) => {
      if (poller.current) clearInterval(poller.current);

      poller.current = setInterval(async () => {
        try {
          const { data } = await jobs.show(jobId);
          setJob(data);

          if (DONE.includes(data.status)) {
            if (poller.current) clearInterval(poller.current);
            setBusy(null);
            await refreshList();
            await openSource(sourceId);
            if (data.status === "FAILED") setError(data.error?.message ?? "The job failed.");
          }
        } catch (e) {
          if (poller.current) clearInterval(poller.current);
          fail(e);
        }
      }, 1500);
    },
    [refreshList, openSource, fail],
  );

  async function handleUpload(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setNote(null);
    setBusy("upload");

    try {
      const { data } = await sources.upload(new FormData(event.currentTarget));
      (event.target as HTMLFormElement).reset();
      setUploading(false);
      await refreshList();
      await openSource(data.id);
      setNote(`“${data.title}” uploaded. Read it next.`);
    } catch (e) {
      fail(e);
    } finally {
      setBusy(null);
    }
  }

  async function handleProcess() {
    if (!selected) return;
    setError(null);
    setNote(null);
    setBusy("process");

    try {
      const { data } = await sources.process(selected.id);
      setJob({
        id: data.job_id,
        type: "ingest",
        status: data.status,
        status_label: "Queued",
        progress: 0,
        progress_message: null,
      });
      watch(data.job_id, selected.id);
    } catch (e) {
      fail(e);
    }
  }

  async function handleGenerate(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selected) return;
    setError(null);
    setNote(null);
    if (formats.length === 0) {
      setError("Pick at least one question format.");
      return;
    }

    setBusy("generate");

    const form = new FormData(event.currentTarget);

    try {
      const { data } = await questions.generate({
        source_id: selected.id,
        exam_id: (form.get("exam_id") as string) || null,
        language_id: form.get("language_id") as string,
        target_count: Number(form.get("target_count")),
        formats,
      });

      setNote(
        `Drafting ${data.planned.raw_questions} to keep ${form.get("target_count")} — the rest are discarded by validation.`,
      );
      setJob({
        id: data.job_id,
        type: "generation",
        status: "QUEUED",
        status_label: "Queued",
        progress: 0,
        progress_message: null,
      });
      watch(data.job_id, selected.id);
    } catch (e) {
      fail(e);
    }
  }

  async function decide(
    decision: "approve" | "reject",
    options: { question_ids?: string[]; min_quality?: number } = {},
  ) {
    if (!selected) return;
    setError(null);
    setNote(null);
    setBusy("decide");

    try {
      const { data } = await sources.decide(selected.id, { decision, ...options });
      setNote(
        `${decision === "approve" ? "Approved" : "Rejected"} ${data.decided} of ${data.considered}.` +
          (data.failed.length ? ` ${data.failed.length} could not be changed.` : ""),
      );
      await openSource(selected.id);
    } catch (e) {
      fail(e);
    } finally {
      setBusy(null);
    }
  }

  async function handlePaper(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selected) return;
    setError(null);
    setNote(null);
    setBusy("paper");

    const form = new FormData(event.currentTarget);
    const choice = String(form.get("paper_language") ?? "");
    const english = languages.find((l) => l.code === "en");
    const tamil = languages.find((l) => l.code === "ta");
    // "Both" prints the book's own language first and the other beneath it,
    // so a Tamil book reads Tamil-first as a TNPSC paper does.
    const bookIsTamil = languages.find((l) => l.id === selected.language_id)?.code === "ta";
    const language =
      choice === "both" && english && tamil
        ? {
            language_id: (bookIsTamil ? tamil : english).id,
            language_mode: "bilingual",
            secondary_language_id: (bookIsTamil ? english : tamil).id,
          }
        : choice === "ta" && tamil
          ? { language_id: tamil.id, language_mode: "single" }
          : choice === "en" && english
            ? { language_id: english.id, language_mode: "single" }
            : {};

    try {
      const { data } = await sources.paper(selected.id, {
        title: form.get("title") || selected.title,
        question_count: Number(form.get("question_count")),
        duration_minutes: Number(form.get("duration_minutes")),
        include_answer_key: withKey,
        include_explanations: withExplanations,
        format,
        ...language,
      });

      setPaper(data);

      if (data.question_count < data.requested_count) {
        const a = data.availability;
        setNote(
          `Asked for ${data.requested_count}, produced ${data.question_count} — ` +
            (data.language_mode === "bilingual" && a
              ? `only ${a.both} of this book's approved questions exist in both languages (${a.primary} / ${a.secondary}).`
              : "the bank ran out of distinct approved questions in that language."),
        );
      }
    } catch (e) {
      fail(e);
    } finally {
      setBusy(null);
    }
  }

  const counts = selected?.questions;
  const running = job !== null && !DONE.includes(job.status);
  const current = stepFor(selected);
  const active = openStep ?? current;
  const done = (s: Step) => STEPS.indexOf(s) < STEPS.indexOf(current);

  return (
    <>
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

      <div className="grid gap-5 lg:grid-cols-[300px_1fr]">
        {/* ---------------------------------------------------- the book shelf */}
        <aside className="space-y-3">
          <button
            onClick={() => setUploading((v) => !v)}
            className={`w-full ${BTN} ${uploading ? "bg-slate-700 hover:bg-slate-600" : "bg-sky-600 hover:bg-sky-500"}`}
          >
            {uploading ? "Cancel" : "+ Upload a book"}
          </button>

          {uploading && (
            <form onSubmit={handleUpload} className={`${PANEL} space-y-2 p-4`}>
              <input
                type="file"
                name="file"
                required
                accept=".pdf,.png,.jpg,.jpeg,.docx"
                className="block w-full text-xs text-slate-400 file:mr-3 file:rounded-md file:border-0 file:bg-slate-800 file:px-3 file:py-1.5 file:text-xs file:text-slate-200 hover:file:bg-slate-700"
              />
              <input
                name="title"
                required
                placeholder="Samacheer Science Std 10"
                className={`w-full ${FIELD}`}
              />
              <select name="kind" defaultValue="textbook" className={`w-full ${FIELD}`}>
                {KINDS.map(([v, l]) => (
                  <option key={v} value={v}>
                    {l}
                  </option>
                ))}
              </select>
              <select name="language_id" required className={`w-full ${FIELD}`}>
                {languages.map((l) => (
                  <option key={l.id} value={l.id}>
                    {l.name}
                  </option>
                ))}
              </select>
              <select name="rights_basis" defaultValue="govt_open" className={`w-full ${FIELD}`}>
                {RIGHTS.map(([v, l]) => (
                  <option key={v} value={v}>
                    {l}
                  </option>
                ))}
              </select>
              <button disabled={busy === "upload"} className={`w-full ${BTN} bg-sky-600 hover:bg-sky-500`}>
                {busy === "upload" ? "Uploading…" : "Upload"}
              </button>
            </form>
          )}

          <div className={PANEL}>
            <p className="border-b border-slate-800 px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-slate-500">
              Books · {list.length}
            </p>
            <ul className="max-h-[60vh] overflow-y-auto p-2">
              {list.map((s) => (
                <li key={s.id}>
                  <button
                    onClick={() => openSource(s.id)}
                    className={`w-full rounded-lg px-3 py-2 text-left transition-colors ${
                      selected?.id === s.id
                        ? "bg-sky-950/60 ring-1 ring-sky-700"
                        : "hover:bg-slate-800/60"
                    }`}
                  >
                    <span className="block truncate text-sm text-slate-200">{s.title}</span>
                    <span className="font-mono text-[11px] text-slate-500">
                      {s.status}
                      {s.page_count ? ` · ${s.page_count}p` : ""}
                    </span>
                  </button>
                </li>
              ))}
              {list.length === 0 && (
                <li className="px-3 py-6 text-center text-xs text-slate-500">
                  No books yet. Upload one to start.
                </li>
              )}
            </ul>
          </div>
        </aside>

        {/* ------------------------------------------------------- the pipeline */}
        <div className="space-y-4">
          {!selected && (
            <div className="grid place-items-center rounded-xl border border-dashed border-slate-800 py-24 text-center">
              <div>
                <p className="text-sm text-slate-400">Pick a book from the left.</p>
                <p className="mt-1 text-xs text-slate-600">
                  Or upload one — it goes read → generate → approve → publish.
                </p>
              </div>
            </div>
          )}

          {selected && (
            <>
              <section className={`${PANEL} p-5`}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <h1 className="text-lg font-semibold text-slate-100">{selected.title}</h1>
                    <p className="mt-0.5 font-mono text-[11px] text-slate-500">
                      {selected.kind} · {selected.status}
                      {selected.page_count ? ` · ${selected.page_count} pages` : ""}
                    </p>
                  </div>
                  {counts && counts.total > 0 && (
                    <div className="flex flex-wrap gap-1.5">
                      <Chip value={counts.total} label="drafted" />
                      <Chip value={counts.approved} label="approved" tone="emerald" />
                      <Chip value={counts.pending_review} label="waiting" tone="amber" />
                      <Chip value={counts.rejected} label="rejected" />
                    </div>
                  )}
                </div>

                <Stepper current={current} active={active} onPick={setOpenStep} />
              </section>

              {running && job && (
                <section className="rounded-xl border border-sky-800 bg-sky-950/40 p-4">
                  <p className="text-sm text-sky-100">
                    {job.type === "ingest" ? "Reading the book" : "Generating questions"}
                    <span className="ml-2 font-mono text-xs text-sky-300">
                      {job.status_label ?? job.status}
                    </span>
                    {job.progress_message && (
                      <span className="ml-2 text-xs text-sky-300/80">{job.progress_message}</span>
                    )}
                  </p>
                  <div className="mt-3 h-1 w-full overflow-hidden rounded-full bg-slate-800">
                    <div
                      className="h-1 rounded-full bg-sky-500 transition-all duration-500"
                      style={{ width: `${Math.max(4, job.progress ?? 0)}%` }}
                    />
                  </div>
                </section>
              )}

              {/* ------------------------------------------------------ 1 · read */}
              {active === "read" ? (
                <Card
                  n={1}
                  title="Read the book"
                  hint="Extracts the text, finds the chapters, splits them into passages and embeds them. Questions can only be drawn from passages, so nothing happens before this."
                >
                  <button
                    onClick={handleProcess}
                    disabled={busy !== null || selected.status === "ready"}
                    className={`${BTN} bg-sky-600 hover:bg-sky-500`}
                  >
                    {selected.status === "ready" ? "Already read" : "Read book"}
                  </button>
                </Card>
              ) : (
                done("read") && (
                  <Receipt
                    title="Read"
                    detail={`${selected.page_count ?? 0} pages · structure detected`}
                    onOpen={() => setOpenStep("read")}
                    href={`/admin/structure?source=${selected.id}`}
                    hrefLabel="Check what was read"
                  />
                )
              )}

              {/* -------------------------------------------------- 2 · generate */}
              {active === "generate" && selected.status === "ready" && (
                <Card
                  n={2}
                  title="Generate questions"
                  hint="Four-option multiple choice, each one drawn from a cited passage of this book. More are drafted than you ask for; the weak and duplicate ones are thrown away."
                >
                  <form onSubmit={handleGenerate} className="space-y-4">
                    <fieldset>
                      <legend className="mb-2 text-xs text-slate-500">Question formats</legend>
                      <div className="grid gap-2 sm:grid-cols-2">
                        {FORMATS.map(([value, label, blurb]) => {
                          const on = formats.includes(value);

                          return (
                            <button
                              key={value}
                              type="button"
                              onClick={() =>
                                setFormats((f) =>
                                  on ? f.filter((x) => x !== value) : [...f, value],
                                )
                              }
                              className={`rounded-lg border px-3 py-2 text-left transition-colors ${
                                on
                                  ? "border-sky-600 bg-sky-950/50"
                                  : "border-slate-800 hover:border-slate-700"
                              }`}
                            >
                              <span className="flex items-center gap-2 text-sm text-slate-200">
                                <span
                                  className={`grid h-4 w-4 shrink-0 place-items-center rounded-sm border text-[10px] ${
                                    on
                                      ? "border-sky-500 bg-sky-600 text-white"
                                      : "border-slate-600"
                                  }`}
                                >
                                  {on ? "✓" : ""}
                                </span>
                                {label}
                              </span>
                              <span className="mt-0.5 block pl-6 text-[11px] text-slate-500">
                                {blurb}
                              </span>
                            </button>
                          );
                        })}
                      </div>
                    </fieldset>

                    <div className="flex flex-wrap items-end gap-3">
                    <Field label="Exam">
                      <select name="exam_id" className={FIELD}>
                        {exams.map((e) => (
                          <option key={e.id} value={e.id}>
                            {e.name}
                          </option>
                        ))}
                      </select>
                    </Field>
                    <Field label="Language">
                      <select
                        name="language_id"
                        defaultValue={selected.language_id}
                        className={FIELD}
                      >
                        {languages.map((l) => (
                          <option key={l.id} value={l.id}>
                            {l.name}
                          </option>
                        ))}
                      </select>
                    </Field>
                    <Field label="How many">
                      <input
                        name="target_count"
                        type="number"
                        min={1}
                        max={500}
                        defaultValue={25}
                        className={`w-24 ${FIELD}`}
                      />
                    </Field>
                    <button
                      disabled={busy !== null}
                      className={`${BTN} bg-sky-600 hover:bg-sky-500`}
                    >
                      Generate
                    </button>
                    </div>
                  </form>
                </Card>
              )}
              {active !== "generate" && done("generate") && counts && (
                <Receipt
                  title="Generated"
                  detail={`${counts.total} drafted · ${counts.rejected} rejected by validation`}
                  onOpen={() => setOpenStep("generate")}
                />
              )}

              {/* --------------------------------------------------- 3 · approve */}
              {active === "approve" && (
                <Card
                  n={3}
                  title={
                    pending.length > 0
                      ? `Approve questions — ${pending.length} waiting`
                      : "Approve questions"
                  }
                  hint="Nothing unapproved is ever printed. Open a question to see its options, the correct answer, and the passage it came from."
                >
                  {pending.length === 0 ? (
                    <p className="text-sm text-slate-400">
                      Nothing waiting.{" "}
                      {counts?.approved
                        ? `${counts.approved} already approved.`
                        : "Generate some questions first."}
                    </p>
                  ) : (
                    <>
                      <div className="mb-3 flex flex-wrap gap-2">
                        <button
                          onClick={() => decide("approve")}
                          disabled={busy !== null}
                          className={`${BTN} bg-emerald-600 hover:bg-emerald-500`}
                        >
                          Approve all {pending.length}
                        </button>
                        <button
                          onClick={() => decide("approve", { min_quality: 85 })}
                          disabled={busy !== null}
                          className={`${BTN} border border-emerald-700 bg-transparent text-emerald-300 hover:bg-emerald-950/50`}
                        >
                          Approve quality 85+
                        </button>
                        <button
                          onClick={() => decide("reject")}
                          disabled={busy !== null}
                          className={`${BTN} border border-slate-700 bg-transparent text-red-300 hover:border-red-800`}
                        >
                          Reject all
                        </button>
                      </div>

                      <ul className="max-h-[30rem] space-y-1.5 overflow-y-auto pr-1">
                        {pending.map((q) => (
                          <li
                            key={q.id}
                            className="rounded-lg border border-slate-800 bg-slate-950/40 p-3"
                          >
                            <div className="flex items-start justify-between gap-3">
                              <button
                                onClick={() => setExpanded(expanded === q.id ? null : q.id)}
                                className="flex-1 text-left"
                              >
                                <span className="font-mono text-[11px] text-slate-500">
                                  {q.difficulty} · quality {q.quality_score?.toFixed(1) ?? "—"}
                                </span>
                                <span className="mt-0.5 block text-sm text-slate-200">
                                  {q.question_text}
                                </span>
                              </button>
                              <div className="flex shrink-0 gap-1">
                                <IconButton
                                  onClick={() => decide("approve", { question_ids: [q.id] })}
                                  disabled={busy !== null}
                                  tone="emerald"
                                  title="Approve"
                                >
                                  ✓
                                </IconButton>
                                <IconButton
                                  onClick={() => decide("reject", { question_ids: [q.id] })}
                                  disabled={busy !== null}
                                  tone="red"
                                  title="Reject"
                                >
                                  ✕
                                </IconButton>
                              </div>
                            </div>

                            {expanded === q.id && (
                              <div className="mt-3 space-y-2 border-t border-slate-800 pt-3">
                                <ul className="grid gap-1 sm:grid-cols-2">
                                  {Object.entries(q.options ?? {}).map(([key, text]) => (
                                    <li
                                      key={key}
                                      className={`flex gap-2 rounded-md border px-3 py-1.5 text-sm ${
                                        key === q.correct_option
                                          ? "border-emerald-700 bg-emerald-950/40 text-emerald-200"
                                          : "border-slate-800 text-slate-300"
                                      }`}
                                    >
                                      <span className="font-mono text-xs text-slate-500">
                                        {key}
                                      </span>
                                      {text}
                                    </li>
                                  ))}
                                </ul>
                                {/* The citation is the trust surface: a question
                                    you cannot trace back should not be printed. */}
                                {q.sources.map((src, i) => (
                                  <div
                                    key={i}
                                    className="border-l-2 border-slate-700 pl-3 text-xs text-slate-400"
                                  >
                                    <p className="italic text-slate-300">
                                      “{src.supporting_quote}”
                                    </p>
                                    <p className="mt-0.5 text-slate-500">{src.citation}</p>
                                  </div>
                                ))}
                              </div>
                            )}
                          </li>
                        ))}
                      </ul>
                    </>
                  )}
                </Card>
              )}
              {active !== "approve" && done("approve") && counts && (
                <Receipt
                  title="Approved"
                  detail={`${counts.approved} ready to print${counts.pending_review ? ` · ${counts.pending_review} still waiting` : ""}`}
                  onOpen={() => setOpenStep("approve")}
                />
              )}

              {/* --------------------------------------------------- 4 · publish */}
              {active === "publish" && (
                <Card
                  n={4}
                  title="Build the paper"
                  hint="Near-duplicates are dropped during selection, so a paper can come out shorter than you asked for. If it does, you will be told."
                >
                  {(counts?.approved ?? 0) === 0 ? (
                    <p className="text-sm text-amber-300">
                      No approved questions yet — approve some first.
                    </p>
                  ) : (
                    <form onSubmit={handlePaper} className="space-y-3">
                      <div className="flex flex-wrap items-end gap-3">
                        <Field label="Paper title">
                          <input
                            name="title"
                            defaultValue={selected.title}
                            className={`w-64 ${FIELD}`}
                          />
                        </Field>
                        <Field label="Questions">
                          <input
                            name="question_count"
                            type="number"
                            min={1}
                            max={200}
                            defaultValue={Math.max(1, counts?.approved ?? 1)}
                            className={`w-24 ${FIELD}`}
                          />
                        </Field>
                        <Field label="Minutes">
                          <input
                            name="duration_minutes"
                            type="number"
                            min={1}
                            defaultValue={30}
                            className={`w-24 ${FIELD}`}
                          />
                        </Field>
                        <Field label="Paper language">
                          <select
                            name="paper_language"
                            defaultValue={
                              languages.find((l) => l.id === selected.language_id)?.code === "ta" ? "ta" : "en"
                            }
                            className={FIELD}
                            title="Both prints every question twice: the book's language first, the other beneath it. Only questions with a validated translation can go on a Tamil or bilingual paper."
                          >
                            <option value="en">English</option>
                            <option value="ta">Tamil</option>
                            <option value="both">Both</option>
                          </select>
                        </Field>
                        <Field label="File">
                          <select
                            value={format}
                            onChange={(e) => setFormat(e.target.value)}
                            className={FIELD}
                            title="PDF to print. Word to edit. Excel or JSON to reuse the questions, options and answers elsewhere."
                          >
                            <option value="pdf">PDF</option>
                            <option value="docx">Word</option>
                            <option value="xlsx">Excel</option>
                            <option value="json">JSON</option>
                          </select>
                        </Field>
                        <button
                          disabled={busy !== null}
                          className={`${BTN} bg-emerald-600 hover:bg-emerald-500`}
                        >
                          {busy === "paper" ? "Building…" : `Build ${format === "pdf" ? "PDF" : format.toUpperCase()}`}
                        </button>
                      </div>

                      <div className="flex gap-4 text-xs text-slate-400">
                        <Toggle checked={withKey} onChange={setWithKey}>
                          Answer key
                        </Toggle>
                        <Toggle checked={withExplanations} onChange={setWithExplanations}>
                          Explanations
                        </Toggle>
                      </div>
                    </form>
                  )}
                </Card>
              )}

              {paper && (
                <section className="rounded-xl border border-emerald-800 bg-emerald-950/30 p-5">
                  <h3 className="text-sm font-semibold text-emerald-100">
                    Paper ready — {paper.question_count} questions
                    {paper.languages?.length ? (
                      <span className="ml-2 font-normal text-emerald-300/80">
                        {paper.languages
                          .map((code) => languages.find((l) => l.code === code)?.name ?? code)
                          .join(" + ")}
                      </span>
                    ) : null}
                  </h3>
                  <ul className="mt-3 space-y-2">
                    {paper.documents.map((d) => (
                      <li
                        key={d.id}
                        className="flex items-center justify-between gap-4 rounded-lg bg-slate-950/40 px-3 py-2"
                      >
                        <span className="text-sm text-slate-200">
                          {d.title}
                          <span className="ml-2 font-mono text-[11px] text-slate-500">
                            {d.document_code} · {d.page_count ?? "?"}p
                          </span>
                        </span>
                        <button
                          onClick={() =>
                            downloadDocument(d.id, `${d.document_code}.${d.format ?? "pdf"}`).catch(fail)
                          }
                          className={`shrink-0 ${BTN} bg-emerald-600 py-1.5 hover:bg-emerald-500`}
                        >
                          Download
                        </button>
                      </li>
                    ))}
                  </ul>
                </section>
              )}
            </>
          )}
        </div>
      </div>
    </>
  );
}

/* ------------------------------------------------------------------ pieces */

/**
 * The pipeline, always visible. Past steps are clickable so an operator can go
 * back and regenerate; future ones are not, because they are not reachable yet.
 */
function Stepper({
  current,
  active,
  onPick,
}: {
  current: Step;
  active: Step;
  onPick: (s: Step | null) => void;
}) {
  return (
    <ol className="mt-4 flex items-center gap-1">
      {STEPS.map((step, i) => {
        const index = STEPS.indexOf(current);
        const state = i < index ? "done" : i === index ? "current" : "todo";
        const isActive = step === active;
        const reachable = i <= index;

        return (
          <li key={step} className="flex flex-1 items-center gap-1">
            <button
              disabled={!reachable}
              onClick={() => onPick(step === current ? null : step)}
              className={`flex flex-1 items-center gap-2 rounded-lg px-3 py-2 text-left transition-colors ${
                isActive
                  ? "bg-slate-800"
                  : reachable
                    ? "hover:bg-slate-800/60"
                    : "cursor-default opacity-40"
              }`}
            >
              <span
                className={`grid h-5 w-5 shrink-0 place-items-center rounded-full font-mono text-[10px] ${
                  state === "done"
                    ? "bg-emerald-600 text-white"
                    : state === "current"
                      ? "bg-sky-600 text-white"
                      : "bg-slate-800 text-slate-500"
                }`}
              >
                {state === "done" ? "✓" : i + 1}
              </span>
              <span
                className={`truncate text-xs ${
                  state === "todo" ? "text-slate-600" : "text-slate-300"
                }`}
              >
                {STEP_LABEL[step]}
              </span>
            </button>
            {i < STEPS.length - 1 && (
              <span
                className={`hidden h-px w-4 shrink-0 sm:block ${
                  i < index ? "bg-emerald-700" : "bg-slate-800"
                }`}
              />
            )}
          </li>
        );
      })}
    </ol>
  );
}

function Card({
  n,
  title,
  hint,
  children,
}: {
  n: number;
  title: string;
  hint: string;
  children: React.ReactNode;
}) {
  return (
    <section className={`${PANEL} overflow-hidden`}>
      <div className="border-l-2 border-sky-600 p-5">
        <h2 className="text-sm font-semibold text-slate-100">
          <span className="mr-2 font-mono text-xs text-sky-500">{n}</span>
          {title}
        </h2>
        <p className="mb-4 mt-1 max-w-2xl text-xs leading-relaxed text-slate-500">{hint}</p>
        {children}
      </div>
    </section>
  );
}

/** A finished step, collapsed to the one number that mattered. */
function Receipt({
  title,
  detail,
  onOpen,
  href,
  hrefLabel,
}: {
  title: string;
  detail: string;
  onOpen: () => void;
  href?: string;
  hrefLabel?: string;
}) {
  return (
    <div className="flex items-center gap-3 rounded-xl border border-slate-800/70 bg-slate-900/40 px-4 py-2.5">
      <span className="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-emerald-950 font-mono text-[10px] text-emerald-400">
        ✓
      </span>
      <span className="text-sm text-slate-300">{title}</span>
      <span className="truncate text-xs text-slate-500">{detail}</span>
      <span className="ml-auto flex shrink-0 gap-3 text-xs">
        {href && (
          <a href={href} className="text-sky-400 hover:underline">
            {hrefLabel}
          </a>
        )}
        <button onClick={onOpen} className="text-slate-500 hover:text-slate-300">
          Redo
        </button>
      </span>
    </div>
  );
}

function Chip({
  value,
  label,
  tone,
}: {
  value: number;
  label: string;
  tone?: "emerald" | "amber";
}) {
  const tones = {
    emerald: "bg-emerald-950/60 text-emerald-300",
    amber: "bg-amber-950/50 text-amber-300",
  };

  return (
    <span
      className={`rounded px-2 py-1 font-mono text-[11px] ${tone ? tones[tone] : "bg-slate-800 text-slate-400"}`}
    >
      {value} {label}
    </span>
  );
}

function IconButton({
  onClick,
  disabled,
  tone,
  title,
  children,
}: {
  onClick: () => void;
  disabled: boolean;
  tone: "emerald" | "red";
  title: string;
  children: React.ReactNode;
}) {
  const tones = {
    emerald: "bg-emerald-700 text-white hover:bg-emerald-600",
    red: "border border-slate-700 text-red-300 hover:border-red-800",
  };

  return (
    <button
      onClick={onClick}
      disabled={disabled}
      title={title}
      className={`rounded-md px-2.5 py-1 text-sm transition-colors disabled:opacity-40 ${tones[tone]}`}
    >
      {children}
    </button>
  );
}

function Toggle({
  checked,
  onChange,
  children,
}: {
  checked: boolean;
  onChange: (v: boolean) => void;
  children: React.ReactNode;
}) {
  return (
    <label className="flex cursor-pointer items-center gap-1.5">
      <input
        type="checkbox"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
        className="accent-sky-500"
      />
      {children}
    </label>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1">
      <span className="text-xs text-slate-500">{label}</span>
      {children}
    </label>
  );
}
