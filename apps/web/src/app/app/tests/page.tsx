"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { student, testsApi, type MyTestRow } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { PaperLanguagePicker, languageLabel, usePaperLanguage } from "../paper-language";
import { BUTTON, CARD, FIELD, GHOST, errorText, useStudent } from "../student-context";

const TYPES = ["topic_practice", "daily", "weekly", "model", "diagnostic"] as const;

/** The student's tests: resume, retake, see results, or make a new one. */
export default function TestsPage() {
  const { t } = useI18n();
  const router = useRouter();
  const { profile } = useStudent();
  const examId = profile?.target_exam?.id ?? null;
  const paperLanguage = usePaperLanguage();

  const [tests, setTests] = useState<MyTestRow[]>([]);
  const [form, setForm] = useState({ type: "topic_practice", count: "20", minutes: "20" });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!examId) return;
    setTests((await student.tests(examId)).data);
  }, [examId]);

  useEffect(() => {
    void (async () => {
      try {
        await load();
      } catch (e) {
        setError(errorText(e, t("common.error")));
      }
    })();
  }, [load, t]);

  async function startNew() {
    if (!examId || !paperLanguage.request) return;
    setBusy(true);
    setError(null);
    try {
      const { data: test } = await testsApi.create({
        exam_id: examId,
        ...paperLanguage.request,
        type: form.type,
        question_count: Number(form.count),
        duration_minutes: Number(form.minutes) || undefined,
      });
      const { data: attempt } = await testsApi.start(test.id);
      router.push(`/app/take?attempt=${attempt.attempt_id}`);
    } catch (e) {
      // A bilingual refusal names how many questions each language has; the
      // localized line says what to do about it.
      const detail = errorText(e, t("tests.noQuestions"));
      setError(paperLanguage.choice === "both" ? `${t("paper.notEnoughBoth")} (${detail})` : detail);
      setBusy(false);
    }
  }

  async function retake(testId: string) {
    const { data } = await testsApi.start(testId);
    router.push(`/app/take?attempt=${data.attempt_id}`);
  }

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">{t("tests.title")}</h1>

      <section className={`${CARD} p-5`}>
        <h2 className="font-semibold">{t("tests.new")}</h2>
        <div className="mt-3">
          <PaperLanguagePicker value={paperLanguage.choice} onChange={paperLanguage.setChoice} />
        </div>
        <div className="mt-3 grid gap-3 sm:grid-cols-4">
          <label className="text-sm">
            <span className="mb-1 block text-xs text-slate-500">{t("tests.type")}</span>
            <select className={FIELD} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
              {TYPES.map((type) => (
                <option key={type} value={type}>
                  {type.replace("_", " ")}
                </option>
              ))}
            </select>
          </label>
          <label className="text-sm">
            <span className="mb-1 block text-xs text-slate-500">{t("tests.count")}</span>
            <input
              className={FIELD}
              inputMode="numeric"
              value={form.count}
              onChange={(e) => setForm({ ...form, count: e.target.value })}
            />
          </label>
          <label className="text-sm">
            <span className="mb-1 block text-xs text-slate-500">{t("tests.duration")}</span>
            <input
              className={FIELD}
              inputMode="numeric"
              value={form.minutes}
              onChange={(e) => setForm({ ...form, minutes: e.target.value })}
            />
          </label>
          <button className={`${BUTTON} self-end`} disabled={busy} onClick={startNew}>
            {busy ? t("common.loading") : t("tests.create")}
          </button>
        </div>
        {error && <p className="mt-3 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}
      </section>

      <section className={CARD}>
        {tests.length === 0 ? (
          <p className="p-6 text-sm text-slate-500">{t("tests.none")}</p>
        ) : (
          <ul className="divide-y divide-slate-100">
            {tests.map((test) => (
              <li key={test.id} className="flex flex-wrap items-center gap-3 px-5 py-3.5">
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{test.title}</p>
                  <p className="text-xs text-slate-500">
                    {test.question_count} {t("common.questions")}
                    {test.duration_minutes ? ` · ${test.duration_minutes} ${t("common.minutes")}` : ""}
                    {test.languages?.length ? ` · ${languageLabel(test.languages)}` : ""}
                    {test.best_accuracy !== null && ` · ${t("tests.best")} ${Math.round(test.best_accuracy)}%`}
                  </p>
                </div>
                {test.open_attempt_id ? (
                  <a href={`/app/take?attempt=${test.open_attempt_id}`} className={BUTTON}>
                    {t("tests.resume")}
                  </a>
                ) : (
                  <button className={GHOST} onClick={() => void retake(test.id)}>
                    {t("tests.retake")}
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
