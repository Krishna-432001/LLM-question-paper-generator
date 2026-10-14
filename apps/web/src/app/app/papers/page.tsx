"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import {
  PaperApiError,
  practicePapers,
  testsApi,
  type AnswerKeyResult,
  type PaperWarning,
  type PracticePaperReport,
  type PracticePaperRow,
} from "@/lib/api";
import { useI18n, type MessageKey } from "@/lib/i18n";
import { BUTTON, CARD, FIELD, GHOST, errorText } from "../student-context";

/** How often a scanned paper's OCR job is checked. OCR takes minutes, not seconds. */
const POLL_MS = 3000;

/** Queued this long without starting: say the worker may not be running. */
const QUEUED_NOTICE_MS = 60_000;

/** Stop polling after this; the job carries on and the paper shows up in the list. */
const POLL_LIMIT_MS = 30 * 60_000;

/** Server titles are capped at 200 characters; a long file name must fit. */
const TITLE_MAX = 200;

const WARNING_CODES = new Set([
  "pages_unreadable",
  "ocr_unsure",
  "skipped",
  "no_key",
  "partial_key",
  "not_in_language",
  "sections",
  "key_first_section_only",
  "key_sections_mismatch",
]);

const ERROR_CODES = new Set([
  "no_text_reader",
  "scanned_without_ocr",
  "no_questions",
  "no_questions_after_ocr",
  "no_exam",
  "exam_not_available",
  "unreadable_key",
]);

type Translate = (key: MessageKey) => string;

function titleFrom(name: string): string {
  return name.replace(/\.pdf$/i, "").slice(0, TITLE_MAX);
}

/** A warning in the reader's language, from its code; the server's English otherwise. */
function warningText(warning: PaperWarning, t: Translate): string {
  const template = WARNING_CODES.has(warning.code)
    ? t(`papers.warn.${warning.code}` as MessageKey)
    : warning.message;

  return template.replace(/\{(\w+)\}/g, (whole: string, name: string) => {
    const value = warning.params[name];
    if (value === undefined) return whole;
    if (name === "language" && (value === "en" || value === "ta")) return t(`papers.lang.${value}` as MessageKey);
    return String(value);
  });
}

/** A refusal in the reader's language when the server gave a code we know. */
function failureText(e: unknown, t: Translate, code?: string): string {
  const reason = code ?? (e instanceof PaperApiError ? e.code : undefined);
  if (reason && ERROR_CODES.has(reason)) return t(`papers.err.${reason}` as MessageKey);
  return errorText(e, t("papers.failed"));
}

/**
 * Upload a question paper and sit it as a timed test.
 *
 * The upload answers with what it read — how many questions, which were
 * skipped, whether there was a key — before the student commits to the clock,
 * so a paper that half-parsed is noticed before it is sat rather than after.
 * A scanned paper is read on the server's OCR queue; this page polls the job
 * and shows the same report when it finishes.
 */
export default function PapersPage() {
  const { t, locale } = useI18n();
  const router = useRouter();

  const [papers, setPapers] = useState<PracticePaperRow[]>([]);
  const [file, setFile] = useState<File | null>(null);
  const [title, setTitle] = useState("");
  const [minutes, setMinutes] = useState("");
  const [busy, setBusy] = useState(false);
  const [ocr, setOcr] = useState(false);
  const [queuedTooLong, setQueuedTooLong] = useState(false);
  const [report, setReport] = useState<PracticePaperReport | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [keyFor, setKeyFor] = useState<string | null>(null);
  // Polling stops when the student leaves; the job carries on regardless and
  // the paper appears in the list next time.
  const mounted = useRef(true);

  const load = useCallback(async () => {
    setPapers((await practicePapers.list()).data);
  }, []);

  useEffect(() => {
    mounted.current = true;

    void (async () => {
      try {
        await load();
      } catch (e) {
        setError(errorText(e, t("common.error")));
      }
    })();

    return () => {
      mounted.current = false;
    };
  }, [load, t]);

  async function waitForJob(jobId: string): Promise<PracticePaperReport | null> {
    const startedAt = Date.now();

    for (;;) {
      await new Promise((resolve) => setTimeout(resolve, POLL_MS));
      if (!mounted.current) return null;

      const { data } = await practicePapers.job(jobId);
      const output = data.output;

      if (data.status === "COMPLETED" && output && "test_id" in output) return output;
      if (data.status === "FAILED" || data.status === "CANCELLED") {
        const code = output && "error" in output ? output.error.code : undefined;
        throw new Error(failureText(new Error(data.error?.message ?? t("papers.failed")), t, code));
      }

      // Nothing picks up a job when no queue worker runs; polling would
      // otherwise spin forever with no hint why.
      const waited = Date.now() - startedAt;
      if (data.status === "QUEUED" && waited > QUEUED_NOTICE_MS) setQueuedTooLong(true);
      if (waited > POLL_LIMIT_MS) throw new Error(t("papers.timedOut"));
    }
  }

  async function upload(event: FormEvent) {
    event.preventDefault();

    if (!file) {
      setError(t("papers.chooseFile"));
      return;
    }

    setBusy(true);
    setError(null);
    setReport(null);
    setQueuedTooLong(false);

    const form = new FormData();
    form.append("file", file);
    form.append("title", (title.trim() || titleFrom(file.name)).slice(0, TITLE_MAX));
    if (minutes.trim()) form.append("duration_minutes", minutes.trim());
    // Sit it in the interface language when the paper was printed in it.
    form.append("language", locale);

    try {
      const { data } = await practicePapers.upload(form);
      let result: PracticePaperReport | null;

      if ("job_id" in data) {
        setOcr(true);
        result = await waitForJob(data.job_id);
      } else {
        result = data;
      }

      if (!mounted.current || !result) return;
      setReport(result);
      await load();
    } catch (e) {
      if (mounted.current) setError(failureText(e, t));
    } finally {
      if (mounted.current) {
        setBusy(false);
        setOcr(false);
      }
    }
  }

  async function start(testId: string) {
    setError(null);
    try {
      const { data } = await testsApi.start(testId);
      router.push(`/app/take?attempt=${data.attempt_id}`);
    } catch (e) {
      setError(errorText(e, t("common.error")));
    }
  }

  const languageNames = (codes: string[]) =>
    codes.map((code) => (code === "en" || code === "ta" ? t(`papers.lang.${code}` as MessageKey) : code)).join(", ");

  return (
    <div className="space-y-4">
      <header>
        <h1 className="text-xl font-semibold">{t("papers.title")}</h1>
        <p className="mt-1 text-sm text-slate-500">{t("papers.subtitle")}</p>
      </header>

      <form className={`${CARD} space-y-3 p-5`} onSubmit={upload}>
        <label className="block text-sm">
          <span className="mb-1 block text-xs text-slate-500">{t("papers.file")}</span>
          <input
            type="file"
            accept="application/pdf,.pdf"
            className={FIELD}
            onChange={(e) => {
              const chosen = e.target.files?.[0] ?? null;
              setFile(chosen);
              if (chosen && !title.trim()) setTitle(titleFrom(chosen.name));
            }}
          />
        </label>
        <div className="grid gap-3 sm:grid-cols-3">
          <label className="text-sm sm:col-span-2">
            <span className="mb-1 block text-xs text-slate-500">{t("papers.paperTitle")}</span>
            <input className={FIELD} value={title} maxLength={TITLE_MAX} onChange={(e) => setTitle(e.target.value)} />
          </label>
          <label className="text-sm">
            <span className="mb-1 block text-xs text-slate-500">{t("papers.minutes")}</span>
            <input
              className={FIELD}
              inputMode="numeric"
              placeholder={t("papers.minutesHint")}
              value={minutes}
              onChange={(e) => setMinutes(e.target.value.replace(/\D/g, ""))}
            />
          </label>
        </div>
        <button type="submit" className={BUTTON} disabled={busy}>
          {busy ? t("papers.reading") : t("papers.upload")}
        </button>
        {ocr && <p className="rounded-xl bg-indigo-50 px-4 py-3 text-sm text-indigo-800">{t("papers.ocr")}</p>}
        {ocr && queuedTooLong && (
          <p className="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">{t("papers.stillQueued")}</p>
        )}
        {error && <p className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}
      </form>

      {report && (
        <section className={`${CARD} space-y-4 p-5`}>
          <div className="flex flex-wrap items-baseline gap-2">
            <h2 className="font-semibold">{t("papers.report")}</h2>
            <span className="text-sm text-slate-500">{report.title}</span>
          </div>
          <dl className="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <Fact label={t("papers.parsed")} value={`${report.parsed}`} />
            <Fact
              label={t("papers.skipped")}
              value={`${report.skipped}`}
              tone={report.skipped > 0 ? "text-amber-700" : undefined}
            />
            <Fact
              label={t("papers.keyFound")}
              value={report.keyed > 0 ? `${report.keyed}/${report.parsed}` : t("papers.keyMissing")}
              tone={report.keyed === 0 ? "text-amber-700" : "text-emerald-700"}
            />
            <Fact label={t("papers.languages")} value={languageNames(report.languages_detected)} />
            <Fact label={t("papers.pages")} value={`${report.pages}`} />
          </dl>
          {report.warnings.length > 0 && (
            <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
              <p className="font-semibold">{t("papers.warnings")}</p>
              <ul className="mt-1 list-disc space-y-1 pl-5">
                {report.warnings.map((warning) => (
                  <li key={warning.code}>{warningText(warning, t)}</li>
                ))}
              </ul>
            </div>
          )}
          <button className={BUTTON} onClick={() => void start(report.test_id)}>
            {t("papers.start")} · {report.duration_minutes} {t("common.minutes")}
          </button>
        </section>
      )}

      <section className={CARD}>
        <h2 className="px-5 pt-4 font-semibold">{t("papers.mine")}</h2>
        {papers.length === 0 ? (
          <p className="p-5 text-sm text-slate-500">{t("papers.none")}</p>
        ) : (
          <ul className="mt-2 divide-y divide-slate-100">
            {papers.map((paper) => (
              <li key={paper.test_id} className="px-5 py-3.5">
                <div className="flex flex-wrap items-center gap-3">
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{paper.title}</p>
                    <p className="text-xs text-slate-500">
                      {paper.question_count} {t("common.questions")}
                      {paper.duration_minutes ? ` · ${paper.duration_minutes} ${t("common.minutes")}` : ""}
                      {` · ${paper.keyed} ${t("papers.keyed")}`}
                      {paper.attempts > 0 && ` · ${paper.attempts} ${t("papers.attempts")}`}
                      {/* With no key nothing was marked, so a "best" of 0% would be a false claim. */}
                      {paper.keyed > 0 &&
                        paper.best_accuracy !== null &&
                        ` · ${t("papers.best")} ${Math.round(paper.best_accuracy)}%`}
                    </p>
                  </div>
                  <button
                    className={GHOST}
                    onClick={() => setKeyFor(keyFor === paper.test_id ? null : paper.test_id)}
                  >
                    {t("papers.addKey")}
                  </button>
                  {paper.open_attempt_id ? (
                    <a href={`/app/take?attempt=${paper.open_attempt_id}`} className={BUTTON}>
                      {t("papers.resume")}
                    </a>
                  ) : (
                    <button className={BUTTON} onClick={() => void start(paper.test_id)}>
                      {paper.attempts > 0 ? t("papers.retake") : t("papers.start")}
                    </button>
                  )}
                </div>
                {keyFor === paper.test_id && <KeyEditor testId={paper.test_id} onSaved={load} />}
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}

function Fact({ label, value, tone }: { label: string; value: string; tone?: string }) {
  return (
    <div className="rounded-xl border border-slate-200 p-3">
      <dt className="text-xs text-slate-500">{label}</dt>
      <dd className={`mt-1 text-lg font-semibold ${tone ?? ""}`}>{value}</dd>
    </div>
  );
}

/**
 * The answer key typed as it is printed at the back of a paper or in a
 * coaching centre's key: "1-A 2-C 3-B". Numbers are the paper's own, so a
 * question the parser skipped does not shift every answer after it.
 */
function KeyEditor({ testId, onSaved }: { testId: string; onSaved: () => Promise<void> }) {
  const { t } = useI18n();
  const [text, setText] = useState("");
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<AnswerKeyResult | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function save() {
    setBusy(true);
    setError(null);
    try {
      const { data } = await practicePapers.setAnswerKey(testId, text);
      setResult(data);
      await onSaved();
    } catch (e) {
      setError(failureText(e, t));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="mt-3 space-y-2">
      <label className="block text-sm">
        <span className="mb-1 block text-xs text-slate-500">{t("papers.keyHint")}</span>
        <textarea
          className={`${FIELD} min-h-24 font-mono`}
          value={text}
          placeholder="1-A 2-C 3-B 4-D"
          onChange={(e) => setText(e.target.value)}
        />
      </label>
      <button className={BUTTON} disabled={busy || !text.trim()} onClick={() => void save()}>
        {busy ? t("common.loading") : t("papers.saveKey")}
      </button>
      {result && (
        <div className="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
          <p>
            {t("papers.keySaved")}: {result.updated}
            {result.unmatched.length > 0 && ` · ${t("papers.unmatched")}: ${result.unmatched.join(", ")}`}
          </p>
          <p className="mt-1">{t("papers.notRescored")}</p>
        </div>
      )}
      {error && <p className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}
    </div>
  );
}
