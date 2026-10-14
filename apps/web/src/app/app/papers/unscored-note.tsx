"use client";

import { useEffect, useState } from "react";
import { practicePapers, type AttemptScoring } from "@/lib/api";
import { useI18n } from "@/lib/i18n";

/**
 * How many questions on a submitted attempt had no answer key.
 *
 * The result screen needs it twice: to say those questions were not scored,
 * and to stop showing a score and accuracy of 0 when nothing could be
 * marked — a 0 there says every answer was wrong, which nobody knows.
 */
export function useScoring(attemptId: string | null | undefined): AttemptScoring | null {
  const [scoring, setScoring] = useState<AttemptScoring | null>(null);

  useEffect(() => {
    if (!attemptId) return;
    let live = true;

    void (async () => {
      try {
        const { data } = await practicePapers.scoring(attemptId);
        if (live) setScoring(data);
      } catch {
        // Only a refinement: the result is still right without it.
      }
    })();

    return () => {
      live = false;
    };
  }, [attemptId]);

  return scoring;
}

/** Kept beside the upload page so the result screen carries one line for it. */
export function UnscoredNote({ scoring }: { scoring: AttemptScoring | null }) {
  const { t } = useI18n();

  if (!scoring || scoring.unscored === 0) return null;

  return (
    <p className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      <span className="font-semibold">{t("papers.unscored")}.</span> {scoring.unscored} {t("papers.unscoredCount")}
    </p>
  );
}
