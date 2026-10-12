"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { ApiError, authApi, setToken } from "@/lib/api";
import { I18nProvider, useI18n } from "@/lib/i18n";

/**
 * Student sign-up. The API provisions a personal organization and the free
 * plan on registration, so a new student lands straight in the app.
 */
export default function RegisterPage() {
  return (
    <I18nProvider>
      <RegisterForm />
    </I18nProvider>
  );
}

function RegisterForm() {
  const { t, locale, setLocale } = useI18n();
  const router = useRouter();
  const [form, setForm] = useState({ name: "", email: "", password: "", password_confirmation: "" });
  const [errors, setErrors] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors([]);

    try {
      const { data } = await authApi.register(form);
      setToken(data.token);
      router.push("/app");
    } catch (e) {
      setErrors(
        e instanceof ApiError
          ? e.errors
            ? Object.values(e.errors).flat()
            : [e.detail ?? e.message]
          : ["Could not reach the server."],
      );
      setBusy(false);
    }
  }

  const field = (key: keyof typeof form, label: string, type = "text") => (
    <label className="block space-y-1.5">
      <span className="text-xs font-medium text-slate-600">{label}</span>
      <input
        type={type}
        required
        value={form[key]}
        onChange={(e) => setForm({ ...form, [key]: e.target.value })}
        className="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
      />
    </label>
  );

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
      <form onSubmit={submit} className="w-full max-w-sm space-y-4 rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
        <div className="flex items-start justify-between">
          <div>
            <h1 className="text-xl font-semibold text-slate-900">ExamForge</h1>
            <p className="mt-1 text-sm text-slate-500">{t("auth.register")}</p>
          </div>
          <select
            value={locale}
            onChange={(e) => setLocale(e.target.value as "en" | "ta")}
            className="rounded-lg border border-slate-300 px-2 py-1 text-xs"
            aria-label={t("lang.label")}
          >
            <option value="en">English</option>
            <option value="ta">தமிழ்</option>
          </select>
        </div>

        {field("name", t("auth.name"))}
        {field("email", t("auth.email"), "email")}
        {field("password", t("auth.password"), "password")}
        {field("password_confirmation", t("auth.confirm"), "password")}
        <p className="text-xs text-slate-500">{t("auth.passwordHint")}</p>

        {errors.length > 0 && (
          <ul className="space-y-1 rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">
            {errors.map((err) => (
              <li key={err}>{err}</li>
            ))}
          </ul>
        )}

        <button
          type="submit"
          disabled={busy}
          className="w-full rounded-xl bg-indigo-600 px-3 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
        >
          {busy ? t("common.loading") : t("auth.register")}
        </button>

        <a href="/login" className="block text-center text-xs text-indigo-600 hover:underline">
          {t("auth.haveAccount")}
        </a>
      </form>
    </main>
  );
}
