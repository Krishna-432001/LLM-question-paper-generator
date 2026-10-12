"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import {
  plansApi,
  student,
  testsApi,
  type AttemptRow,
  type PlanDay,
  type PlanSummary,
  type Progress,
} from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { BUTTON, CARD, GHOST, errorText, useStudent } from "./student-context";

export default function StudentHome() {
  const { t } = useI18n();
  const router = useRouter();
  const { profile, contentLanguageId } = useStudent();
  const examId = profile?.target_exam?.id ?? null;

  const [plan, setPlan] = useState<PlanSummary | null>(null);
  const [today, setToday] = useState<PlanDay | null>(null);
  const [recent, setRecent] = useState<AttemptRow[]>([]);
  const [progress, setProgress] = useState<Progress | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [starting, setStarting] = useState(false);

  useEffect(() => {
    if (!examId) return;

    void (async () => {
      try {
        const [plans, attempts, prog] = await Promise.all([
          plansApi.list(),
          student.attempts(examId, 5),
          student.progress(examId, 30),
        ]);
        setRecent(attempts.data);
        setProgress(prog.data);

        const active = plans.data.find((p) => p.status === "active") ?? plans.data[0] ?? null;
        setPlan(active);
        if (active) setToday((await plansApi.today(active.id)).data);
      } catch (e) {
        setError(errorText(e, t("common.error")));
      }
    })();
  }, [examId, t]);

  async function quickTest() {
    if (!examId || !contentLanguageId) return;
    setStarting(true);
    setError(null);
    try {
      const { data: test } = await testsApi.create({
        exam_id: examId,
        language_id: contentLanguageId,
        type: "topic_practice",
        question_count: 20,
        title: t("home.quickTest"),
        duration_minutes: 20,
      });
      const { data: attempt } = await testsApi.start(test.id);
      router.push(`/app/take?attempt=${attempt.attempt_id}`);
    } catch (e) {
      setError(errorText(e, t("tests.noQuestions")));
      setStarting(false);
    }
  }

  async function complete(itemId: string) {
    if (!plan) return;
    await plansApi.complete(plan.id, itemId);
    setToday((await plansApi.today(plan.id)).data);
  }

  return (
    <div className="space-y-4">
      <section className="flex flex-wrap items-end gap-3">
        <div>
          <p className="text-sm text-slate-500">{t("home.greeting")}</p>
          <h1 className="text-2xl font-semibold">{profile?.name}</h1>
        </div>
        {progress && (
          <span className="ml-auto rounded-full bg-amber-50 px-3 py-1.5 text-sm font-semibold text-amber-700">
            🔥 {progress.streak_days} {t("home.streak")}
          </span>
        )}
      </section>

      {error && <p className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}

      <div className="grid gap-4 md:grid-cols-[1.4fr_1fr]">
        <section className={`${CARD} p-5`}>
          <h2 className="font-semibold">{t("home.today")}</h2>
          {!plan ? (
            <div className="mt-3">
              <p className="text-sm text-slate-500">{t("home.noPlan")}</p>
              <a href="/app/plans" className={`${GHOST} mt-3`}>
                {t("home.makePlan")}
              </a>
            </div>
          ) : !today ? (
            <p className="mt-3 text-sm text-slate-500">—</p>
          ) : (
            <ul className="mt-3 space-y-2">
              {today.items.map((item) => (
                <li key={item.id} className="flex items-center gap-3 rounded-xl border border-slate-200 px-3 py-2.5">
                  <input
                    type="checkbox"
                    checked={item.completed_at !== null}
                    disabled={item.completed_at !== null}
                    onChange={() => void complete(item.id)}
                    className="h-5 w-5 accent-indigo-600"
                    aria-label={t("plans.markDone")}
                  />
                  <span className="flex-1 text-sm">
                    {item.topic ?? item.type}
                    {item.is_revision && (
                      <span className="ml-2 rounded bg-sky-50 px-1.5 py-0.5 text-[11px] text-sky-700">
                        {t("plans.revision")}
                      </span>
                    )}
                  </span>
                  <span className="text-xs text-slate-500">
                    {item.minutes} {t("common.minutes")}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className={`${CARD} flex flex-col p-5`}>
          <h2 className="font-semibold">{t("home.quickTest")}</h2>
          <p className="mt-1 text-sm text-slate-500">{t("home.quickTestHint")}</p>
          <button className={`${BUTTON} mt-auto`} disabled={starting} onClick={quickTest}>
            {starting ? t("common.loading") : t("tests.create")}
          </button>
        </section>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <section className={`${CARD} p-5`}>
          <h2 className="font-semibold">{t("home.recent")}</h2>
          {recent.length === 0 ? (
            <p className="mt-3 text-sm text-slate-500">{t("home.noResults")}</p>
          ) : (
            <ul className="mt-3 divide-y divide-slate-100">
              {recent.map((a) => (
                <li key={a.attempt_id}>
                  <a
                    href={a.status === "submitted" ? `/app/result?attempt=${a.attempt_id}` : `/app/take?attempt=${a.attempt_id}`}
                    className="flex items-center gap-3 py-2.5 text-sm hover:text-indigo-700"
                  >
                    <span className="flex-1 truncate">{a.title}</span>
                    {a.accuracy !== null ? (
                      <span className="font-semibold">{Math.round(a.accuracy)}%</span>
                    ) : (
                      <span className="text-xs text-amber-600">{t("tests.resume")}</span>
                    )}
                  </a>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className={`${CARD} p-5`}>
          <h2 className="font-semibold">{t("home.weak")}</h2>
          {!progress || progress.weak_topics.length === 0 ? (
            <p className="mt-3 text-sm text-slate-500">{t("home.noWeak")}</p>
          ) : (
            <ul className="mt-3 space-y-2">
              {progress.weak_topics.map((m) => (
                <li key={m.canonical_topic_id} className="text-sm">
                  <div className="flex justify-between">
                    <span>{m.topic}</span>
                    <span className="text-slate-500">{Math.round(m.mastery * 100)}%</span>
                  </div>
                  <div className="mt-1 h-1.5 rounded-full bg-slate-100">
                    <div className="h-1.5 rounded-full bg-rose-400" style={{ width: `${m.mastery * 100}%` }} />
                  </div>
                </li>
              ))}
            </ul>
          )}
          {progress && progress.review_due > 0 && (
            <p className="mt-3 text-xs text-sky-700">
              {progress.review_due} {t("home.reviewDue")}
            </p>
          )}
        </section>
      </div>

      <p className="text-center text-xs text-slate-400">{t("home.disclaimer")}</p>
    </div>
  );
}
