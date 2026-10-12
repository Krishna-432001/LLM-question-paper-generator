"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import {
  ApiError,
  dashboard,
  getToken,
  trends,
  type DashboardData,
  type TrendData,
  type TrendPoint,
} from "@/lib/api";

/**
 * The state of the system, and what to do about it.
 *
 * A dashboard earns its place only if it changes what someone does next, so
 * every number here is either an action ("43 waiting for review") or a warning
 * ("2 pages could not be read"). Counts that only go up — total questions
 * generated, total calls made — are deliberately kept small and grey.
 */

const PANEL = "rounded-xl border border-slate-800 bg-slate-900";

export default function DashboardPage() {
  const router = useRouter();

  const [data, setData] = useState<DashboardData | null>(null);
  const [trend, setTrend] = useState<TrendData | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!getToken()) {
      router.replace("/login");
      return;
    }

    (async () => {
      try {
        const [overview, series] = await Promise.all([dashboard.load(), trends.load(30)]);
        setData(overview.data);
        setTrend(series.data);
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) router.replace("/login");
        else setError(e instanceof Error ? e.message : "Could not load the dashboard.");
      }
    })();
  }, [router]);

  if (error) {
    return (
      <p className="rounded-lg border border-red-900 bg-red-950/60 px-4 py-3 text-sm text-red-300">
        {error}
      </p>
    );
  }

  if (!data) {
    return <p className="p-8 text-center text-sm text-slate-500">Loading…</p>;
  }

  const { books, questions, pyqs, documents, ai } = data;
  const budgetUsed = ai.daily_budget_inr > 0 ? ai.spend_today_inr / ai.daily_budget_inr : 0;

  return (
    <>
      <header className="mb-5 flex flex-wrap items-baseline gap-x-6 gap-y-1">
        <h1 className="text-lg font-semibold text-slate-100">Overview</h1>
        <p className="text-xs text-slate-500">
          {ai.driver === "fake"
            ? "Running on the offline model — question quality is a stand-in"
            : `Generating with ${ai.driver}`}
        </p>
      </header>

      {/* Anything needing a decision comes first. */}
      <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Action
          value={questions.pending_review}
          label="waiting for review"
          href="/review"
          tone={questions.pending_review > 0 ? "amber" : "quiet"}
        />
        <Action
          value={pyqs.unmapped}
          label="past questions unmapped"
          href="/admin/pyq"
          tone={pyqs.unmapped > 0 ? "amber" : "quiet"}
          hint="Unmapped questions count for nothing in the analysis"
        />
        <Action
          value={books.pages_needing_review}
          label="pages read badly"
          href="/admin"
          tone={books.pages_needing_review > 0 ? "red" : "quiet"}
        />
        <Action
          value={ai.jobs_failed}
          label="jobs failed"
          href="/admin"
          tone={ai.jobs_failed > 0 ? "red" : "quiet"}
        />
      </div>

      {trend && (
        <section className={`${PANEL} mb-5 p-5`}>
          <div className="mb-4 flex flex-wrap items-baseline justify-between gap-2">
            <h2 className="text-sm font-semibold text-slate-100">Last 30 days</h2>
            <p className="text-xs text-slate-500">
              {trend.from} to {trend.to} · quiet days shown, not skipped
            </p>
          </div>

          <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <Spark label="Drafted" points={trend.series.drafted} className="text-sky-400" />
            <Spark label="Approved" points={trend.series.approved} className="text-emerald-400" />
            <Spark label="Papers built" points={trend.series.documents} className="text-slate-400" />
            <Spark
              label="Spend"
              points={trend.series.spend_inr}
              className="text-amber-400"
              prefix="₹"
            />
          </div>
        </section>
      )}

      <div className="grid gap-4 lg:grid-cols-3">
        <section className={`${PANEL} p-5`}>
          <h2 className="mb-3 text-sm font-semibold text-slate-100">Question bank</h2>

          {/* One bar, three states: what the bank is really made of. */}
          <div className="mb-3 flex h-2 overflow-hidden rounded-full bg-slate-800">
            <Segment value={questions.approved} total={questions.total} className="bg-emerald-500" />
            <Segment value={questions.pending_review} total={questions.total} className="bg-amber-500" />
            <Segment value={questions.rejected} total={questions.total} className="bg-slate-600" />
          </div>

          <Row label="Approved" value={questions.approved} tone="emerald" />
          <Row label="Awaiting review" value={questions.pending_review} tone="amber" />
          <Row label="Rejected" value={questions.rejected} />
          <Row label="Drafted in total" value={questions.total} quiet />

          {Object.keys(questions.top_rejection_reasons).length > 0 && (
            <>
              <p className="mt-4 mb-2 text-xs text-slate-500">
                Why questions were discarded — the clearest signal of generation quality
              </p>
              {Object.entries(questions.top_rejection_reasons).map(([code, count]) => (
                <Row key={code} label={code.toLowerCase().replaceAll("_", " ")} value={count} quiet />
              ))}
            </>
          )}
        </section>

        <section className={`${PANEL} p-5`}>
          <h2 className="mb-3 text-sm font-semibold text-slate-100">Books</h2>
          <Row label="Ready to generate from" value={books.ready} tone="emerald" />
          <Row label="Still processing" value={books.processing} tone={books.processing ? "amber" : undefined} />
          <Row label="Failed" value={books.failed} tone={books.failed ? "red" : undefined} />
          <Row label="Pages read" value={books.pages} quiet />

          <h2 className="mb-3 mt-6 text-sm font-semibold text-slate-100">Past papers</h2>
          <Row label="Papers" value={pyqs.papers} />
          <Row label="Questions" value={pyqs.questions} />
          <Row label="Unmapped" value={pyqs.unmapped} tone={pyqs.unmapped ? "amber" : undefined} />
          {pyqs.years && (
            <p className="mt-2 font-mono text-[11px] text-slate-500">Covering {pyqs.years}</p>
          )}
        </section>

        <section className={`${PANEL} p-5`}>
          <h2 className="mb-3 text-sm font-semibold text-slate-100">AI spend</h2>

          <p className="font-mono text-2xl text-slate-100">
            ₹{ai.spend_today_inr.toFixed(2)}
            <span className="ml-2 text-xs text-slate-500">today</span>
          </p>

          {ai.daily_budget_inr > 0 && (
            <>
              <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-slate-800">
                <div
                  className={`h-1.5 rounded-full ${
                    budgetUsed > 0.8 ? "bg-red-500" : budgetUsed > 0.5 ? "bg-amber-500" : "bg-sky-500"
                  }`}
                  style={{ width: `${Math.min(100, budgetUsed * 100)}%` }}
                />
              </div>
              <p className="mt-1.5 text-xs text-slate-500">
                {(budgetUsed * 100).toFixed(0)}% of the ₹{ai.daily_budget_inr} daily cap
              </p>
            </>
          )}

          <div className="mt-4 border-t border-slate-800 pt-3">
            <Row label="Calls made" value={ai.calls} quiet />
            <Row label="Spent in total" value={`₹${ai.spend_inr.toFixed(2)}`} quiet />
            <Row label="Jobs running" value={ai.jobs_running} tone={ai.jobs_running ? "sky" : undefined} />
          </div>

          <h2 className="mb-3 mt-6 text-sm font-semibold text-slate-100">Papers produced</h2>
          <Row label="Documents" value={documents.total} />
          <Row label="Downloads" value={documents.downloads} quiet />
          <div className="mt-2 flex flex-wrap gap-1.5">
            {Object.entries(documents.by_format).map(([format, count]) => (
              <span
                key={format}
                className="rounded bg-slate-800 px-2 py-1 font-mono text-[11px] text-slate-400"
              >
                {count} {format}
              </span>
            ))}
          </div>
        </section>
      </div>
    </>
  );
}

/**
 * A bar per day, including the empty ones.
 *
 * Drawn from the full zero-filled series rather than only the active days: a
 * chart that omits quiet days compresses a fortnight of nothing into a flat
 * line beside a busy one, and reads as steady output when the truth is a stall.
 */
function Spark({
  label,
  points,
  className,
  prefix = "",
}: {
  label: string;
  points: TrendPoint[];
  className: string;
  prefix?: string;
}) {
  const total = points.reduce((sum, p) => sum + p.value, 0);
  const peak = Math.max(...points.map((p) => p.value), 0);

  return (
    <div>
      <div className="flex items-baseline justify-between">
        <span className="text-xs text-slate-500">{label}</span>
        <span className={`font-mono text-sm ${className}`}>
          {prefix}
          {total % 1 === 0 ? total : total.toFixed(2)}
        </span>
      </div>

      <div className="mt-2 flex h-10 items-end gap-px" title={`Peak ${prefix}${peak}`}>
        {points.map((point) => (
          <span
            key={point.date}
            title={`${point.date}: ${prefix}${point.value}`}
            className={`flex-1 rounded-sm ${point.value > 0 ? "bg-current" : "bg-slate-800"} ${className}`}
            style={{
              // A day with activity never renders as nothing, or a single
              // question looks identical to none at all.
              height: peak > 0 && point.value > 0
                ? `${Math.max(12, (point.value / peak) * 100)}%`
                : "2px",
            }}
          />
        ))}
      </div>
    </div>
  );
}

function Action({
  value,
  label,
  href,
  tone,
  hint,
}: {
  value: number;
  label: string;
  href: string;
  tone: "amber" | "red" | "quiet";
  hint?: string;
}) {
  const tones = {
    amber: "border-amber-700/70 bg-amber-950/30 text-amber-200",
    red: "border-red-900 bg-red-950/40 text-red-300",
    quiet: "border-slate-800 bg-slate-900 text-slate-500",
  };

  return (
    <a
      href={href}
      className={`block rounded-xl border p-4 transition-colors hover:border-slate-600 ${tones[tone]}`}
    >
      <span className="font-mono text-2xl">{value}</span>
      <span className="mt-0.5 block text-xs">{label}</span>
      {hint && value > 0 && <span className="mt-1 block text-[11px] opacity-70">{hint}</span>}
    </a>
  );
}

function Row({
  label,
  value,
  tone,
  quiet,
}: {
  label: string;
  value: number | string;
  tone?: "emerald" | "amber" | "red" | "sky";
  quiet?: boolean;
}) {
  const tones = {
    emerald: "text-emerald-300",
    amber: "text-amber-300",
    red: "text-red-300",
    sky: "text-sky-300",
  };

  return (
    <div className="flex items-baseline justify-between py-1 text-sm">
      <span className={quiet ? "text-slate-500" : "text-slate-300"}>{label}</span>
      <span className={`font-mono ${tone ? tones[tone] : quiet ? "text-slate-500" : "text-slate-200"}`}>
        {value}
      </span>
    </div>
  );
}

function Segment({
  value,
  total,
  className,
}: {
  value: number;
  total: number;
  className: string;
}) {
  if (total === 0 || value === 0) return null;

  return <span className={className} style={{ width: `${(value / total) * 100}%` }} />;
}
