"use client";

import { useEffect, useState } from "react";
import { student, type Progress } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { CARD, errorText, useStudent } from "../student-context";

/**
 * Where the student stands: totals, the last 30 days, and every topic's
 * mastery from weakest to strongest — the same numbers that steer their next
 * test and plan.
 */
export default function ProgressPage() {
  const { t } = useI18n();
  const { profile } = useStudent();
  const examId = profile?.target_exam?.id ?? null;
  const [progress, setProgress] = useState<Progress | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!examId) return;
    void (async () => {
      try {
        setProgress((await student.progress(examId, 30)).data);
      } catch (e) {
        setError(errorText(e, t("common.error")));
      }
    })();
  }, [examId, t]);

  if (!progress) return <p className="py-16 text-center text-sm text-slate-500">{error ?? t("common.loading")}</p>;

  const empty = progress.totals.tests === 0;

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">{t("progress.title")}</h1>

      <section className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Stat label={t("progress.tests")} value={progress.totals.tests} />
        <Stat label={t("progress.answered")} value={progress.totals.questions_answered} />
        <Stat
          label={t("progress.accuracy")}
          value={progress.totals.accuracy === null ? "—" : `${Math.round(progress.totals.accuracy)}%`}
        />
        <Stat label={t("home.streak")} value={`🔥 ${progress.streak_days}`} />
      </section>

      {empty ? (
        <p className={`${CARD} p-6 text-sm text-slate-500`}>{t("progress.empty")}</p>
      ) : (
        <>
          <section className={`${CARD} p-5`}>
            <h2 className="font-semibold">{t("progress.trend")}</h2>
            <Trend points={progress.trend} />
          </section>

          <section className={`${CARD} p-5`}>
            <h2 className="font-semibold">{t("progress.mastery")}</h2>
            <ul className="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2">
              {progress.mastery.map((m) => (
                <li key={m.canonical_topic_id} className="text-sm">
                  <div className="flex justify-between gap-2">
                    <span className="truncate">
                      {m.topic}
                      {m.review_due && <span className="ml-1.5 text-[11px] text-sky-700">↻</span>}
                    </span>
                    <span className="shrink-0 text-slate-500">
                      {Math.round(m.mastery * 100)}% · {m.correct}/{m.attempts}
                    </span>
                  </div>
                  <div className="mt-1 h-2 rounded-full bg-slate-100">
                    <div
                      className={`h-2 rounded-full ${m.mastery >= 0.75 ? "bg-emerald-500" : m.mastery >= 0.5 ? "bg-amber-400" : "bg-rose-400"}`}
                      style={{ width: `${m.mastery * 100}%` }}
                    />
                  </div>
                </li>
              ))}
            </ul>
          </section>
        </>
      )}
    </div>
  );
}

function Stat({ label, value }: { label: string; value: string | number }) {
  return (
    <div className={`${CARD} p-4`}>
      <p className="text-xs text-slate-500">{label}</p>
      <p className="mt-1 text-2xl font-semibold">{value}</p>
    </div>
  );
}

/**
 * Daily accuracy as bars; a day with no test is an empty slot rather than a
 * zero, so a rest day does not read as a failed one.
 */
function Trend({ points }: { points: Progress["trend"] }) {
  return (
    <div className="mt-4 flex h-32 items-end gap-[3px]" role="img" aria-label="Accuracy by day">
      {points.map((p) => (
        <div key={p.date} className="flex h-full flex-1 flex-col justify-end" title={`${p.date}: ${p.accuracy ?? "—"}%`}>
          {p.accuracy === null ? (
            <div className="h-1 rounded-sm bg-slate-100" />
          ) : (
            <div
              className={`rounded-sm ${p.accuracy >= 70 ? "bg-emerald-500" : p.accuracy >= 40 ? "bg-amber-400" : "bg-rose-400"}`}
              style={{ height: `${Math.max(4, p.accuracy)}%` }}
            />
          )}
        </div>
      ))}
    </div>
  );
}
