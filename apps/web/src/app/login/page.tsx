"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { ApiError, auth, setToken } from "@/lib/api";

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      const { data } = await auth.login(email, password);
      setToken(data.token);
      router.push("/review");
    } catch (e) {
      setError(
        e instanceof ApiError
          ? (e.detail ?? e.message)
          : "Could not reach the API. Is it running?",
      );
      setBusy(false);
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-950 p-6">
      <form
        onSubmit={submit}
        className="w-full max-w-sm space-y-5 rounded-xl border border-slate-800 bg-slate-900 p-8"
      >
        <div>
          <h1 className="text-xl font-semibold text-slate-100">ExamForge</h1>
          <p className="mt-1 text-sm text-slate-400">Reviewer console</p>
        </div>

        <label className="block space-y-1.5">
          <span className="text-xs font-medium text-slate-400">Email</span>
          <input
            type="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            className="w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 outline-none focus:border-sky-500"
          />
        </label>

        <label className="block space-y-1.5">
          <span className="text-xs font-medium text-slate-400">Password</span>
          <input
            type="password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className="w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 outline-none focus:border-sky-500"
          />
        </label>

        {error && (
          <p className="rounded-md border border-red-900 bg-red-950/60 px-3 py-2 text-sm text-red-300">
            {error}
          </p>
        )}

        <button
          type="submit"
          disabled={busy}
          className="w-full rounded-md bg-sky-600 px-3 py-2 text-sm font-medium text-white hover:bg-sky-500 disabled:opacity-50"
        >
          {busy ? "Signing in…" : "Sign in"}
        </button>
      </form>
    </main>
  );
}
