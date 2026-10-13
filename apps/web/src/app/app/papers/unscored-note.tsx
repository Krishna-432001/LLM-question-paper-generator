"use client";

import { useEffect, useState } from "react";
import { practicePapers } from "@/lib/api";
import { useI18n } from "@/lib/i18n";

/**
 * Shown on a result whose paper had questions with no answer key.
 *
 * Without it, a paper uploaded with no key reads as a score of zero, which
 * says the student got everything wrong when the truth is that nothing could
 * be marked. Kept here, beside the upload page, so the result screen carries
 * a single line for it.
 */
export function UnscoredNote({ attemptId }: { attemptId: string }) {
  const { t } = useI18n();
  const [unscored, setUnscored] = useState(0);

  useEffect(() => {
    let live = true;

    void (async () => {
      try {
        const { data } = await practicePapers.scoring(attemptId);
        if (live) setUnscored(data.unscored);
      } catch {
        // Only a note: the result above is still right without it.
      }
    })();

    return () => {
      live = false;
    };
  }, [attemptId]);

  if (unscored === 0) return null;

  return (
    <p className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      <span className="font-semibold">{t("papers.unscored")}.</span> {unscored} {t("papers.unscoredCount")}
    </p>
  );
}
