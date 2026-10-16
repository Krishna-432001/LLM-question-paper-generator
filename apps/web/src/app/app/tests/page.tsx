"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import {
  ApiError,
  examAdmin,
  student,
  testsApi,
  type MyTestRow,
  type PatternPartCount,
  type SyllabusUnitNode,
} from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { PaperLanguagePicker, creationErrorText, languageLabel, usePaperLanguage } from "../paper-language";
import { BUTTON, CARD, FIELD, GHOST, errorText, useStudent } from "../student-context";

const TYPES = ["topic_practice", "daily", "weekly", "monthly", "model", "prediction", "diagnostic"] as const;

/** The student's tests: resume, retake, see results, or make a new one. */
export default function TestsPage() {
  const { t } = useI18n();
  const router = useRouter();
  const { profile } = useStudent();
  const examId = profile?.target_exam?.id ?? null;
  const paperLanguage = usePaperLanguage();

  const [tests, setTests] = useState<MyTestRow[]>([]);
  const [form, setForm] = useState({ type: "topic_practice", count: "20", minutes: "20" });
  // A topic-wise test: chosen units and single topics; none chosen = the whole syllabus.
  const [units, setUnits] = useState<SyllabusUnitNode[]>([]);
  const [chosenUnits, setChosenUnits] = useState<string[]>([]);
  const [chosenTopics, setChosenTopics] = useState<string[]>([]);
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

  // The syllabus, for choosing topics; loaded once, only when it is needed.
  useEffect(() => {
    if (!examId || form.type !== "topic_practice" || units.length > 0) return;
    void (async () => {
      try {
        setUnits((await examAdmin.syllabus(examId)).data.units);
      } catch {
        // Without the syllabus the test is simply on the whole syllabus.
      }
    })();
  }, [examId, form.type, units.length]);

  const toggle = (list: string[], id: string) => (list.includes(id) ? list.filter((x) => x !== id) : [...list, id]);

  async function startNew() {
    if (!examId || !paperLanguage.request) return;
    setBusy(true);
    setError(null);
    let testId: string;
    try {
      const { data: test } = await testsApi.create({
        exam_id: examId,
        ...paperLanguage.request,
        type: form.type,
        question_count: Number(form.count),
        duration_minutes: Number(form.minutes) || undefined,
        ...(form.type === "topic_practice" && (chosenUnits.length > 0 || chosenTopics.length > 0)
          ? { exam_unit_ids: chosenUnits, exam_topic_ids: chosenTopics }
          : {}),
      });
      testId = test.id;
    } catch (e) {
      // Only the server's refusal of a bilingual paper gets the "not enough
      // in both languages" line; other failures say what they are.
      setError(creationErrorText(e, paperLanguage.choice, t));
      setBusy(false);
      return;
    }
    try {
      const { data: attempt } = await testsApi.start(testId);
      router.push(`/app/take?attempt=${attempt.attempt_id}`);
    } catch (e) {
      setError(errorText(e, t("common.error")));
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

      {examId && <FullMock examId={examId} paperLanguage={paperLanguage} />}

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
        {form.type === "topic_practice" && units.length > 0 && (
          <div className="mt-3">
            <p className="mb-1 text-xs text-slate-500">{t("tests.topics")}</p>
            <div className="max-h-72 space-y-1 overflow-y-auto rounded-xl border border-slate-200 p-2 text-sm">
              {units.map((unit) => (
                <details key={unit.id} className="rounded-lg px-2 py-1 open:bg-slate-50">
                  <summary className="flex cursor-pointer items-center gap-2">
                    <input
                      type="checkbox"
                      className="h-4 w-4 accent-indigo-600"
                      checked={chosenUnits.includes(unit.id)}
                      onChange={() => setChosenUnits(toggle(chosenUnits, unit.id))}
                      onClick={(e) => e.stopPropagation()}
                    />
                    <span className="font-medium">{unit.name}</span>
                    <span className="ml-auto text-xs text-slate-400">{unit.subject}</span>
                  </summary>
                  <ul className="mt-1 space-y-0.5 pl-6">
                    {unit.topics.map((topic) => (
                      <li key={topic.id} className={topic.parent_id ? "pl-4" : ""}>
                        <label className="flex items-center gap-2">
                          <input
                            type="checkbox"
                            className="h-3.5 w-3.5 accent-indigo-600"
                            disabled={chosenUnits.includes(unit.id)}
                            checked={chosenUnits.includes(unit.id) || chosenTopics.includes(topic.id)}
                            onChange={() => setChosenTopics(toggle(chosenTopics, topic.id))}
                          />
                          <span>{topic.name}</span>
                        </label>
                      </li>
                    ))}
                  </ul>
                </details>
              ))}
            </div>
          </div>
        )}
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

/**
 * The full mock in the exam's own pattern. When the bank cannot fill every
 * part yet it says so part by part, and offers the shorter mock — never a
 * short paper passed off as the real one.
 */
function FullMock({ examId, paperLanguage }: { examId: string; paperLanguage: ReturnType<typeof usePaperLanguage> }) {
  const { t } = useI18n();
  const router = useRouter();
  const [type, setType] = useState<"model" | "prediction">("model");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [short, setShort] = useState<{ parts: PatternPartCount[]; available: number } | null>(null);

  async function start(allowShort: boolean) {
    if (!paperLanguage.request) return;
    setBusy(true);
    setError(null);
    let testId: string;
    try {
      const { data } = await testsApi.createExamPattern({
        exam_id: examId,
        ...paperLanguage.request,
        type,
        allow_short: allowShort,
      });
      testId = data.id;
    } catch (e) {
      if (e instanceof ApiError && e.code === "exam_pattern_short") {
        setShort({
          parts: (e.meta?.sections as PatternPartCount[] | undefined) ?? [],
          available: Number(e.meta?.available_total ?? 0),
        });
      } else {
        setError(errorText(e, t("common.error")));
      }
      setBusy(false);
      return;
    }
    try {
      const { data: attempt } = await testsApi.start(testId);
      router.push(`/app/take?attempt=${attempt.attempt_id}`);
    } catch (e) {
      setError(errorText(e, t("common.error")));
      setBusy(false);
    }
  }

  return (
    <section className={`${CARD} p-5`}>
      <h2 className="font-semibold">{t("mock.title")}</h2>
      <p className="mt-1 text-sm text-slate-500">{t("mock.pattern")}</p>
      <div className="mt-3 flex flex-wrap items-end gap-3">
        <label className="text-sm">
          <span className="mb-1 block text-xs text-slate-500">{t("tests.type")}</span>
          <select
            className={FIELD}
            value={type}
            onChange={(e) => {
              setType(e.target.value as "model" | "prediction");
              setShort(null);
            }}
          >
            <option value="model">{t("mock.standard")}</option>
            <option value="prediction">{t("mock.prediction")}</option>
          </select>
        </label>
        <button className={BUTTON} disabled={busy} onClick={() => void start(false)}>
          {busy ? t("common.loading") : t("mock.start")}
        </button>
      </div>
      {short && (
        <div className="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          {short.available === 0 ? (
            <p>{t("mock.empty")}</p>
          ) : (
            <>
              <p className="font-semibold">{t("mock.short")}</p>
              <ul className="mt-1 list-disc space-y-0.5 pl-5">
                {short.parts.map((part) => (
                  <li key={part.name}>
                    {t("mock.partCount")
                      .replace("{name}", part.name)
                      .replace("{available}", String(part.available))
                      .replace("{needed}", String(part.needed))}
                  </li>
                ))}
              </ul>
              <button className={`${GHOST} mt-3`} disabled={busy} onClick={() => void start(true)}>
                {t("mock.buildShort").replace("{count}", String(short.available))}
              </button>
            </>
          )}
        </div>
      )}
      {error && <p className="mt-3 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}
    </section>
  );
}
