"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { student, testsApi, type MasteryBand, type MockResult, type Progress } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { PaperLanguagePicker, usePaperLanguage } from "../paper-language";
import { BUTTON, CARD, errorText, useStudent } from "../student-context";

/** Bar and chip colours per study band. */
const BAND: Record<MasteryBand, { bar: string; chip: string }> = {
  red: { bar: "bg-rose-500", chip: "bg-rose-50 text-rose-800 ring-rose-200" },
  orange: { bar: "bg-orange-400", chip: "bg-orange-50 text-orange-800 ring-orange-200" },
  yellow: { bar: "bg-amber-300", chip: "bg-amber-50 text-amber-800 ring-amber-200" },
  green: { bar: "bg-emerald-500", chip: "bg-emerald-50 text-emerald-800 ring-emerald-200" },
};

/**
 * Where the student stands: totals, the last 30 days, and every topic's
 * mastery from weakest to strongest — the same numbers that steer their next
 * test and plan.
 */
export default function ProgressPage() {
  const { t } = useI18n();
  const router = useRouter();
  const { profile } = useStudent();
  const paperLanguage = usePaperLanguage();
  const examId = profile?.target_exam?.id ?? null;
  const [progress, setProgress] = useState<Progress | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [recoveryError, setRecoveryError] = useState<string | null>(null);

  /** An error-recovery test of the mistakes due now, started straight away. */
  async function startRecovery() {
    if (!examId || !paperLanguage.request) return;
    setBusy(true);
    setRecoveryError(null);
    try {
      const { data: test } = await testsApi.createErrorRecovery({ exam_id: examId, ...paperLanguage.request });
      const { data: attempt } = await testsApi.start(test.id);
      router.push(`/app/take?attempt=${attempt.attempt_id}`);
    } catch (e) {
      setRecoveryError(errorText(e, t("common.error")));
      setBusy(false);
    }
  }

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
          <section className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {(["red", "orange", "yellow", "green"] as const).map((b) => (
              <div key={b} className={`rounded-xl p-4 ring-1 ${BAND[b].chip}`}>
                <p className="text-xs">{t(`progress.band.${b}`)}</p>
                <p className="mt-1 text-2xl font-semibold">{progress.bands[b]}</p>
                <p className="mt-0.5 text-[11px] opacity-80">{t(`progress.bandAction.${b}`)}</p>
              </div>
            ))}
          </section>

          <section className={`${CARD} p-5`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h2 className="font-semibold">{t("progress.mistakes")}</h2>
                <p className="mt-1 text-sm text-slate-600">
                  {t("progress.mistakesLine")
                    .replace("{due}", String(progress.mistakes.due))
                    .replace("{open}", String(progress.mistakes.open))}
                </p>
                <p className="mt-0.5 text-xs text-slate-500">
                  {t("progress.repeated")}:{" "}
                  {progress.mistakes.repeated_rate === null
                    ? "—"
                    : `${progress.mistakes.repeated_rate}% (${progress.mistakes.repeated}/${progress.mistakes.ever_wrong})`}
                </p>
              </div>
              <div className="flex flex-col items-end gap-2">
                <PaperLanguagePicker value={paperLanguage.choice} onChange={paperLanguage.setChoice} />
                <button
                  type="button"
                  className={BUTTON}
                  disabled={busy || progress.mistakes.due < 5 || !paperLanguage.request}
                  onClick={() => void startRecovery()}
                >
                  {t("progress.startRecovery")}
                </button>
              </div>
            </div>
            {progress.mistakes.due < 5 && progress.mistakes.open > 0 && (
              <p className="mt-2 text-xs text-slate-500">{t("progress.recoveryWait")}</p>
            )}
            {recoveryError && <p className="mt-2 text-sm text-rose-700">{recoveryError}</p>}
          </section>

          {progress.mocks.length > 0 && (
            <section className={`${CARD} p-5`}>
              <h2 className="font-semibold">{t("progress.mocks")}</h2>
              <Mocks mocks={progress.mocks} />
            </section>
          )}

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
                      <span className={`mr-1.5 rounded px-1.5 py-0.5 text-[10px] ring-1 ${BAND[m.band].chip}`}>
                        {t(`progress.band.${m.band}`)}
                      </span>
                      {Math.round(m.mastery * 100)}% · {m.correct}/{m.attempts}
                    </span>
                  </div>
                  <div className="mt-1 h-2 rounded-full bg-slate-100">
                    <div
                      className={`h-2 rounded-full ${BAND[m.band].bar}`}
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

/**
 * Each full mock in order: score as a share of the paper, with every part's
 * marks, and the qualifying part (General Tamil) marked pass or short.
 */
function Mocks({ mocks }: { mocks: MockResult[] }) {
  const { t } = useI18n();
  return (
    <div className="mt-3 overflow-x-auto">
      <table className="w-full min-w-[520px] text-sm">
        <thead className="text-left text-xs text-slate-500">
          <tr>
            <th className="py-1 pr-3 font-normal">{t("progress.mockDate")}</th>
            <th className="py-1 pr-3 font-normal">{t("progress.mockScore")}</th>
            <th className="py-1 pr-3 font-normal">{t("progress.accuracy")}</th>
            <th className="py-1 font-normal">{t("progress.mockParts")}</th>
          </tr>
        </thead>
        <tbody>
          {mocks.map((m) => (
            <tr key={m.attempt_id} className="border-t border-slate-100 align-top">
              <td className="whitespace-nowrap py-2 pr-3">{m.submitted_at.slice(0, 10)}</td>
              <td className="whitespace-nowrap py-2 pr-3">
                <span className="font-medium">{m.score}</span>
                <span className="text-slate-500"> / {m.max_marks}</span>
                {m.percent !== null && <span className="ml-1 text-xs text-slate-500">({m.percent}%)</span>}
              </td>
              <td className="py-2 pr-3">{m.accuracy === null ? "—" : `${m.accuracy}%`}</td>
              <td className="py-2 text-xs">
                {m.sections.map((s) => (
                  <span key={s.name} className="mr-3 inline-block">
                    {s.name}: {s.marks ?? "—"}/{s.max_marks ?? "—"}
                    {s.qualified !== null && (
                      <span className={s.qualified ? "ml-1 text-emerald-700" : "ml-1 text-rose-700"}>
                        {s.qualified ? "✓" : `✗ ${t("progress.needs")} ${s.qualifying_marks}`}
                      </span>
                    )}
                  </span>
                ))}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
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
