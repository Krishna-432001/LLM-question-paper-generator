"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import {
  ApiError,
  catalogue,
  getToken,
  targets,
  type BacklogRow,
  type Exam,
} from "@/lib/api";

/**
 * The content backlog: how far each topic falls short of its target.
 *
 * Before this existed an operator picked a topic by instinct and generated
 * against a hunch. The point of the screen is to replace that with a number,
 * so the thinnest topic is the obvious one to work on.
 */

const PANEL = "rounded-xl border border-slate-800 bg-slate-900";
const FIELD =
  "rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 outline-none focus:border-sky-500";

export default function BacklogPage() {
  const router = useRouter();

  const [exams, setExams] = useState<Exam[]>([]);
  const [examId, setExamId] = useState("");
  const [rows, setRows] = useState<BacklogRow[]>([]);
  const [meta, setMeta] = useState<{
    targets: number;
    total_deficit: number;
    satisfied: number;
    counts_pending_as_held: boolean;
  } | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async (exam: string) => {
    try {
      const { data, meta } = await targets.backlog(exam);
      setRows(data);
      setMeta(meta);
      setError(null);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load the backlog.");
    }
  }, []);

  useEffect(() => {
    if (!getToken()) {
      router.replace("/login");
      return;
    }

    (async () => {
      try {
        const { data } = await catalogue.exams();
        setExams(data);

        const first = data[0]?.id ?? "";
        setExamId(first);

        if (first) await load(first);
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) router.replace("/login");
        else setError(e instanceof Error ? e.message : "Something went wrong.");
      }
    })();
  }, [router, load]);

  const widest = Math.max(1, ...rows.map((r) => r.requested));

  return (
    <>
      <header className="mb-4 flex flex-wrap items-baseline gap-x-6 gap-y-1">
        <h1 className="text-lg font-semibold text-slate-100">Content backlog</h1>
        <p className="text-xs text-slate-500">
          How far each topic falls short of the blueprint
        </p>
        <select
          value={examId}
          onChange={async (e) => {
            setExamId(e.target.value);
            await load(e.target.value);
          }}
          className={`ml-auto ${FIELD}`}
        >
          {exams.map((e) => (
            <option key={e.id} value={e.id}>
              {e.name}
            </option>
          ))}
        </select>
      </header>

      {error && (
        <p className="mb-4 rounded-lg border border-red-900 bg-red-950/60 px-4 py-3 text-sm text-red-300">
          {error}
        </p>
      )}

      {meta && (
        <div className="mb-4 flex flex-wrap gap-2">
          <Chip value={meta.targets} label="targets" />
          <Chip value={meta.total_deficit} label="questions short" tone="amber" />
          <Chip value={meta.satisfied} label="already met" tone="emerald" />
        </div>
      )}

      <section className={PANEL}>
        <div className="flex items-center justify-between border-b border-slate-800 px-4 py-2.5">
          <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
            Largest shortfall first
          </p>
          {meta?.counts_pending_as_held && (
            // Said out loud because it changes every number on the page.
            <p className="text-[11px] text-slate-500">
              Questions awaiting review count as already held
            </p>
          )}
        </div>

        <ul className="divide-y divide-slate-800/70">
          {rows.map((row, i) => (
            <li key={`${row.canonical_topic_id}-${i}`} className="flex items-center gap-4 px-4 py-3">
              <span className="w-8 shrink-0 font-mono text-[11px] text-slate-600">
                {i + 1}
              </span>

              {/* Approved, then pending, then the gap — one bar, so "how much
                  of this target is real" is legible at a glance. */}
              <span className="flex h-2 flex-1 overflow-hidden rounded-full bg-slate-800">
                <span
                  className="h-2 bg-emerald-500"
                  style={{ width: `${(row.approved / widest) * 100}%` }}
                />
                <span
                  className="h-2 bg-amber-500/70"
                  style={{ width: `${(row.pending_review / widest) * 100}%` }}
                />
              </span>

              <span className="w-44 shrink-0 text-right font-mono text-[11px] text-slate-500">
                {row.approved} approved
                {row.pending_review > 0 && ` · ${row.pending_review} pending`}
                {" / "}
                {row.requested}
              </span>

              <span
                className={`w-20 shrink-0 rounded px-2 py-1 text-center font-mono text-[11px] ${
                  row.satisfied
                    ? "bg-emerald-950/60 text-emerald-300"
                    : "bg-amber-950/50 text-amber-300"
                }`}
              >
                {row.satisfied ? "met" : `−${row.deficit}`}
              </span>
            </li>
          ))}

          {rows.length === 0 && (
            <li className="px-4 py-10 text-center text-sm text-slate-500">
              No targets configured for this exam.
            </li>
          )}
        </ul>
      </section>
    </>
  );
}

function Chip({
  value,
  label,
  tone,
}: {
  value: number;
  label: string;
  tone?: "emerald" | "amber";
}) {
  const tones = {
    emerald: "bg-emerald-950/60 text-emerald-300",
    amber: "bg-amber-950/50 text-amber-300",
  };

  return (
    <span
      className={`rounded px-2 py-1 font-mono text-[11px] ${tone ? tones[tone] : "bg-slate-800 text-slate-400"}`}
    >
      {value} {label}
    </span>
  );
}
