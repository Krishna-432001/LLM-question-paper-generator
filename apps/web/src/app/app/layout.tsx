"use client";

import { usePathname, useRouter } from "next/navigation";
import { useState, type ReactNode } from "react";
import { authApi, setToken } from "@/lib/api";
import { I18nProvider, useI18n, type MessageKey } from "@/lib/i18n";
import { BUTTON, CARD, StudentProvider, useStudent } from "./student-context";

/**
 * The student app. Light, phone-first, and in the student's language: most
 * TNPSC aspirants practise on a phone, often in Tamil.
 */
export default function StudentLayout({ children }: { children: ReactNode }) {
  return (
    <I18nProvider>
      <StudentProvider>
        <Shell>{children}</Shell>
      </StudentProvider>
    </I18nProvider>
  );
}

const NAV: Array<[string, MessageKey]> = [
  ["/app", "nav.today"],
  ["/app/plans", "nav.plans"],
  ["/app/tests", "nav.tests"],
  ["/app/progress", "nav.progress"],
  ["/app/downloads", "nav.downloads"],
  ["/app/papers", "nav.papers"],
];

function Shell({ children }: { children: ReactNode }) {
  const { t, locale } = useI18n();
  const { profile, loading, switchLanguage } = useStudent();
  const pathname = usePathname();
  const router = useRouter();

  // The test runner is a focused screen: no navigation to tap by accident.
  const focused = pathname?.startsWith("/app/take");

  return (
    <div className="min-h-screen bg-slate-50 text-slate-900">
      {!focused && (
        <header className="sticky top-0 z-10 border-b border-slate-200 bg-white/90 backdrop-blur">
          <div className="mx-auto flex max-w-5xl items-center gap-3 px-4 py-2.5">
            <a href="/app" className="flex items-center gap-2 font-semibold text-slate-900">
              <span className="grid h-7 w-7 place-items-center rounded-lg bg-indigo-600 font-mono text-[11px] text-white">
                EF
              </span>
              <span className="hidden sm:inline">ExamForge</span>
            </a>
            {profile?.target_exam && (
              <span className="truncate rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700">
                {profile.target_exam.name}
              </span>
            )}
            <div className="ml-auto flex items-center gap-2">
              <label className="sr-only" htmlFor="lang">
                {t("lang.label")}
              </label>
              <select
                id="lang"
                value={locale}
                onChange={(e) => void switchLanguage(e.target.value as "en" | "ta")}
                className="rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs"
              >
                <option value="en">English</option>
                <option value="ta">தமிழ்</option>
              </select>
              <button
                onClick={async () => {
                  try {
                    await authApi.logout();
                  } catch {
                    // Logging out locally still works if the server call fails.
                  }
                  setToken(null);
                  router.replace("/login");
                }}
                className="rounded-lg px-2 py-1 text-xs text-slate-500 hover:bg-slate-100"
              >
                {t("nav.logout")}
              </button>
            </div>
          </div>
          <nav className="mx-auto flex max-w-5xl gap-1 overflow-x-auto px-2 pb-1">
            {NAV.map(([href, key]) => {
              const active = href === "/app" ? pathname === "/app" : pathname?.startsWith(href);
              return (
                <a
                  key={href}
                  href={href}
                  className={`whitespace-nowrap rounded-lg px-3 py-1.5 text-sm ${
                    active ? "bg-indigo-50 font-semibold text-indigo-700" : "text-slate-600 hover:bg-slate-100"
                  }`}
                >
                  {t(key)}
                </a>
              );
            })}
          </nav>
        </header>
      )}

      <main className="mx-auto max-w-5xl px-4 py-5">
        {loading ? (
          <p className="py-16 text-center text-sm text-slate-500">{t("common.loading")}</p>
        ) : profile && !profile.target_exam && !focused ? (
          <Onboarding />
        ) : (
          children
        )}
      </main>
    </div>
  );
}

function Onboarding() {
  const { t } = useI18n();
  const { exams, setTargetExam } = useStudent();
  const [choice, setChoice] = useState(exams[0]?.id ?? "");
  const [busy, setBusy] = useState(false);

  return (
    <section className={`${CARD} mx-auto max-w-lg p-6`}>
      <h1 className="text-xl font-semibold">{t("onboard.title")}</h1>
      <p className="mt-1 text-sm text-slate-500">{t("onboard.subtitle")}</p>
      <div className="mt-5 space-y-2">
        {exams.map((exam) => (
          <label
            key={exam.id}
            className={`flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 ${
              choice === exam.id ? "border-indigo-500 bg-indigo-50" : "border-slate-200"
            }`}
          >
            <input type="radio" name="exam" checked={choice === exam.id} onChange={() => setChoice(exam.id)} />
            <span className="text-sm font-medium">{exam.name}</span>
          </label>
        ))}
      </div>
      <button
        className={`${BUTTON} mt-5 w-full`}
        disabled={!choice || busy}
        onClick={async () => {
          setBusy(true);
          await setTargetExam(choice);
          setBusy(false);
        }}
      >
        {t("onboard.continue")}
      </button>
    </section>
  );
}
