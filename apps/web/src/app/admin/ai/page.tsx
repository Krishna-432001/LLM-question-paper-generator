"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import {
  ApiError,
  aiHistory,
  getToken,
  type AiHistoryMeta,
  type AiInvocation,
} from "@/lib/api";

/**
 * Where the model budget actually goes.
 *
 * The per-operation table is the point of the screen. The useful question is
 * never "what did one call cost" but "which operation is eating the budget",
 * and the answer is usually one operation sitting on a model tier it does not
 * need.
 */

const PANEL = "rounded-xl border border-slate-800 bg-slate-900";

export default function AiHistoryPage() {
  const router = useRouter();

  const [rows, setRows] = useState<AiInvocation[]>([]);
  const [summary, setSummary] = useState<AiHistoryMeta | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!getToken()) {
      router.replace("/login");
      return;
    }

    (async () => {
      try {
        const { data, meta } = await aiHistory.load(50);
        setRows(data);
        setSummary(meta);
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) router.replace("/login");
        else setError(e instanceof Error ? e.message : "Could not load AI history.");
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

  if (!summary) {
    return <p className="p-8 text-center text-sm text-slate-500">Loading…</p>;
  }

  const heaviest = summary.by_operation[0]?.cost_inr ?? 1;

  return (
    <>
      <header className="mb-4 flex flex-wrap items-baseline gap-x-6 gap-y-1">
        <h1 className="text-lg font-semibold text-slate-100">AI history</h1>
        <p className="text-xs text-slate-500">Every model call, and what it cost</p>
      </header>

      <div className="mb-5 grid gap-3 sm:grid-cols-4">
        <Stat value={summary.total_calls.toString()} label="calls" />
        <Stat value={`₹${summary.total_cost_inr.toFixed(2)}`} label="spent" />
        <Stat
          value={`${(summary.cache_hit_rate * 100).toFixed(0)}%`}
          label="served from cache"
          tone={summary.cache_hit_rate > 0.2 ? "emerald" : undefined}
        />
        <Stat
          value={summary.errors.toString()}
          label="errors"
          tone={summary.errors > 0 ? "red" : undefined}
        />
      </div>

      <section className={`${PANEL} mb-5 p-5`}>
        <h2 className="mb-1 text-sm font-semibold text-slate-100">Cost by operation</h2>
        <p className="mb-4 text-xs text-slate-500">
          One operation on the wrong model tier is the usual explanation for a surprising bill
        </p>

        <ul className="space-y-3">
          {summary.by_operation.map((op) => (
            <li key={op.operation}>
              <div className="flex items-baseline justify-between gap-3 text-sm">
                <span className="text-slate-300">{op.operation}</span>
                <span className="font-mono text-slate-200">₹{op.cost_inr.toFixed(2)}</span>
              </div>

              <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-800">
                <div
                  className="h-1.5 rounded-full bg-sky-500"
                  style={{ width: `${Math.max(2, (op.cost_inr / heaviest) * 100)}%` }}
                />
              </div>

              <p className="mt-1 font-mono text-[11px] text-slate-500">
                {op.calls} calls · {op.input_tokens.toLocaleString()} in ·{" "}
                {op.output_tokens.toLocaleString()} out · {op.avg_latency_ms}ms avg ·{" "}
                {op.cache_hits} cached
                {op.errors > 0 && <span className="text-red-400"> · {op.errors} failed</span>}
              </p>
            </li>
          ))}
        </ul>
      </section>

      <section className={PANEL}>
        <p className="border-b border-slate-800 px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-slate-500">
          Most recent {rows.length}
        </p>
        <ul className="divide-y divide-slate-800/70">
          {rows.map((row) => (
            <li key={row.id} className="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-4 py-2.5">
              <span className="w-52 shrink-0 truncate text-sm text-slate-300">{row.operation}</span>
              <span className="font-mono text-[11px] text-slate-500">{row.model ?? "—"}</span>
              <span className="font-mono text-[11px] text-slate-500">
                {row.input_tokens}&rarr;{row.output_tokens}
              </span>

              {row.cache_hit && <Tag tone="emerald">cached</Tag>}
              {/* A cheaper tier was used because the primary was unavailable:
                  a quality event, not only a cost one. */}
              {row.degraded && <Tag tone="amber">degraded</Tag>}
              {row.error && <Tag tone="red">failed</Tag>}

              <span className="ml-auto font-mono text-[11px] text-slate-400">
                ₹{row.cost_inr.toFixed(4)}
              </span>
              <span className="w-20 shrink-0 text-right font-mono text-[11px] text-slate-600">
                {row.created_at.slice(11, 19)}
              </span>
            </li>
          ))}

          {rows.length === 0 && (
            <li className="px-4 py-10 text-center text-sm text-slate-500">
              No model calls recorded yet.
            </li>
          )}
        </ul>
      </section>
    </>
  );
}

function Tag({
  tone,
  children,
}: {
  tone: "emerald" | "amber" | "red";
  children: React.ReactNode;
}) {
  const tones = {
    emerald: "bg-emerald-950/60 text-emerald-300",
    amber: "bg-amber-950/50 text-amber-300",
    red: "bg-red-950/50 text-red-300",
  };

  return (
    <span className={`rounded px-1.5 py-0.5 font-mono text-[10px] ${tones[tone]}`}>{children}</span>
  );
}

function Stat({
  value,
  label,
  tone,
}: {
  value: string;
  label: string;
  tone?: "emerald" | "red";
}) {
  const tones = { emerald: "text-emerald-300", red: "text-red-300" };

  return (
    <div className={`${PANEL} p-4`}>
      <p className={`font-mono text-2xl ${tone ? tones[tone] : "text-slate-100"}`}>{value}</p>
      <p className="mt-0.5 text-xs text-slate-500">{label}</p>
    </div>
  );
}
