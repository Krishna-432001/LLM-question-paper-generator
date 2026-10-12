"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState } from "react";
import {
  ApiError,
  getToken,
  reviews,
  type Decision,
  type ReviewItem,
  type ReviewerMetrics,
  type QueueHealth,
} from "@/lib/api";

/**
 * The reviewer workstation.
 *
 * Two design decisions carry the whole screen:
 *
 *   1. The source passage is ALWAYS on screen, beside the question. A reviewer
 *      who has to go looking for the book reviews ~40 questions an hour; one
 *      who can read the cited passage without moving reviews ~200.
 *   2. The machine shows its work. Every validation verdict is visible, so the
 *      human is auditing a decision rather than redoing it from scratch.
 *
 * Every action is one keystroke and auto-advances.
 */
export default function ReviewPage() {
  const router = useRouter();

  const [item, setItem] = useState<ReviewItem | null>(null);
  const [queue, setQueue] = useState<QueueHealth | null>(null);
  const [metrics, setMetrics] = useState<ReviewerMetrics | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [empty, setEmpty] = useState(false);
  const [reasonFor, setReasonFor] = useState<Decision | null>(null);
  const [reason, setReason] = useState("");
  const [correctOverride, setCorrectOverride] = useState<string | null>(null);

  // Time-on-task feeds the throughput metric that gates auto-approval, so it
  // is measured rather than estimated.
  const startedAt = useRef<number>(Date.now());

  const loadNext = useCallback(async () => {
    setLoading(true);
    setError(null);
    setReasonFor(null);
    setReason("");
    setCorrectOverride(null);

    try {
      const [next, stats] = await Promise.all([reviews.next(), reviews.metrics()]);

      setItem(next.data);
      setEmpty(next.data === null);
      setMetrics(stats.data.me);
      setQueue(stats.data.queue);
      startedAt.current = Date.now();
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) {
        router.push("/login");
        return;
      }
      setError(e instanceof ApiError ? (e.detail ?? e.message) : String(e));
    } finally {
      setLoading(false);
    }
  }, [router]);

  useEffect(() => {
    if (!getToken()) {
      router.push("/login");
      return;
    }
    void loadNext();
  }, [loadNext, router]);

  const decide = useCallback(
    async (decision: Decision, withReason?: string) => {
      if (!item) return;

      // A rejection without a reason cannot feed prompt tuning or the
      // overgeneration auto-tuner, so the UI asks for one before sending.
      if (decision !== "approve" && !withReason) {
        setReasonFor(decision);
        return;
      }

      setLoading(true);

      try {
        await reviews.decide(item.review_id, {
          decision,
          reason: withReason,
          time_spent_seconds: Math.max(
            1,
            Math.round((Date.now() - startedAt.current) / 1000),
          ),
          edits:
            correctOverride && correctOverride !== item.question.correct_option
              ? { correct_option: correctOverride }
              : undefined,
        });

        await loadNext();
      } catch (e) {
        setError(e instanceof ApiError ? (e.detail ?? e.message) : String(e));
        setLoading(false);
      }
    },
    [item, correctOverride, loadNext],
  );

  // Keyboard-first: the whole point is not touching the mouse.
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (reasonFor || !item || loading) return;
      if (event.target instanceof HTMLInputElement) return;

      const key = event.key.toLowerCase();

      if (key === "a") void decide("approve");
      else if (key === "r") setReasonFor("reject");
      else if (key === "v") setReasonFor("revision");
      else if (["1", "2", "3", "4"].includes(key)) {
        setCorrectOverride(["A", "B", "C", "D"][Number(key) - 1]);
      }
    }

    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [decide, item, loading, reasonFor]);

  if (loading && !item) {
    return <Shell><p className="text-slate-400">Loading…</p></Shell>;
  }

  if (error) {
    return (
      <Shell>
        <div className="rounded-md border border-red-900 bg-red-950/60 p-4 text-sm text-red-300">
          {error}
        </div>
        <button onClick={() => void loadNext()} className="mt-4 text-sm text-sky-400 hover:underline">
          Retry
        </button>
      </Shell>
    );
  }

  if (empty || !item) {
    return (
      <Shell>
        <div className="rounded-lg border border-slate-800 bg-slate-900 p-8 text-center">
          <p className="text-lg font-medium text-slate-200">Queue is empty</p>
          <p className="mt-1 text-sm text-slate-400">
            Nothing is waiting for review. Generate questions to fill the queue.
          </p>
          <button onClick={() => void loadNext()} className="mt-4 text-sm text-sky-400 hover:underline">
            Check again
          </button>
        </div>
      </Shell>
    );
  }

  const q = item.question;
  const shownCorrect = correctOverride ?? q.correct_option;

  return (
    <Shell metrics={metrics} queue={queue}>
      <div className="grid gap-4 lg:grid-cols-[1fr_1fr]">
        {/* ---------------------------------------------- question pane */}
        <section className="rounded-lg border border-slate-800 bg-slate-900 p-5">
          <header className="mb-4 flex flex-wrap items-center gap-2 text-xs">
            <Badge tone="sky">quality {q.quality_score ?? "—"}</Badge>
            <Badge tone="slate">{q.difficulty}</Badge>
            <Badge tone="slate">
              confidence {q.ai_confidence !== null ? q.ai_confidence.toFixed(2) : "—"}
            </Badge>
            {correctOverride && correctOverride !== q.correct_option && (
              <Badge tone="amber">answer changed → {correctOverride}</Badge>
            )}
          </header>

          <p className="text-base leading-relaxed text-slate-100">{q.question_text}</p>

          <ul className="mt-4 space-y-2">
            {Object.entries(q.options ?? {}).map(([key, text], index) => {
              const isCorrect = key === shownCorrect;

              return (
                <li
                  key={key}
                  className={`flex gap-3 rounded-md border px-3 py-2 text-sm ${
                    isCorrect
                      ? "border-emerald-700 bg-emerald-950/40 text-emerald-200"
                      : "border-slate-800 text-slate-300"
                  }`}
                >
                  <span className="w-10 shrink-0 font-mono text-xs text-slate-500">
                    [{index + 1}] {key}
                  </span>
                  <span>{text}</span>
                </li>
              );
            })}
          </ul>

          {q.explanation && (
            <p className="mt-4 border-t border-slate-800 pt-3 text-sm text-slate-400">
              {q.explanation}
            </p>
          )}

          <div className="mt-5 flex flex-wrap gap-2 border-t border-slate-800 pt-4">
            {item.checks.map((check) => (
              <span
                key={check.code}
                title={JSON.stringify(check.detail)}
                className={`rounded px-2 py-1 font-mono text-[11px] ${
                  check.passed
                    ? "bg-emerald-950/60 text-emerald-300"
                    : "bg-red-950/60 text-red-300"
                }`}
              >
                {check.passed ? "✓" : "✗"} {check.code.replace(/^V\d+_/, "").toLowerCase()}
                {check.score !== null && ` ${check.score.toFixed(2)}`}
              </span>
            ))}
          </div>
        </section>

        {/* ------------------------------------------------ source pane */}
        <section className="rounded-lg border border-slate-800 bg-slate-900 p-5">
          <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
            Source
          </h2>

          {item.source.length === 0 && (
            <p className="text-sm text-red-400">
              No citation. This question cannot be approved.
            </p>
          )}

          {item.source.map((source, index) => (
            <article key={index} className="mb-4">
              <p className="text-xs text-slate-500">{source.citation}</p>

              {source.supporting_quote && (
                <p className="mt-2 border-l-2 border-amber-600 bg-amber-950/20 py-1 pl-3 text-sm text-amber-200">
                  “{source.supporting_quote}”
                </p>
              )}

              {source.passage && (
                <p className="mt-3 max-h-80 overflow-y-auto whitespace-pre-wrap text-sm leading-relaxed text-slate-400">
                  {source.passage}
                </p>
              )}
            </article>
          ))}
        </section>
      </div>

      {/* --------------------------------------------------- action bar */}
      {reasonFor ? (
        <form
          onSubmit={(e) => {
            e.preventDefault();
            if (reason.trim()) void decide(reasonFor, reason.trim());
          }}
          className="mt-4 flex gap-2 rounded-lg border border-slate-800 bg-slate-900 p-3"
        >
          <input
            autoFocus
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder={`Why ${reasonFor}? (feeds prompt tuning)`}
            className="flex-1 rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 outline-none focus:border-sky-500"
          />
          <button
            type="submit"
            className="rounded-md bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-600"
          >
            Confirm
          </button>
          <button
            type="button"
            onClick={() => setReasonFor(null)}
            className="rounded-md border border-slate-700 px-4 py-2 text-sm text-slate-300"
          >
            Cancel
          </button>
        </form>
      ) : (
        <div className="mt-4 flex flex-wrap items-center gap-2 rounded-lg border border-slate-800 bg-slate-900 p-3">
          <Action onClick={() => void decide("approve")} tone="emerald" hotkey="A">
            Approve
          </Action>
          <Action onClick={() => setReasonFor("reject")} tone="red" hotkey="R">
            Reject
          </Action>
          <Action onClick={() => setReasonFor("revision")} tone="amber" hotkey="V">
            Needs revision
          </Action>
          <span className="ml-auto text-xs text-slate-500">
            press 1–4 to change the correct option
          </span>
        </div>
      )}
    </Shell>
  );
}

function Shell({
  children,
  metrics,
  queue,
}: {
  children: React.ReactNode;
  metrics?: ReviewerMetrics | null;
  queue?: QueueHealth | null;
}) {
  return (
    <main className="min-h-screen bg-slate-950 p-4 text-slate-200 lg:p-6">
      <header className="mb-4 flex flex-wrap items-baseline gap-x-6 gap-y-1">
        <h1 className="text-sm font-semibold text-slate-100">Reviewer console</h1>

        {queue && (
          <p className="text-xs text-slate-500">
            {queue.pending} pending · {queue.decided_24h} decided in 24h
            {queue.oldest_hours !== null && ` · oldest ${queue.oldest_hours}h`}
          </p>
        )}

        {metrics && (
          <p className="text-xs text-slate-500">
            you: {metrics.sample} reviewed
            {metrics.per_hour && ` · ${metrics.per_hour}/hr`} · override{" "}
            {(metrics.override_rate * 100).toFixed(1)}%
            {/* Automation is earned, and the console says how far off it is. */}
            <span className={metrics.auto_approve_eligible ? "text-emerald-400" : "text-slate-600"}>
              {" "}
              · auto-approve {metrics.auto_approve_eligible ? "eligible" : "locked"}
            </span>
          </p>
        )}
      </header>

      {children}
    </main>
  );
}

function Badge({ children, tone }: { children: React.ReactNode; tone: string }) {
  const tones: Record<string, string> = {
    sky: "bg-sky-950/60 text-sky-300",
    slate: "bg-slate-800 text-slate-400",
    amber: "bg-amber-950/60 text-amber-300",
  };

  return <span className={`rounded px-2 py-0.5 ${tones[tone]}`}>{children}</span>;
}

function Action({
  children,
  onClick,
  tone,
  hotkey,
}: {
  children: React.ReactNode;
  onClick: () => void;
  tone: string;
  hotkey: string;
}) {
  const tones: Record<string, string> = {
    emerald: "bg-emerald-700 hover:bg-emerald-600",
    red: "bg-red-800 hover:bg-red-700",
    amber: "bg-amber-800 hover:bg-amber-700",
  };

  return (
    <button
      onClick={onClick}
      className={`flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium text-white ${tones[tone]}`}
    >
      {children}
      <kbd className="rounded bg-black/30 px-1.5 py-0.5 font-mono text-[10px]">{hotkey}</kbd>
    </button>
  );
}
