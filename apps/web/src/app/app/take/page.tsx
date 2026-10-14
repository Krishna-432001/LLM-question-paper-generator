"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from "react";
import { student, testsApi, type OpenAttempt } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { MultiText, OptionText, optionKeysOf, versionsOf } from "../paper-language";
import { BUTTON, CARD, GHOST, errorText, useQueryParam } from "../student-context";

/**
 * The test runner.
 *
 * Built to survive a bad connection, because that is what most students have:
 * every answer is written to this device as it is chosen, a reload resumes
 * the same attempt, and the only network call that matters is the final
 * submit — which can be retried until it goes through.
 */

interface Saved {
  answers: Record<string, string>;
  review: Record<string, boolean>;
  seconds: Record<string, number>;
  current: number;
}

const EMPTY: Saved = { answers: {}, review: {}, seconds: {}, current: 0 };

function load(key: string): Saved {
  try {
    const raw = window.localStorage.getItem(key);
    return raw ? { ...EMPTY, ...(JSON.parse(raw) as Saved) } : EMPTY;
  } catch {
    return EMPTY;
  }
}

function persist(key: string, value: Saved): void {
  try {
    window.localStorage.setItem(key, JSON.stringify(value));
  } catch {
    // Storage full or private mode: answers still live in memory.
  }
}

function subscribeOnline(callback: () => void): () => void {
  window.addEventListener("online", callback);
  window.addEventListener("offline", callback);
  return () => {
    window.removeEventListener("online", callback);
    window.removeEventListener("offline", callback);
  };
}

export default function TakeTest() {
  const { t } = useI18n();
  const router = useRouter();
  const attemptId = useQueryParam("attempt");

  const [paper, setPaper] = useState<OpenAttempt | null>(null);
  const [saved, setSaved] = useState<Saved>(EMPTY);
  const [remaining, setRemaining] = useState<number | null>(null);
  const online = useSyncExternalStore(subscribeOnline, () => navigator.onLine, () => true);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const submittedRef = useRef(false);
  // The latest answers, readable from the timer without re-creating it every
  // second as the time-per-question counter ticks.
  const savedRef = useRef<Saved>(EMPTY);

  const storageKey = attemptId ? `examforge.attempt.${attemptId}` : "";

  useEffect(() => {
    if (attemptId === undefined) return;
    if (attemptId === null) {
      router.replace("/app/tests");
      return;
    }

    void (async () => {
      try {
        const { data } = await student.attempt(attemptId);
        if (data.status === "submitted") {
          router.replace(`/app/result?attempt=${attemptId}`);
          return;
        }
        const restored = load(`examforge.attempt.${attemptId}`);
        savedRef.current = restored;
        setPaper(data as OpenAttempt);
        setSaved(restored);
      } catch (e) {
        setError(errorText(e, t("common.error")));
      }
    })();
  }, [attemptId, router, t]);

  const update = useCallback(
    (fn: (s: Saved) => Saved) => {
      setSaved((prev) => {
        const next = fn(prev);
        savedRef.current = next;
        if (storageKey) persist(storageKey, next);
        return next;
      });
    },
    [storageKey],
  );

  const submit = useCallback(
    async (auto = false) => {
      if (!paper || submittedRef.current) return;
      if (!auto && !window.confirm(t("take.confirm"))) return;

      submittedRef.current = true;
      setSubmitting(true);
      setError(null);

      const latest = savedRef.current;

      try {
        await testsApi.submit(
          paper.attempt_id,
          paper.questions.map((q) => ({
            test_question_id: q.test_question_id,
            selected_option: latest.answers[q.test_question_id] ?? null,
            time_spent_seconds: Math.min(7200, latest.seconds[q.test_question_id] ?? 0),
          })),
        );
        try {
          window.localStorage.removeItem(storageKey);
        } catch {
          // Nothing to clean up.
        }
        router.replace(`/app/result?attempt=${paper.attempt_id}`);
      } catch (e) {
        // Allow another try: the answers are still on this device.
        submittedRef.current = false;
        setSubmitting(false);
        setError(errorText(e, t("common.error")));
      }
    },
    [paper, storageKey, router, t],
  );

  // Countdown, and time spent on the question on screen.
  useEffect(() => {
    if (!paper) return;

    const tick = () => {
      if (paper.expires_at) {
        const left = Math.max(0, Math.floor((Date.parse(paper.expires_at) - Date.now()) / 1000));
        setRemaining(left);
        if (left === 0) void submit(true);
      }

      const current = paper.questions[savedRef.current.current];
      if (current) {
        update((s) => ({
          ...s,
          seconds: { ...s.seconds, [current.test_question_id]: (s.seconds[current.test_question_id] ?? 0) + 1 },
        }));
      }
    };

    const timer = window.setInterval(tick, 1000);
    return () => window.clearInterval(timer);
  }, [paper, submit, update]);


  if (!paper) {
    return <p className="py-16 text-center text-sm text-slate-500">{error ?? t("common.loading")}</p>;
  }

  const question = paper.questions[saved.current];
  // A bilingual paper shows every question in both of its languages, the
  // primary first and the other beneath it; a single-language paper has one.
  // A language with no text says so rather than showing another language.
  const versions = question ? versionsOf(question, paper.languages) : [];
  const answered = Object.keys(saved.answers).length;
  const total = paper.questions.length;
  const clock =
    remaining === null
      ? null
      : `${Math.floor(remaining / 60)
          .toString()
          .padStart(2, "0")}:${(remaining % 60).toString().padStart(2, "0")}`;
  const go = (index: number) => update((s) => ({ ...s, current: Math.max(0, Math.min(total - 1, index)) }));

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center gap-3">
        <h1 className="font-semibold">{paper.test.title}</h1>
        <span className="text-sm text-slate-500">
          {answered}/{total} {t("take.answered")}
        </span>
        {clock && (
          <span
            className={`ml-auto rounded-xl px-3 py-1.5 font-mono text-sm font-semibold ${
              remaining !== null && remaining < 60 ? "bg-red-50 text-red-700" : "bg-slate-100 text-slate-700"
            }`}
            aria-label={t("take.timeLeft")}
          >
            ⏱ {clock}
          </span>
        )}
      </header>

      {!online && <p className="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">{t("take.offline")}</p>}
      {remaining === 0 && <p className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{t("take.timeUp")}</p>}
      {error && <p className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}

      <div className="grid gap-4 md:grid-cols-[1fr_220px]">
        <section className={`${CARD} p-5`}>
          <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
            {t("take.question")} {saved.current + 1} {t("take.of")} {total}
          </p>
          <p className="mt-3 whitespace-pre-line text-base leading-relaxed">
            <MultiText versions={versions} field="question_text" />
          </p>

          <div className="mt-5 space-y-2">
            {optionKeysOf(versions).map((key) => {
              const chosen = saved.answers[question.test_question_id] === key;
              return (
                <button
                  key={key}
                  onClick={() =>
                    update((s) => ({ ...s, answers: { ...s.answers, [question.test_question_id]: key } }))
                  }
                  className={`flex w-full items-start gap-3 rounded-xl border px-4 py-3 text-left text-sm transition ${
                    chosen ? "border-indigo-500 bg-indigo-50" : "border-slate-200 hover:bg-slate-50"
                  }`}
                >
                  <span
                    className={`grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs font-semibold ${
                      chosen ? "bg-indigo-600 text-white" : "bg-slate-100 text-slate-600"
                    }`}
                  >
                    {key}
                  </span>
                  <span className="pt-0.5">
                    <OptionText versions={versions} optionKey={key} />
                  </span>
                </button>
              );
            })}
          </div>

          <div className="mt-5 flex flex-wrap gap-2">
            <button className={GHOST} onClick={() => go(saved.current - 1)} disabled={saved.current === 0}>
              {t("take.prev")}
            </button>
            <button
              className={GHOST}
              onClick={() =>
                update((s) => ({
                  ...s,
                  review: { ...s.review, [question.test_question_id]: !s.review[question.test_question_id] },
                }))
              }
            >
              {saved.review[question?.test_question_id] ? "★ " : "☆ "}
              {t("take.review")}
            </button>
            <button
              className={GHOST}
              onClick={() =>
                update((s) => {
                  const answers = { ...s.answers };
                  delete answers[question.test_question_id];
                  return { ...s, answers };
                })
              }
            >
              {t("take.clear")}
            </button>
            <button className={`${BUTTON} ml-auto`} onClick={() => go(saved.current + 1)} disabled={saved.current === total - 1}>
              {t("take.next")}
            </button>
          </div>
        </section>

        <aside className={`${CARD} h-fit p-4`}>
          <div className="grid grid-cols-5 gap-1.5">
            {paper.questions.map((q, i) => {
              const isAnswered = saved.answers[q.test_question_id] !== undefined;
              const isReview = saved.review[q.test_question_id];
              return (
                <button
                  key={q.test_question_id}
                  onClick={() => go(i)}
                  className={`h-9 rounded-lg text-xs font-semibold ${
                    i === saved.current ? "ring-2 ring-indigo-500 " : ""
                  }${
                    isReview
                      ? "bg-amber-100 text-amber-800"
                      : isAnswered
                        ? "bg-indigo-600 text-white"
                        : "bg-slate-100 text-slate-600"
                  }`}
                >
                  {i + 1}
                </button>
              );
            })}
          </div>
          <button className={`${BUTTON} mt-4 w-full`} disabled={submitting} onClick={() => void submit(false)}>
            {submitting ? t("common.loading") : t("take.submit")}
          </button>
        </aside>
      </div>
    </div>
  );
}
