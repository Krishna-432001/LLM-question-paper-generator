"use client";

import { useEffect, useState } from "react";
import {
  documentsApi,
  downloadDocument,
  plansApi,
  student,
  type DocumentRow,
  type MyTestRow,
  type PlanSummary,
} from "@/lib/api";
import { useI18n, type MessageKey } from "@/lib/i18n";
import { CARD, GHOST, errorText, useStudent } from "../student-context";

const TYPE_LABEL: Record<string, MessageKey> = {
  question_paper: "downloads.paper",
  answer_key: "downloads.key",
  explanations: "downloads.explanations",
  study_plan: "downloads.plan",
};

/**
 * Printable copies. Rendering is a layout pass over stored data — no model —
 * and a second download re-serves the stored file.
 */
export default function DownloadsPage() {
  const { t } = useI18n();
  const { profile } = useStudent();
  const examId = profile?.target_exam?.id;
  const [docs, setDocs] = useState<DocumentRow[]>([]);
  const [tests, setTests] = useState<MyTestRow[]>([]);
  const [plans, setPlans] = useState<PlanSummary[]>([]);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    void (async () => {
      try {
        const [d, ts, ps] = await Promise.all([documentsApi.list(), student.tests(examId), plansApi.list()]);
        setDocs(d.data);
        setTests(ts.data);
        setPlans(ps.data);
      } catch (e) {
        setError(errorText(e, t("common.error")));
      }
    })();
  }, [examId, t]);

  async function make(kind: "tests" | "study-plans", id: string) {
    setBusy(id);
    setError(null);
    try {
      const { data } = await documentsApi.generate(kind, id);
      setDocs((existing) => [...data, ...existing.filter((d) => !data.some((n) => n.id === d.id))]);
    } catch (e) {
      setError(errorText(e, t("common.error")));
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">{t("downloads.title")}</h1>
      {error && <p className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}

      <section className={CARD}>
        {docs.length === 0 ? (
          <p className="p-6 text-sm text-slate-500">{t("downloads.none")}</p>
        ) : (
          <ul className="divide-y divide-slate-100">
            {docs.map((doc) => (
              <li key={doc.id} className="flex items-center gap-3 px-5 py-3">
                <span className="flex-1 truncate text-sm">
                  {doc.title}
                  <span className="ml-2 text-xs text-slate-500">
                    {TYPE_LABEL[doc.type] ? t(TYPE_LABEL[doc.type]) : doc.type}
                  </span>
                </span>
                <button
                  className={GHOST}
                  onClick={() => void downloadDocument(doc.id, `${doc.document_code}.${doc.format ?? "pdf"}`)}
                >
                  ⬇ PDF
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className={`${CARD} p-5`}>
        <h2 className="font-semibold">{t("downloads.make")}</h2>
        <ul className="mt-3 divide-y divide-slate-100">
          {tests.slice(0, 15).map((test) => (
            <li key={test.id} className="flex items-center gap-3 py-2.5 text-sm">
              <span className="flex-1 truncate">{test.title}</span>
              <button className={GHOST} disabled={busy === test.id} onClick={() => void make("tests", test.id)}>
                {busy === test.id ? t("common.loading") : `${t("downloads.paper")} + ${t("downloads.key")}`}
              </button>
            </li>
          ))}
          {plans.slice(0, 5).map((plan) => (
            <li key={plan.id} className="flex items-center gap-3 py-2.5 text-sm">
              <span className="flex-1 truncate">
                {t("downloads.plan")} · {plan.start_date} → {plan.end_date}
              </span>
              <button className={GHOST} disabled={busy === plan.id} onClick={() => void make("study-plans", plan.id)}>
                {busy === plan.id ? t("common.loading") : t("downloads.plan")}
              </button>
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}
