"use client";

import { useEffect, useState } from "react";
import { student, testsApi, type AttemptResult, type PaperLanguage, type Solution } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { MultiText, OptionText, versionsOf } from "../paper-language";
import { CARD, GHOST, errorText, useQueryParam } from "../student-context";
// Practice papers: says "not scored" for questions whose paper had no answer key.
import { UnscoredNote, useScoring } from "../papers/unscored-note";

/**
 * A finished attempt: the score, where it was lost, and every solution with
 * the textbook passage it comes from — something a student can take to a
 * teacher and check.
 */
export default function ResultPage() {
  const { t } = useI18n();
  const attemptId = useQueryParam("attempt");
  // Practice papers: how many questions had no key (owned by the papers feature).
  const scoring = useScoring(attemptId);
  const [result, setResult] = useState<AttemptResult | null>(null);
  const [solutions, setSolutions] = useState<Solution[]>([]);
  // The paper's languages in print order; a bilingual paper's solutions are
  // shown in both, as the paper was.
  const [languages, setLanguages] = useState<PaperLanguage[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!attemptId) return;

    void (async () => {
      try {
        const { data } = await student.attempt(attemptId);
        if (data.status !== "submitted") {
          window.location.replace(`/app/take?attempt=${attemptId}`);
          return;
        }
        setResult(data as AttemptResult);
        const answers = await testsApi.solutions(attemptId);
        setSolutions(answers.data);
        setLanguages(answers.meta?.languages ?? []);
      } catch (e) {
        setError(errorText(e, t("common.error")));
      }
    })();
  }, [attemptId, t]);

  if (!result?.result) {
    return <p className="py-16 text-center text-sm text-slate-500">{error ?? t("common.loading")}</p>;
  }

  const r = result.result;
  // Practice papers: with no key for anything answered, a 0 score and 0%
  // would say every answer was wrong. Show a dash instead.
  const nothingScored = (scoring?.unscored ?? 0) > 0 && r.attempted === 0;

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-baseline gap-3">
        <h1 className="text-xl font-semibold">{result.test.title}</h1>
        <span className="text-sm text-slate-500">{t("result.title")}</span>
        <a href="/app/tests" className={`${GHOST} ml-auto`}>
          {t("nav.tests")}
        </a>
      </header>

      <section className="grid grid-cols-2 gap-3 sm:grid-cols-5">
        <Stat label={t("result.score")} value={nothingScored ? "—" : `${r.final_score}`} accent />
        <Stat label={t("result.accuracy")} value={nothingScored ? "—" : `${Math.round(r.accuracy ?? 0)}%`} />
        <Stat label={t("result.correct")} value={`${r.correct}`} tone="text-emerald-700" />
        <Stat label={t("result.wrong")} value={`${r.wrong}`} tone="text-rose-700" />
        <Stat label={t("result.skipped")} value={`${r.unattempted}`} tone="text-slate-500" />
      </section>

      {/* Practice papers: unkeyed questions are unscored, not wrong. Owned by the papers feature. */}
      <UnscoredNote scoring={scoring} />

      {/* A mock in parts: marks per part, and the qualifying mark where a part has one. */}
      {(r.sections?.length ?? 0) > 0 && (
        <section className={`${CARD} p-5`}>
          <h2 className="font-semibold">{t("mock.parts")}</h2>
          <ul className="mt-3 space-y-3">
            {r.sections!.map((part) => (
              <li key={part.name} className="text-sm">
                <div className="flex flex-wrap items-baseline gap-2">
                  <span className="font-medium">{part.name}</span>
                  <span className="ml-auto tabular-nums">
                    {part.marks} / {part.max_marks}
                  </span>
                </div>
                <p className="text-xs text-slate-500">
                  {part.correct} {t("result.correct")} · {part.wrong} {t("result.wrong")} · {part.questions}{" "}
                  {t("common.questions")}
                </p>
                {part.qualified === true && <p className="text-xs text-emerald-700">{t("mock.qualified")}</p>}
                {part.qualified === false && (
                  <p className="mt-1 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    {t("mock.notQualified").replace("{marks}", String(part.qualifying_marks))}
                  </p>
                )}
              </li>
            ))}
          </ul>
        </section>
      )}

      {r.topics.length > 0 && (
        <section className={`${CARD} p-5`}>
          <h2 className="font-semibold">{t("result.byTopic")}</h2>
          <ul className="mt-3 space-y-2.5">
            {r.topics.map((topic) => {
              const accuracy = topic.attempted > 0 ? topic.correct / topic.attempted : 0;
              return (
                <li key={topic.topic} className="text-sm">
                  <div className="flex justify-between">
                    <span>{topic.topic}</span>
                    <span className="text-slate-500">
                      {topic.correct}/{topic.attempted}
                    </span>
                  </div>
                  <div className="mt-1 h-1.5 rounded-full bg-slate-100">
                    <div
                      className={`h-1.5 rounded-full ${accuracy >= 0.7 ? "bg-emerald-500" : accuracy >= 0.4 ? "bg-amber-400" : "bg-rose-400"}`}
                      style={{ width: `${accuracy * 100}%` }}
                    />
                  </div>
                </li>
              );
            })}
          </ul>
        </section>
      )}

      <section className={`${CARD} p-5`}>
        <h2 className="font-semibold">{t("result.solutions")}</h2>
        <ol className="mt-3 space-y-4">
          {solutions.map((s) => {
            // The same languages the paper was taken in; a language with no
            // text is marked rather than filled with another language.
            const versions = versionsOf(s, languages);
            const key = s.correct_option;
            const explained = versions.some((v) => v.available && v.explanation);

            return (
              <li key={s.sequence} className="rounded-xl border border-slate-200 p-4">
                <p className="whitespace-pre-line text-sm leading-relaxed">
                  <span className="mr-2 font-semibold text-slate-500">{s.sequence}.</span>
                  <MultiText versions={versions} field="question_text" />
                </p>
                <p className="mt-2 text-sm">
                  <span className="font-semibold text-emerald-700">
                    {/* Practice papers: a question with no key says so instead of a blank. */}
                    {t("result.answer")}: {s.correct_option ?? t("papers.noKey")}
                  </span>
                  {key && (
                    <span className="ml-1 inline-block align-top">
                      — <OptionText versions={versions} optionKey={key} />
                    </span>
                  )}
                </p>
                {explained && (
                  <p className="mt-1 whitespace-pre-line text-sm text-slate-600">
                    <MultiText versions={versions} field="explanation" />
                  </p>
                )}
                {s.sources.map((src, i) => (
                  <blockquote key={i} className="mt-2 border-l-2 border-indigo-200 pl-3 text-xs text-slate-500">
                    <span className="font-medium text-slate-600">{t("result.source")}:</span> {src.citation}
                    {src.supporting_quote && <span className="block italic">“{src.supporting_quote}”</span>}
                  </blockquote>
                ))}
              </li>
            );
          })}
        </ol>
      </section>
    </div>
  );
}

function Stat({ label, value, accent, tone }: { label: string; value: string; accent?: boolean; tone?: string }) {
  return (
    <div className={`${CARD} p-4 ${accent ? "bg-indigo-600 text-white" : ""}`}>
      <p className={`text-xs ${accent ? "text-indigo-100" : "text-slate-500"}`}>{label}</p>
      <p className={`mt-1 text-2xl font-semibold ${accent ? "" : (tone ?? "")}`}>{value}</p>
    </div>
  );
}
