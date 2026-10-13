"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState } from "react";
import {
  ApiError,
  catalogue,
  getToken,
  glossaryApi,
  translationsApi,
  type Exam,
  type GlossaryEntry,
  type Language,
  type TranslationCheck,
  type TranslationEdits,
  type TranslationJob,
  type TranslationReviewItem,
  type TranslationStats,
  type TranslationStatus,
} from "@/lib/api";

/**
 * Translations: how much of an exam can be set in a language, ordering the
 * rest, and the human side of it.
 *
 * The machine translates and checks; this page is where a person reads what
 * it was unsure of beside the source, fixes it or throws it out, and keeps
 * the board's vocabulary — the glossary every check enforces — correct.
 */

const PANEL = "rounded-xl border border-slate-800 bg-slate-900";
const FIELD =
  "rounded-md border border-slate-700 bg-slate-950 px-2.5 py-1.5 text-sm text-slate-100 outline-none focus:border-sky-500";
const BUTTON =
  "rounded-md bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500 disabled:cursor-not-allowed disabled:opacity-50";
const GHOST =
  "rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-300 hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50";
const DONE = ["COMPLETED", "FAILED", "CANCELLED"];
const KEYS = ["A", "B", "C", "D"] as const;

const FAILURE_LABELS: Record<string, string> = {
  V1_STRUCTURE: "Structure / numbers",
  V2_GLOSSARY: "Board terms",
  V3_MEANING: "Meaning drift",
  V4_ANSWER: "Answer changed",
  V5_LANGUAGE: "Script / length",
};

const STATUS_STYLE: Record<TranslationStatus, string> = {
  AI_VALIDATED: "text-emerald-300",
  HUMAN_APPROVED: "text-sky-300",
  NEEDS_REVIEW: "text-amber-300",
  STALE: "text-slate-400",
  REJECTED: "text-red-300",
  MACHINE: "text-slate-500",
};

function message(e: unknown): string {
  if (e instanceof ApiError) {
    const first = e.errors ? Object.values(e.errors)[0]?.[0] : undefined;
    return first ?? e.detail ?? e.message;
  }
  return e instanceof Error ? e.message : "Something went wrong.";
}

export default function TranslationsPage() {
  const router = useRouter();
  const [languages, setLanguages] = useState<Language[]>([]);
  const [exams, setExams] = useState<Exam[]>([]);
  const [languageId, setLanguageId] = useState("");
  const [examId, setExamId] = useState("");
  const [tab, setTab] = useState<"review" | "glossary">("review");
  const [stats, setStats] = useState<TranslationStats | null>(null);
  const [count, setCount] = useState(0);
  const [job, setJob] = useState<TranslationJob | null>(null);
  const [refreshKey, setRefreshKey] = useState(0);
  const [error, setError] = useState<string | null>(null);
  const [note, setNote] = useState<string | null>(null);
  const poller = useRef<ReturnType<typeof setInterval> | null>(null);

  const loadStats = useCallback(async (lang: string, exam: string) => {
    if (!lang) return null;
    const { data } = await translationsApi.stats(lang, exam || undefined);
    setStats(data);
    setCount(Math.min(data.needs_translation, 100));
    return data;
  }, []);

  const watch = useCallback(
    (jobId: string, lang: string, exam: string) => {
      if (poller.current) clearInterval(poller.current);

      poller.current = setInterval(async () => {
        try {
          const { data } = await translationsApi.job(jobId);
          setJob(data);

          if (DONE.includes(data.status)) {
            if (poller.current) clearInterval(poller.current);
            await loadStats(lang, exam);
            setRefreshKey((k) => k + 1);
            if (data.status === "FAILED") setError(data.error?.message ?? "The translation job failed.");
          }
        } catch (e) {
          if (poller.current) clearInterval(poller.current);
          setError(message(e));
        }
      }, 1500);
    },
    [loadStats],
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
        const lang = langs.data.find((l) => l.code === "ta")?.id ?? langs.data[0]?.id ?? "";
        const exam = exs.data[0]?.id ?? "";
        setLanguageId(lang);
        setExamId(exam);
        const loaded = await loadStats(lang, exam);
        // A run started earlier (or by a paper request) is picked up again.
        if (loaded?.running_job_id) watch(loaded.running_job_id, lang, exam);
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) router.replace("/login");
        else setError(message(e));
      }
    })();
  }, [router, loadStats, watch]);

  useEffect(
    () => () => {
      if (poller.current) clearInterval(poller.current);
    },
    [],
  );

  const switchTo = async (lang: string, exam: string) => {
    setLanguageId(lang);
    setExamId(exam);
    setError(null);
    setNote(null);
    setJob(null);
    if (poller.current) clearInterval(poller.current);
    try {
      const loaded = await loadStats(lang, exam);
      if (loaded?.running_job_id) watch(loaded.running_job_id, lang, exam);
    } catch (e) {
      setError(message(e));
    }
  };

  const startTranslation = async () => {
    setError(null);
    setNote(null);
    try {
      const { data } = await translationsApi.start({ language_id: languageId, exam_id: examId, limit: count });
      if (data.reused) setNote("A translation run for this exam is already going; following it.");
      setJob({ id: data.job_id, type: "translation", status: data.status, status_label: data.status, progress: 0, progress_message: null });
      watch(data.job_id, languageId, examId);
    } catch (e) {
      setError(message(e));
    }
  };

  const language = languages.find((l) => l.id === languageId);
  const running = job !== null && !DONE.includes(job.status);

  return (
    <>
      <header className="mb-4 flex flex-wrap items-baseline gap-x-6 gap-y-2">
        <h1 className="text-lg font-semibold text-slate-100">Translations</h1>
        <p className="text-xs text-slate-500">Translate approved questions, review what the checks doubted, keep the glossary</p>
        <span className="ml-auto flex flex-wrap gap-2">
          <select className={FIELD} value={languageId} onChange={(e) => void switchTo(e.target.value, examId)}>
            {languages.map((l) => (
              <option key={l.id} value={l.id}>
                Into {l.name}
              </option>
            ))}
          </select>
          <select className={FIELD} value={examId} onChange={(e) => void switchTo(languageId, e.target.value)}>
            <option value="">All exams</option>
            {exams.map((e) => (
              <option key={e.id} value={e.id}>
                {e.name}
              </option>
            ))}
          </select>
        </span>
      </header>

      {error && (
        <p className="mb-4 rounded-lg border border-red-900 bg-red-950/60 px-4 py-3 text-sm text-red-300">{error}</p>
      )}
      {note && (
        <p className="mb-4 rounded-lg border border-emerald-900 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-300">
          {note}
        </p>
      )}

      {stats && (
        <section className={`${PANEL} mb-4 p-4`}>
          <div className="flex flex-wrap items-end gap-6">
            <Stat label="Approved questions" value={stats.approved} />
            <Stat label={`Readable in ${stats.language.name}`} value={stats.available} />
            <Stat label="Coverage" value={`${Math.round(stats.coverage * 100)}%`} />
            <Stat label="Still to translate" value={stats.needs_translation} />
            <div className="ml-auto flex flex-wrap gap-3 text-xs">
              {(Object.keys(stats.by_status) as TranslationStatus[])
                .filter((s) => s !== "MACHINE")
                .map((s) => (
                  <span key={s} className={STATUS_STYLE[s]}>
                    {s.replace("_", " ").toLowerCase()} <span className="font-mono">{stats.by_status[s]}</span>
                  </span>
                ))}
            </div>
          </div>

          <div className="mt-3 h-1.5 overflow-hidden rounded bg-slate-800">
            <div className="h-full bg-emerald-500" style={{ width: `${Math.round(stats.coverage * 100)}%` }} />
          </div>

          {!stats.has_prompt ? (
            <p className="mt-3 text-sm text-amber-300">
              There is no translation prompt for {stats.language.name} yet, so nothing can be translated into it.
            </p>
          ) : !examId ? (
            <p className="mt-3 text-xs text-slate-500">Choose an exam to translate its approved questions.</p>
          ) : (
            <div className="mt-3 flex flex-wrap items-center gap-2">
              <input
                type="number"
                min={1}
                max={1000}
                className={`${FIELD} w-24 text-right`}
                value={count}
                onChange={(e) => setCount(Math.max(0, Math.min(1000, Number(e.target.value) || 0)))}
              />
              <button className={BUTTON} disabled={running || count < 1} onClick={() => void startTranslation()}>
                Translate {count} approved question{count === 1 ? "" : "s"}
              </button>
              <span className="text-xs text-slate-500">
                Best first, ten per model call. Ones already usable or waiting for review are skipped.
              </span>
            </div>
          )}

          {job && <JobProgress job={job} />}
        </section>
      )}

      <nav className="mb-3 flex gap-1 text-sm">
        {(["review", "glossary"] as const).map((t) => (
          <button
            key={t}
            onClick={() => setTab(t)}
            className={`rounded-md px-3 py-1.5 ${
              tab === t ? "bg-slate-800 text-slate-100" : "text-slate-400 hover:bg-slate-800/60"
            }`}
          >
            {t === "review" ? "Review queue" : "Glossary"}
          </button>
        ))}
      </nav>

      {tab === "review" && languageId && (
        <ReviewQueue
          key={`${languageId}:${examId}:${refreshKey}`}
          languageId={languageId}
          examId={examId}
          languageName={language?.name ?? ""}
          onDecided={() => void loadStats(languageId, examId)}
          onError={setError}
        />
      )}
      {tab === "glossary" && <GlossaryPanel languages={languages} defaultTargetId={languageId} onError={setError} />}
    </>
  );
}

function Stat({ label, value }: { label: string; value: number | string }) {
  return (
    <div>
      <p className="text-[11px] uppercase tracking-wide text-slate-500">{label}</p>
      <p className="font-mono text-xl text-slate-100">{value}</p>
    </div>
  );
}

function JobProgress({ job }: { job: TranslationJob }) {
  const out = job.output;

  return (
    <div className="mt-4 rounded-lg border border-slate-800 bg-slate-950/60 p-3 text-sm">
      <div className="flex items-center gap-3">
        <span className="text-slate-300">{job.status_label}</span>
        <div className="h-1.5 flex-1 overflow-hidden rounded bg-slate-800">
          <div className="h-full bg-sky-500 transition-all" style={{ width: `${job.progress}%` }} />
        </div>
        <span className="font-mono text-xs text-slate-400">{job.progress}%</span>
      </div>
      {job.progress_message && <p className="mt-1 text-xs text-slate-500">{job.progress_message}</p>}
      {out && (
        <p className="mt-2 text-xs text-slate-300">
          {out.translated ?? 0} translated · <span className="text-emerald-300">{out.validated ?? 0} validated</span> ·{" "}
          <span className="text-amber-300">{out.needs_review ?? 0} need review</span> · {out.failed ?? 0} failed ·{" "}
          {out.skipped ?? 0} skipped
        </p>
      )}
      {out?.errors?.map((e) => (
        <p key={e} className="mt-1 text-xs text-red-300">
          {e}
        </p>
      ))}
    </div>
  );
}

// ------------------------------------------------------------ review queue

function ReviewQueue({
  languageId,
  examId,
  languageName,
  onDecided,
  onError,
}: {
  languageId: string;
  examId: string;
  languageName: string;
  onDecided: () => void;
  onError: (message: string | null) => void;
}) {
  const [status, setStatus] = useState<TranslationStatus>("NEEDS_REVIEW");
  const [items, setItems] = useState<TranslationReviewItem[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);

  const load = useCallback(
    async (offset: number) => {
      const res = await translationsApi.review({
        language_id: languageId,
        status,
        exam_id: examId || undefined,
        limit: 20,
        offset,
      });
      setItems((prev) => (offset === 0 ? res.data : [...prev, ...res.data]));
      setTotal(res.meta.total);
    },
    [languageId, examId, status],
  );

  useEffect(() => {
    (async () => {
      setLoading(true);
      try {
        await load(0);
      } catch (e) {
        onError(message(e));
      } finally {
        setLoading(false);
      }
    })();
  }, [load, onError]);

  const decided = (id: string) => {
    setItems((prev) => prev.filter((i) => i.id !== id));
    setTotal((t) => Math.max(0, t - 1));
    onDecided();
  };

  return (
    <section className={PANEL}>
      <div className="flex flex-wrap items-center gap-3 border-b border-slate-800 px-4 py-2.5">
        <h2 className="text-sm font-semibold text-slate-100">
          {total} {status.replace("_", " ").toLowerCase()} in {languageName}
        </h2>
        <select
          className={`ml-auto ${FIELD}`}
          value={status}
          onChange={(e) => setStatus(e.target.value as TranslationStatus)}
        >
          {(["NEEDS_REVIEW", "AI_VALIDATED", "HUMAN_APPROVED", "REJECTED", "STALE"] as TranslationStatus[]).map((s) => (
            <option key={s} value={s}>
              {s.replace("_", " ").toLowerCase()}
            </option>
          ))}
        </select>
      </div>

      {loading && <p className="px-4 py-8 text-center text-sm text-slate-500">Loading…</p>}
      {!loading && items.length === 0 && (
        <p className="px-4 py-8 text-center text-sm text-slate-500">Nothing here.</p>
      )}

      <ul className="divide-y divide-slate-800">
        {items.map((item) => (
          <ReviewCard key={item.id} item={item} onDone={() => decided(item.id)} onError={onError} />
        ))}
      </ul>

      {items.length < total && (
        <div className="border-t border-slate-800 px-4 py-3 text-center">
          <button className={GHOST} onClick={() => void load(items.length).catch((e) => onError(message(e)))}>
            Load more ({total - items.length} left)
          </button>
        </div>
      )}
    </section>
  );
}

function ReviewCard({
  item,
  onDone,
  onError,
}: {
  item: TranslationReviewItem;
  onDone: () => void;
  onError: (message: string | null) => void;
}) {
  const [text, setText] = useState(item.translation.question_text ?? "");
  const [options, setOptions] = useState<Record<string, string>>({ ...item.translation.options });
  const [explanation, setExplanation] = useState(item.translation.explanation ?? "");
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);

  const checks = item.validation_report.checks ?? {};
  const editable = item.status !== "STALE";

  const edits = (): TranslationEdits | undefined => {
    const out: TranslationEdits = {};
    if (text !== (item.translation.question_text ?? "")) out.question_text = text;
    const changed = KEYS.filter((k) => (options[k] ?? "") !== (item.translation.options[k] ?? ""));
    if (changed.length > 0) out.options = Object.fromEntries(changed.map((k) => [k, options[k] ?? ""]));
    if (explanation !== (item.translation.explanation ?? "")) out.explanation = explanation || null;
    return Object.keys(out).length > 0 ? out : undefined;
  };

  const decide = async (decision: "approve" | "reject") => {
    setBusy(true);
    onError(null);
    try {
      await translationsApi.decide(item.id, {
        decision,
        reason: reason.trim() || undefined,
        edits: decision === "approve" ? edits() : undefined,
      });
      onDone();
    } catch (e) {
      onError(message(e));
      setBusy(false);
    }
  };

  return (
    <li className="px-4 py-4">
      <div className="mb-2 flex flex-wrap items-center gap-2 text-xs">
        <span className={STATUS_STYLE[item.status]}>{item.status.replace("_", " ").toLowerCase()}</span>
        <span className="text-slate-500">
          {item.source_language} → {item.language} · v{item.translation_version}
          {item.quality_score !== null && ` · quality ${Math.round(item.quality_score)}`}
        </span>
        {item.failures.map((code) => (
          <span key={code} className="rounded bg-amber-950/70 px-1.5 py-0.5 text-amber-200" title={code}>
            {FAILURE_LABELS[code] ?? code}
          </span>
        ))}
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <div>
          <p className="mb-1 text-[11px] uppercase tracking-wide text-slate-500">Source</p>
          <p className="whitespace-pre-wrap text-sm text-slate-200">{item.source.question_text}</p>
          <ol className="mt-2 space-y-1 text-sm">
            {KEYS.map((k) => (
              <li key={k} className={k === item.correct_option ? "text-emerald-300" : "text-slate-300"}>
                <span className="font-mono text-slate-500">{k}.</span> {item.source.options[k]}
                {k === item.correct_option && <span className="ml-2 text-[10px] uppercase">key</span>}
              </li>
            ))}
          </ol>
          {item.source.explanation && (
            <p className="mt-2 whitespace-pre-wrap text-xs text-slate-500">{item.source.explanation}</p>
          )}
        </div>

        <div>
          <p className="mb-1 text-[11px] uppercase tracking-wide text-slate-500">Translation</p>
          <textarea
            className={`${FIELD} h-24 w-full`}
            value={text}
            disabled={!editable}
            onChange={(e) => setText(e.target.value)}
          />
          <div className="mt-2 space-y-1">
            {KEYS.map((k) => (
              <label key={k} className="flex items-center gap-2">
                <span className={`w-4 font-mono text-xs ${k === item.correct_option ? "text-emerald-300" : "text-slate-500"}`}>
                  {k}
                </span>
                <input
                  className={`${FIELD} flex-1`}
                  value={options[k] ?? ""}
                  disabled={!editable}
                  onChange={(e) => setOptions((prev) => ({ ...prev, [k]: e.target.value }))}
                />
              </label>
            ))}
          </div>
          <textarea
            className={`${FIELD} mt-2 h-16 w-full text-xs`}
            placeholder="Explanation"
            value={explanation}
            disabled={!editable}
            onChange={(e) => setExplanation(e.target.value)}
          />
        </div>
      </div>

      <CheckDetails checks={checks} report={item.validation_report} />

      {editable ? (
        <div className="mt-3 flex flex-wrap items-center gap-2">
          <input
            className={`${FIELD} min-w-64 flex-1`}
            placeholder="Reason (optional)"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
          <button className={GHOST} disabled={busy} onClick={() => void decide("reject")}>
            Reject
          </button>
          <button className={BUTTON} disabled={busy} onClick={() => void decide("approve")}>
            {edits() ? "Approve with edits" : "Approve"}
          </button>
        </div>
      ) : (
        <p className="mt-3 text-xs text-slate-500">
          The master question changed after this was translated. Translate it again rather than approving the old text.
        </p>
      )}
    </li>
  );
}

function CheckDetails({
  checks,
  report,
}: {
  checks: Record<string, TranslationCheck>;
  report: TranslationReviewItem["validation_report"];
}) {
  const lines: string[] = [];
  const v1 = checks.V1;
  const v2 = checks.V2;
  const v3 = checks.V3;
  const v4 = checks.V4;
  const v5 = checks.V5;

  if (v1 && !v1.passed) lines.push(...(v1.problems ?? []));
  if (v2 && !v2.passed)
    lines.push(...(v2.missing ?? []).map((m) => `Board term for “${m.source}” should be “${m.expected}”.`));
  if (v3 && !v3.passed)
    lines.push(`Meaning score ${v3.meaning_score ?? "—"} is below the 0.85 needed.`);
  if (v4 && !v4.passed)
    lines.push(`A blind solver picked ${v4.model_choice ?? "nothing"}; the key is ${v4.expected ?? "—"}.`);
  if (v5 && !v5.passed) lines.push(...(v5.problems ?? []));

  if (lines.length === 0 && !report.back_translation && !(report.notes ?? []).length) return null;

  return (
    <div className="mt-3 rounded-lg bg-slate-950/60 px-3 py-2 text-xs">
      {lines.map((l) => (
        <p key={l} className="text-amber-200">
          {l}
        </p>
      ))}
      {report.back_translation && (
        <p className="mt-1 text-slate-400">
          <span className="text-slate-500">Back-translation:</span> {report.back_translation}
        </p>
      )}
      {(report.notes ?? []).map((n) => (
        <p key={n} className="mt-1 text-slate-500">
          {n}
        </p>
      ))}
    </div>
  );
}

// ---------------------------------------------------------------- glossary

function GlossaryPanel({
  languages,
  defaultTargetId,
  onError,
}: {
  languages: Language[];
  defaultTargetId: string;
  onError: (message: string | null) => void;
}) {
  const english = languages.find((l) => l.code === "en")?.id ?? "";
  const [sourceId, setSourceId] = useState(english);
  const [targetId, setTargetId] = useState(defaultTargetId !== english ? defaultTargetId : (languages.find((l) => l.code === "ta")?.id ?? ""));
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [kind, setKind] = useState<"" | "term" | "name">("");
  const [includeInactive, setIncludeInactive] = useState(false);
  const [rows, setRows] = useState<GlossaryEntry[]>([]);
  const [total, setTotal] = useState(0);
  const [editing, setEditing] = useState<string | null>(null);
  const [draft, setDraft] = useState({ source_term: "", target_term: "", kind: "term" as "term" | "name", domain: "" });
  const [importing, setImporting] = useState(false);
  const [csv, setCsv] = useState("");
  const [result, setResult] = useState<string | null>(null);

  const load = useCallback(async () => {
    const res = await glossaryApi.list({
      source_language_id: sourceId || undefined,
      target_language_id: targetId || undefined,
      q: query || undefined,
      kind: kind || undefined,
      include_inactive: includeInactive,
      limit: 200,
    });
    setRows(res.data);
    setTotal(res.meta.total);
  }, [sourceId, targetId, query, kind, includeInactive]);

  useEffect(() => {
    (async () => {
      try {
        await load();
      } catch (e) {
        onError(message(e));
      }
    })();
  }, [load, onError]);

  const run = async (action: () => Promise<unknown>) => {
    onError(null);
    try {
      await action();
      await load();
    } catch (e) {
      onError(message(e));
    }
  };

  return (
    <section className={PANEL}>
      <div className="flex flex-wrap items-center gap-2 border-b border-slate-800 px-4 py-2.5">
        <select className={FIELD} value={sourceId} onChange={(e) => setSourceId(e.target.value)}>
          {languages.map((l) => (
            <option key={l.id} value={l.id}>
              {l.name}
            </option>
          ))}
        </select>
        <span className="text-slate-500">→</span>
        <select className={FIELD} value={targetId} onChange={(e) => setTargetId(e.target.value)}>
          {languages.map((l) => (
            <option key={l.id} value={l.id}>
              {l.name}
            </option>
          ))}
        </select>
        <form
          className="flex flex-1 gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            setQuery(search.trim());
          }}
        >
          <input
            className={`${FIELD} min-w-48 flex-1`}
            placeholder="Search either side, Enter"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </form>
        <select className={FIELD} value={kind} onChange={(e) => setKind(e.target.value as "" | "term" | "name")}>
          <option value="">Terms and names</option>
          <option value="term">Terms</option>
          <option value="name">Names</option>
        </select>
        <label className="flex items-center gap-1.5 text-xs text-slate-400">
          <input type="checkbox" checked={includeInactive} onChange={(e) => setIncludeInactive(e.target.checked)} />
          Show deactivated
        </label>
        <button className={GHOST} onClick={() => setImporting((v) => !v)}>
          Import CSV…
        </button>
      </div>

      {importing && (
        <div className="border-b border-slate-800 p-4">
          <p className="mb-2 text-xs text-slate-500">
            One entry per line: <span className="font-mono">source,target[,kind[,domain]]</span>. Kind is “term” or
            “name”; quote a field that contains a comma. Existing entries are updated, not duplicated.
          </p>
          <textarea
            className={`${FIELD} h-36 w-full font-mono text-xs`}
            value={csv}
            placeholder={"photosynthesis,ஒளிச்சேர்க்கை,term,biology\nCauvery,காவிரி,name,river"}
            onChange={(e) => setCsv(e.target.value)}
          />
          <div className="mt-2 flex items-center gap-3">
            {result && <span className="text-xs text-emerald-300">{result}</span>}
            <button
              className={`ml-auto ${BUTTON}`}
              disabled={!csv.trim() || !sourceId || !targetId}
              onClick={() =>
                void run(async () => {
                  const { data } = await glossaryApi.importCsv({ source_language_id: sourceId, target_language_id: targetId, csv });
                  setResult(
                    `${data.created} created, ${data.updated} updated, ${data.skipped} skipped` +
                      (data.errors.length ? ` — ${data.errors.slice(0, 3).join(" ")}` : ""),
                  );
                  setCsv("");
                })
              }
            >
              Import
            </button>
          </div>
        </div>
      )}

      <div className="flex flex-wrap items-center gap-2 border-b border-slate-800 px-4 py-2.5">
        <input
          className={`${FIELD} min-w-40 flex-1`}
          placeholder="Source term"
          value={draft.source_term}
          onChange={(e) => setDraft({ ...draft, source_term: e.target.value })}
        />
        <input
          className={`${FIELD} min-w-40 flex-1`}
          placeholder="Board's target term"
          value={draft.target_term}
          onChange={(e) => setDraft({ ...draft, target_term: e.target.value })}
        />
        <select
          className={FIELD}
          value={draft.kind}
          onChange={(e) => setDraft({ ...draft, kind: e.target.value as "term" | "name" })}
        >
          <option value="term">term</option>
          <option value="name">name</option>
        </select>
        <input
          className={`${FIELD} w-32`}
          placeholder="domain"
          value={draft.domain}
          onChange={(e) => setDraft({ ...draft, domain: e.target.value })}
        />
        <button
          className={BUTTON}
          disabled={!draft.source_term.trim() || !draft.target_term.trim() || sourceId === targetId}
          onClick={() =>
            void run(async () => {
              await glossaryApi.create({
                source_language_id: sourceId,
                target_language_id: targetId,
                source_term: draft.source_term,
                target_term: draft.target_term,
                kind: draft.kind,
                domain: draft.domain || null,
              });
              setDraft({ source_term: "", target_term: "", kind: draft.kind, domain: draft.domain });
            })
          }
        >
          Add
        </button>
      </div>

      <table className="w-full text-sm">
        <thead className="text-left text-[11px] uppercase tracking-wide text-slate-500">
          <tr>
            <th className="px-4 py-2 font-normal">Source</th>
            <th className="px-4 py-2 font-normal">Target</th>
            <th className="px-4 py-2 font-normal">Kind</th>
            <th className="px-4 py-2 font-normal">Domain</th>
            <th className="px-4 py-2" />
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-800/70">
          {rows.map((row) =>
            editing === row.id ? (
              <GlossaryEditRow
                key={row.id}
                row={row}
                onCancel={() => setEditing(null)}
                onSave={(patch) =>
                  void run(async () => {
                    await glossaryApi.update(row.id, patch);
                    setEditing(null);
                  })
                }
              />
            ) : (
              <tr key={row.id} className={row.is_active ? "" : "opacity-50"}>
                <td className="px-4 py-1.5 text-slate-200">{row.source_term}</td>
                <td className="px-4 py-1.5 text-slate-200">{row.target_term}</td>
                <td className="px-4 py-1.5 text-xs text-slate-400">{row.kind}</td>
                <td className="px-4 py-1.5 text-xs text-slate-400">{row.domain ?? "—"}</td>
                <td className="whitespace-nowrap px-4 py-1.5 text-right text-xs">
                  <button className="text-sky-300 hover:text-sky-200" onClick={() => setEditing(row.id)}>
                    Edit
                  </button>
                  {row.is_active ? (
                    <button
                      className="ml-3 text-red-300 hover:text-red-200"
                      onClick={() => void run(() => glossaryApi.deactivate(row.id))}
                    >
                      Deactivate
                    </button>
                  ) : (
                    <button
                      className="ml-3 text-emerald-300 hover:text-emerald-200"
                      onClick={() => void run(() => glossaryApi.update(row.id, { is_active: true }))}
                    >
                      Reactivate
                    </button>
                  )}
                </td>
              </tr>
            ),
          )}
          {rows.length === 0 && (
            <tr>
              <td colSpan={5} className="px-4 py-8 text-center text-sm text-slate-500">
                No entries.
              </td>
            </tr>
          )}
        </tbody>
      </table>
      {total > rows.length && (
        <p className="border-t border-slate-800 px-4 py-2 text-xs text-slate-500">
          Showing {rows.length} of {total}. Search to narrow it down.
        </p>
      )}
    </section>
  );
}

function GlossaryEditRow({
  row,
  onCancel,
  onSave,
}: {
  row: GlossaryEntry;
  onCancel: () => void;
  onSave: (patch: { source_term: string; target_term: string; kind: "term" | "name"; domain: string | null }) => void;
}) {
  const [source, setSource] = useState(row.source_term);
  const [target, setTarget] = useState(row.target_term);
  const [kind, setKind] = useState(row.kind);
  const [domain, setDomain] = useState(row.domain ?? "");

  return (
    <tr className="bg-slate-950/50">
      <td className="px-4 py-1.5">
        <input className={`${FIELD} w-full`} value={source} onChange={(e) => setSource(e.target.value)} />
      </td>
      <td className="px-4 py-1.5">
        <input className={`${FIELD} w-full`} value={target} onChange={(e) => setTarget(e.target.value)} />
      </td>
      <td className="px-4 py-1.5">
        <select className={FIELD} value={kind} onChange={(e) => setKind(e.target.value as "term" | "name")}>
          <option value="term">term</option>
          <option value="name">name</option>
        </select>
      </td>
      <td className="px-4 py-1.5">
        <input className={`${FIELD} w-28`} value={domain} onChange={(e) => setDomain(e.target.value)} />
      </td>
      <td className="whitespace-nowrap px-4 py-1.5 text-right text-xs">
        <button
          className="text-emerald-300 hover:text-emerald-200"
          disabled={!source.trim() || !target.trim()}
          onClick={() => onSave({ source_term: source, target_term: target, kind, domain: domain.trim() || null })}
        >
          Save
        </button>
        <button className="ml-3 text-slate-400 hover:text-slate-200" onClick={onCancel}>
          Cancel
        </button>
      </td>
    </tr>
  );
}
