"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { plansApi, testsApi, type PlanDetail, type PlanMode, type PlanSummary } from "@/lib/api";
import { useI18n, type MessageKey } from "@/lib/i18n";
import { BUTTON, CARD, FIELD, GHOST, errorText, useQueryParam, useStudent } from "../student-context";

const MODES: PlanMode[] = ["daily", "three-day", "weekly", "monthly", "model-test"];
const WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

/** Study plans: make one, then work through it day by day. */
export default function PlansPage() {
  const { t } = useI18n();
  const planId = useQueryParam("plan");

  if (planId === undefined) return null;

  return planId ? <PlanView planId={planId} /> : <PlanList t={t} />;
}

function PlanList({ t }: { t: (key: MessageKey) => string }) {
  const router = useRouter();
  const { profile, contentLanguageId } = useStudent();
  const [plans, setPlans] = useState<PlanSummary[]>([]);
  const [mode, setMode] = useState<PlanMode>("weekly");
  const [examDate, setExamDate] = useState("");
  const [hours, setHours] = useState("3");
  const [offDays, setOffDays] = useState<number[]>([0]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => setPlans((await plansApi.list()).data), []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function create() {
    if (!profile?.target_exam || !contentLanguageId) return;
    setBusy(true);
    setError(null);
    try {
      const { data } = await plansApi.create(mode, {
        exam_id: profile.target_exam.id,
        language_id: contentLanguageId,
        exam_date: examDate || undefined,
        hours_per_day: Number(hours) || 3,
        off_days: offDays,
      });
      router.push(`/app/plans?plan=${data.id}`);
      router.refresh();
    } catch (e) {
      setError(errorText(e, t("common.error")));
      setBusy(false);
    }
  }

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">{t("plans.title")}</h1>

      <section className={`${CARD} p-5`}>
        <h2 className="font-semibold">{t("plans.new")}</h2>
        <div className="mt-3 flex flex-wrap gap-2">
          {MODES.map((m) => (
            <button
              key={m}
              onClick={() => setMode(m)}
              className={`rounded-xl border px-3 py-2 text-sm ${
                mode === m ? "border-indigo-500 bg-indigo-50 font-semibold text-indigo-700" : "border-slate-200"
              }`}
            >
              {t(`plans.mode.${m}` as MessageKey)}
            </button>
          ))}
        </div>
        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          <label className="text-sm">
            <span className="mb-1 block text-xs text-slate-500">{t("plans.examDate")}</span>
            <input type="date" className={FIELD} value={examDate} onChange={(e) => setExamDate(e.target.value)} />
          </label>
          <label className="text-sm">
            <span className="mb-1 block text-xs text-slate-500">{t("plans.hours")}</span>
            <input className={FIELD} inputMode="decimal" value={hours} onChange={(e) => setHours(e.target.value)} />
          </label>
          <div className="text-sm">
            <span className="mb-1 block text-xs text-slate-500">{t("plans.offDays")}</span>
            <div className="flex flex-wrap gap-1">
              {WEEKDAYS.map((day, i) => (
                <button
                  key={day}
                  onClick={() => setOffDays(offDays.includes(i) ? offDays.filter((d) => d !== i) : [...offDays, i])}
                  className={`rounded-lg px-2 py-1.5 text-xs ${
                    offDays.includes(i) ? "bg-slate-800 text-white" : "bg-slate-100 text-slate-600"
                  }`}
                >
                  {day}
                </button>
              ))}
            </div>
          </div>
        </div>
        {error && <p className="mt-3 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}
        <button className={`${BUTTON} mt-4`} disabled={busy} onClick={create}>
          {busy ? t("common.loading") : t("plans.create")}
        </button>
      </section>

      <section className={CARD}>
        <ul className="divide-y divide-slate-100">
          {plans.map((plan) => (
            <li key={plan.id} className="flex flex-wrap items-center gap-3 px-5 py-3.5">
              <div className="flex-1">
                <p className="text-sm font-medium">
                  {t(`plans.mode.${plan.mode.replace("_", "-")}` as MessageKey) ?? plan.mode} · {plan.start_date} → {plan.end_date}
                </p>
                <p className="text-xs text-slate-500">
                  {plan.day_count} {t("plans.days")}
                  {plan.coverage_percent !== null && ` · ${Math.round(plan.coverage_percent)}% ${t("plans.coverage")}`}
                </p>
              </div>
              <a href={`/app/plans?plan=${plan.id}`} className={GHOST}>
                {t("plans.open")}
              </a>
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}

function PlanView({ planId }: { planId: string }) {
  const { t } = useI18n();
  const router = useRouter();
  const [plan, setPlan] = useState<PlanDetail | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => setPlan((await plansApi.show(planId)).data), [planId]);

  useEffect(() => {
    void (async () => {
      try {
        await load();
      } catch (e) {
        setError(errorText(e, t("common.error")));
      }
    })();
  }, [load, t]);

  async function dayTest(dayId: string) {
    try {
      const { data } = await plansApi.dayTest(planId, dayId);
      const { data: attempt } = await testsApi.start(data.test_id);
      router.push(`/app/take?attempt=${attempt.attempt_id}`);
    } catch (e) {
      setError(errorText(e, t("tests.noQuestions")));
    }
  }

  if (!plan) return <p className="py-16 text-center text-sm text-slate-500">{error ?? t("common.loading")}</p>;

  const today = new Date().toISOString().slice(0, 10);

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-baseline gap-3">
        <h1 className="text-xl font-semibold">
          {t(`plans.mode.${plan.mode.replace("_", "-")}` as MessageKey)} · {plan.start_date} → {plan.end_date}
        </h1>
        <a href="/app/plans" className={`${GHOST} ml-auto`}>
          {t("plans.title")}
        </a>
      </header>
      {error && <p className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}

      {plan.days.map((day) => (
        <section
          key={day.day_number}
          className={`${CARD} p-4 ${day.date === today ? "ring-2 ring-indigo-400" : ""}`}
        >
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="font-semibold">
              {day.date} <span className="text-xs font-normal text-slate-500">· {day.role}</span>
            </h2>
            <span className="ml-auto text-xs text-slate-500">
              {Math.round(day.completion_pct ?? 0)}% · {day.target_minutes} {t("common.minutes")}
            </span>
          </div>
          <ul className="mt-2 space-y-1.5">
            {day.items.map((item) => (
              <li key={item.id} className="flex items-center gap-3 text-sm">
                <input
                  type="checkbox"
                  className="h-4 w-4 accent-indigo-600"
                  checked={item.completed_at !== null}
                  disabled={item.completed_at !== null}
                  onChange={async () => {
                    await plansApi.complete(plan.id, item.id);
                    await load();
                  }}
                  aria-label={t("plans.markDone")}
                />
                <span className={`flex-1 ${item.completed_at ? "text-slate-400 line-through" : ""}`}>
                  {item.topic ?? item.type}
                  {item.is_revision && <span className="ml-2 text-[11px] text-sky-700">{t("plans.revision")}</span>}
                </span>
                <span className="text-xs text-slate-400">{item.minutes}′</span>
              </li>
            ))}
          </ul>
          {day.id && day.items.some((i) => i.type === "test") && (
            <button className={`${GHOST} mt-3`} onClick={() => void dayTest(day.id!)}>
              {t("plans.dayTest")}
            </button>
          )}
        </section>
      ))}
    </div>
  );
}
