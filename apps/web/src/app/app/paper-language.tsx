"use client";

import { useState, type ReactNode } from "react";
import { ApiError, type LanguageMode, type PaperLanguage, type QuestionText } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { errorText, useStudent } from "./student-context";

/**
 * Which language a new paper is printed in: English, Tamil, or both — each
 * question twice, the way TNPSC sets its papers.
 *
 * The choice defaults to the interface language and keeps following it until
 * the student picks something else, so a student who switches the app to
 * Tamil gets Tamil papers without a second step.
 */
export type PaperChoice = "en" | "ta" | "both";

/** Language names are shown in their own script whatever the interface language. */
const NAMES: Record<string, string> = { en: "English", ta: "தமிழ்" };

export function languageLabel(codes: string[] | undefined): string {
  return (codes ?? []).map((code) => NAMES[code] ?? code.toUpperCase()).join(" + ");
}

export interface PaperLanguageRequest {
  language_id: string;
  language_mode: LanguageMode;
  secondary_language_id?: string;
}

export function usePaperLanguage(): {
  choice: PaperChoice;
  setChoice: (choice: PaperChoice) => void;
  /** null until the language list has loaded. */
  request: PaperLanguageRequest | null;
} {
  const { locale } = useI18n();
  const { languages } = useStudent();
  const [picked, setPicked] = useState<PaperChoice | null>(null);

  const interfaceCode = locale === "ta" ? "ta" : "en";
  const choice: PaperChoice = picked ?? interfaceCode;
  const idOf = (code: string) => languages.find((l) => l.code === code)?.id ?? null;

  // "Both" puts the interface language first: a student reading the app in
  // Tamil reads the Tamil line first and checks the English beneath it.
  const primaryCode = choice === "both" ? interfaceCode : choice;
  const primaryId = idOf(primaryCode);
  const secondaryId = choice === "both" ? idOf(primaryCode === "ta" ? "en" : "ta") : null;

  const request: PaperLanguageRequest | null =
    primaryId === null || (choice === "both" && secondaryId === null)
      ? null
      : choice === "both"
        ? { language_id: primaryId, language_mode: "bilingual", secondary_language_id: secondaryId ?? undefined }
        : { language_id: primaryId, language_mode: "single" };

  return { choice, setChoice: setPicked, request };
}

/**
 * The message for a failed test creation. Only the server's refusal of a
 * bilingual paper — a 422 carrying a detail, not field errors — gets the
 * "not enough in both languages" line; a dropped connection or a bad
 * question count must not be blamed on the languages.
 */
export function creationErrorText(
  e: unknown,
  choice: PaperChoice,
  t: (key: "paper.notEnoughBoth" | "tests.noQuestions") => string,
): string {
  const detail = errorText(e, t("tests.noQuestions"));
  const bilingualRefusal = choice === "both" && e instanceof ApiError && e.status === 422 && !e.errors;

  return bilingualRefusal ? `${t("paper.notEnoughBoth")} (${detail})` : detail;
}

export function PaperLanguagePicker({
  value,
  onChange,
}: {
  value: PaperChoice;
  onChange: (choice: PaperChoice) => void;
}) {
  const { t } = useI18n();
  const options: Array<[PaperChoice, string]> = [
    ["en", NAMES.en],
    ["ta", NAMES.ta],
    ["both", t("paper.both")],
  ];

  return (
    <fieldset className="text-sm">
      <legend className="mb-1 block text-xs text-slate-500">{t("paper.language")}</legend>
      <div className="inline-flex rounded-xl border border-slate-300 bg-white p-0.5" role="radiogroup">
        {options.map(([key, label]) => (
          <button
            key={key}
            type="button"
            role="radio"
            aria-checked={value === key}
            onClick={() => onChange(key)}
            className={`rounded-lg px-3 py-1.5 text-sm font-medium transition ${
              value === key ? "bg-indigo-600 text-white" : "text-slate-600 hover:bg-slate-50"
            }`}
          >
            {label}
          </button>
        ))}
      </div>
      {value === "both" && <p className="mt-1 text-xs text-slate-500">{t("paper.bothHint")}</p>}
    </fieldset>
  );
}

export type Version = QuestionText & { code: string | null; available: boolean };

/**
 * A question's wording in print order: the primary first, then any other
 * language of the paper. Falls back to the single-language fields an older
 * server sends, so the runner never shows a blank question.
 */
export function versionsOf(
  item: {
    question_text: string | null;
    options?: Record<string, string> | null;
    explanation?: string | null;
    texts?: Record<string, QuestionText>;
  },
  languages: PaperLanguage[] | undefined,
): Version[] {
  const fromTexts = (languages ?? []).flatMap((l) => {
    const text = item.texts?.[l.code];
    return text ? [{ ...text, code: l.code, available: text.available !== false }] : [];
  });

  if (fromTexts.length > 0) return fromTexts;

  return [
    {
      code: languages?.[0]?.code ?? null,
      question_text: item.question_text,
      options: item.options ?? {},
      explanation: item.explanation,
      available: true,
    },
  ];
}

/**
 * One field across a paper's languages: the first language that has it in
 * full, any other beneath it in the lighter style, and — for the question
 * itself — a visible note where a language has no text, rather than another
 * language's text passed off as it.
 */
export function MultiText({ versions, field }: { versions: Version[]; field: "question_text" | "explanation" }) {
  const { t } = useI18n();
  const leadIndex = versions.findIndex((v) => v.available && (v[field] ?? "") !== "");
  const lead = leadIndex >= 0 ? (versions[leadIndex][field] ?? "") : "";

  return (
    <>
      {versions.map((v, i) => {
        const key = `${v.code ?? "x"}-${i}`;

        if (!v.available) {
          return field === "question_text" ? (
            <span key={key} className="mt-0.5 block text-[0.85em] italic text-amber-700">
              {languageLabel(v.code ? [v.code] : [])} — {t("paper.unavailable")}
            </span>
          ) : null;
        }

        const text = v[field] ?? "";
        if (i === leadIndex) {
          return (
            <span key={key} lang={v.code ?? undefined}>
              {text}
            </span>
          );
        }
        if (text === "" || text === lead) return null;

        return (
          <Alternate key={key} code={v.code}>
            {text}
          </Alternate>
        );
      })}
    </>
  );
}

/** Option letters across every language that has text, in order. */
export function optionKeysOf(versions: Version[]): string[] {
  return [...new Set(versions.filter((v) => v.available).flatMap((v) => Object.keys(v.options ?? {})))].sort();
}

/** One option's text: the first language that has it, others beneath. */
export function OptionText({ versions, optionKey }: { versions: Version[]; optionKey: string }) {
  const shown = versions.filter((v) => v.available && (v.options?.[optionKey] ?? "") !== "");
  const [lead, ...rest] = shown;
  if (!lead) return null;
  const leadText = lead.options[optionKey];

  return (
    <span lang={lead.code ?? undefined}>
      {leadText}
      {rest.map((other) =>
        other.options[optionKey] !== leadText ? (
          <Alternate key={other.code} code={other.code}>
            {other.options[optionKey]}
          </Alternate>
        ) : null,
      )}
    </span>
  );
}

/** The second language of a bilingual paper, set lighter beneath the first. */
export function Alternate({ code, children }: { code: string | null; children: ReactNode }) {
  return (
    <span lang={code ?? undefined} className="mt-0.5 block text-[0.92em] text-slate-500">
      {children}
    </span>
  );
}
