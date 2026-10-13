"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { ApiError, audit, getToken, type AuditEntry } from "@/lib/api";

/**
 * Who did what.
 *
 * The table is append-only at the database level, so this screen is read-only
 * by construction — there is no write path to expose even if someone wanted
 * one.
 *
 * Entries are grouped by request rather than listed flat: one admin action
 * routinely writes several rows, and a flat list turns "someone approved a
 * batch" into thirty lines that have to be reassembled by eye.
 */

const PANEL = "rounded-xl border border-slate-800 bg-slate-900";
const FIELD =
  "rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 outline-none focus:border-sky-500";

/** Actions worth colouring, because they are the ones that matter on a scan. */
const TONE: Array<[RegExp, string]> = [
  [/^auth\.(failed|throttled)/, "bg-red-950/50 text-red-300"],
  [/^auth\./, "bg-slate-800 text-slate-400"],
  [/approved$/, "bg-emerald-950/60 text-emerald-300"],
  [/rejected$/, "bg-amber-950/50 text-amber-300"],
  [/deleted$/, "bg-red-950/50 text-red-300"],
];

const FILTERS = [
  ["", "Everything"],
  ["auth", "Sign-in"],
  ["question", "Questions"],
  ["questions.bulk", "Bulk decisions"],
  ["source", "Books"],
  ["document", "Documents"],
  ["generation_target", "Targets"],
  ["pyq", "Past papers"],
] as const;

export default function AuditPage() {
  const router = useRouter();

  const [entries, setEntries] = useState<AuditEntry[]>([]);
  const [total, setTotal] = useState(0);
  const [action, setAction] = useState("");
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async (prefix: string) => {
    try {
      const { data, meta } = await audit.load({
        action: prefix || undefined,
        per_page: 100,
      });
      setEntries(data);
      setTotal(meta.total);
      setError(null);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load the audit trail.");
    }
  }, []);

  useEffect(() => {
    if (!getToken()) {
      router.replace("/login");
      return;
    }

    void (async () => {
      try {
        await load("");
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) router.replace("/login");
      }
    })();
  }, [router, load]);

  // One admin action can write several rows; they share a request id.
  const groups = entries.reduce<Array<{ key: string; rows: AuditEntry[] }>>((acc, entry) => {
    const key = entry.request_id ?? entry.id;
    const last = acc[acc.length - 1];

    if (last && last.key === key) last.rows.push(entry);
    else acc.push({ key, rows: [entry] });

    return acc;
  }, []);

  return (
    <>
      <header className="mb-4 flex flex-wrap items-baseline gap-x-6 gap-y-1">
        <h1 className="text-lg font-semibold text-slate-100">Audit trail</h1>
        <p className="text-xs text-slate-500">
          {total} entries · append-only, enforced by the database
        </p>
        <select
          value={action}
          onChange={async (e) => {
            setAction(e.target.value);
            await load(e.target.value);
          }}
          className={`ml-auto ${FIELD}`}
        >
          {FILTERS.map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </select>
      </header>

      {error && (
        <p className="mb-4 rounded-lg border border-red-900 bg-red-950/60 px-4 py-3 text-sm text-red-300">
          {error}
        </p>
      )}

      <section className={PANEL}>
        <ul className="divide-y divide-slate-800/70">
          {groups.map((group) => (
            <li key={group.key} className="px-4 py-3">
              {group.rows.length > 1 && (
                <p className="mb-1.5 font-mono text-[10px] text-slate-600">
                  {group.rows.length} entries from one request
                </p>
              )}

              {group.rows.map((entry) => (
                <div key={entry.id} className="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-1">
                  <span
                    className={`rounded px-2 py-0.5 font-mono text-[11px] ${toneFor(entry.action)}`}
                  >
                    {entry.action}
                  </span>

                  {entry.subject_type && (
                    <span className="font-mono text-[11px] text-slate-500">
                      {entry.subject_type}
                    </span>
                  )}

                  <Changes changes={entry.changes} />

                  <span className="ml-auto flex shrink-0 gap-3 font-mono text-[11px] text-slate-600">
                    {entry.ip && <span>{entry.ip}</span>}
                    <span>{entry.created_at?.slice(0, 19).replace("T", " ")}</span>
                  </span>
                </div>
              ))}
            </li>
          ))}

          {entries.length === 0 && !error && (
            <li className="px-4 py-12 text-center text-sm text-slate-500">
              Nothing recorded for this filter yet.
            </li>
          )}
        </ul>
      </section>
    </>
  );
}

function toneFor(action: string): string {
  return TONE.find(([pattern]) => pattern.test(action))?.[1] ?? "bg-slate-800 text-slate-400";
}

/**
 * The changed fields, inline and short.
 *
 * A full JSON dump per row makes the trail unreadable; the point of a scan is
 * to find the entry worth opening, not to read every payload.
 */
function Changes({ changes }: { changes: Record<string, unknown> }) {
  const parts = Object.entries(changes ?? {})
    .slice(0, 4)
    .map(([key, value]) => {
      if (value !== null && typeof value === "object" && "old" in (value as object)) {
        const pair = value as { old: unknown; new: unknown };
        return `${key}: ${short(pair.old)} → ${short(pair.new)}`;
      }

      return `${key}: ${short(value)}`;
    });

  if (parts.length === 0) return null;

  return (
    <span className="truncate text-xs text-slate-400" title={JSON.stringify(changes, null, 2)}>
      {parts.join(" · ")}
    </span>
  );
}

function short(value: unknown): string {
  if (value === null || value === undefined) return "—";
  if (typeof value === "object") return Array.isArray(value) ? `[${value.length}]` : "{…}";

  const text = String(value);

  return text.length > 28 ? `${text.slice(0, 28)}…` : text;
}
