"use client";

import { useRouter } from "next/navigation";
import { createContext, useCallback, useContext, useEffect, useState, useSyncExternalStore, type ReactNode } from "react";
import { ApiError, catalogue, getToken, student, type Exam, type Language, type StudentProfile } from "@/lib/api";
import { useI18n, type Locale } from "@/lib/i18n";

/**
 * What every student screen needs: who the student is, which exam they are
 * preparing for, and which language their questions should be in. Loaded once
 * by the layout so pages do not each re-fetch it.
 */
interface StudentState {
  profile: StudentProfile | null;
  exams: Exam[];
  languages: Language[];
  /** The language questions are served in, following the interface language. */
  contentLanguageId: string | null;
  loading: boolean;
  refresh: () => Promise<void>;
  setTargetExam: (examId: string) => Promise<void>;
  switchLanguage: (locale: Locale) => Promise<void>;
}

const Context = createContext<StudentState | null>(null);

export function StudentProvider({ children }: { children: ReactNode }) {
  const router = useRouter();
  const { locale, setLocale } = useI18n();
  const [profile, setProfile] = useState<StudentProfile | null>(null);
  const [exams, setExams] = useState<Exam[]>([]);
  const [languages, setLanguages] = useState<Language[]>([]);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async () => {
    try {
      const [p, e, l] = await Promise.all([student.profile(), catalogue.exams(), catalogue.languages()]);
      setProfile(p.data);
      setExams(e.data);
      setLanguages(l.data);
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) router.replace("/login");
    } finally {
      setLoading(false);
    }
  }, [router]);

  useEffect(() => {
    if (!getToken()) {
      router.replace("/login");
      return;
    }
    void (async () => {
      await refresh();
    })();
  }, [router, refresh]);

  const setTargetExam = useCallback(async (examId: string) => {
    const { data } = await student.updateProfile({ target_exam_id: examId });
    setProfile(data);
  }, []);

  const switchLanguage = useCallback(
    async (next: Locale) => {
      setLocale(next);
      const language = languages.find((l) => l.code === next);
      if (language) {
        // Kept on the account too, so new tests default to it on any device.
        const { data } = await student.updateProfile({ preferred_language_id: language.id });
        setProfile(data);
      }
    },
    [languages, setLocale],
  );

  const contentLanguageId =
    languages.find((l) => l.code === locale)?.id ?? languages.find((l) => l.code === "en")?.id ?? null;

  return (
    <Context.Provider
      value={{ profile, exams, languages, contentLanguageId, loading, refresh, setTargetExam, switchLanguage }}
    >
      {children}
    </Context.Provider>
  );
}

export function useStudent(): StudentState {
  const value = useContext(Context);
  if (!value) throw new Error("useStudent must be used inside StudentProvider");
  return value;
}

const noSubscription = () => () => undefined;

/**
 * One query parameter, without useSearchParams' Suspense requirement.
 * undefined during the server render, then the value (or null) in the browser.
 */
export function useQueryParam(name: string): string | null | undefined {
  return useSyncExternalStore(
    noSubscription,
    () => new URLSearchParams(window.location.search).get(name),
    () => undefined,
  );
}

export const CARD = "rounded-2xl border border-slate-200 bg-white shadow-sm";
export const BUTTON =
  "inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50";
export const GHOST =
  "inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50";
export const FIELD =
  "w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100";

export function errorText(e: unknown, fallback: string): string {
  if (e instanceof ApiError) {
    const first = e.errors ? Object.values(e.errors)[0]?.[0] : undefined;
    return first ?? e.detail ?? e.message;
  }
  return e instanceof Error ? e.message : fallback;
}
