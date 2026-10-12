"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { auth, getToken } from "@/lib/api";
import { homeFor } from "@/lib/routing";

/** Sends each person to their own home: admin console, review queue or student app. */
export default function Home() {
  const router = useRouter();

  useEffect(() => {
    if (!getToken()) {
      router.replace("/login");
      return;
    }

    void (async () => {
      try {
        const { data } = await auth.me();
        router.replace(homeFor(data));
      } catch {
        router.replace("/login");
      }
    })();
  }, [router]);

  return <p className="p-10 text-center text-sm text-slate-400">Loading…</p>;
}
