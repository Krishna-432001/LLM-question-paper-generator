"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import {
  ApiError,
  catalogue,
  examAdmin,
  getToken,
  type ExamDetail,
  type ExamPaperRow,
  type ExamRow,
  type Language,
  type SyllabusTree,
} from "@/lib/api";

/**
 * The exam catalogue: what each exam is, how its paper is shaped, and the
 * syllabus the generator and selector work against.
 *
 * Creating an exam builds its whole spine server-side (version, paper,
 * subjects, a draft syllabus), so this screen only asks for what a person
 * would read off the notification.
 */

const PANEL = "rounded-xl border border-slate-800 bg-slate-900";
const FIELD =
  "w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 outline-none focus:border-sky-500";
const LABEL = "mb-1 block text-[11px] font-medium uppercase tracking-wide text-slate-500";
const BUTTON =
  "rounded-md bg-sky-600 px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-sky-500 disabled:cursor-not-allowed disabled:opacity-50";
const GHOST =
  "rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-300 transition-colors hover:bg-slate-800";

function message(e: unknown): string {
  if (e instanceof ApiError) {
    const first = e.errors ? Object.values(e.errors)[0]?.[0] : undefined;
    return first ?? e.detail ?? e.message;
  }
  return e instanceof Error ? e.message : "Something went wrong.";
}

export default function ExamsPage() {
  const router = useRouter();

  const [exams, setExams] = useState<ExamRow[]>([]);
  const [languages, setLanguages] = useState<Language[]>([]);
  const [selected, setSelected] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const loadList = useCallback(async (focus?: string) => {
    const { data } = await examAdmin.list();
    setExams(data);
    setSelected((current) => focus ?? current ?? data[0]?.id ?? null);
  }, []);

  useEffect(() => {
    if (!getToken()) {
      router.replace("/login");
      return;
    }

    (async () => {
      try {
        const [, langs] = await Promise.all([loadList(), catalogue.languages()]);
        setLanguages(langs.data);
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) router.replace("/login");
        else setError(message(e));
      }
    })();
  }, [router, loadList]);

  return (
    <>
      <header className="mb-4 flex flex-wrap items-baseline gap-x-6 gap-y-1">
        <h1 className="text-lg font-semibold text-slate-100">Exams</h1>
        <p className="text-xs text-slate-500">Paper pattern, dates and syllabus for each exam</p>
        <button
          className={`ml-auto ${BUTTON}`}
          onClick={() => {
            setCreating(true);
            setError(null);
          }}
        >
          New exam
        </button>
      </header>

      {error && (
        <p className="mb-4 rounded-lg border border-red-900 bg-red-950/60 px-4 py-3 text-sm text-red-300">
          {error}
        </p>
      )}

      <div className="grid gap-4 lg:grid-cols-[300px_1fr]">
        <section className={`${PANEL} h-fit`}>
          <ul className="divide-y divide-slate-800/70">
            {exams.map((exam) => (
              <li key={exam.id}>
                <button
                  onClick={() => {
                    setSelected(exam.id);
                    setCreating(false);
                  }}
                  className={`flex w-full flex-col items-start gap-1 px-4 py-3 text-left transition-colors hover:bg-slate-800/60 ${
                    selected === exam.id && !creating ? "bg-slate-800/80" : ""
                  }`}
                >
                  <span className="text-sm text-slate-100">{exam.name}</span>
                  <span className="flex flex-wrap gap-1.5 font-mono text-[10px]">
                    <span className="text-slate-500">{exam.code}</span>
                    {exam.is_platform && (
                      <span className="rounded bg-sky-950/70 px-1.5 text-sky-300">platform</span>
                    )}
                    {!exam.is_active && (
                      <span className="rounded bg-slate-800 px-1.5 text-slate-400">inactive</span>
                    )}
                  </span>
                </button>
              </li>
            ))}
            {exams.length === 0 && (
              <li className="px-4 py-8 text-center text-sm text-slate-500">No exams yet.</li>
            )}
          </ul>
        </section>

        {creating ? (
          <CreateExam
            languages={languages}
            onCancel={() => setCreating(false)}
            onCreated={async (id) => {
              setCreating(false);
              await loadList(id);
            }}
          />
        ) : selected ? (
          <ExamEditor key={selected} examId={selected} onSaved={() => loadList()} />
        ) : null}
      </div>
    </>
  );
}

function ExamEditor({ examId, onSaved }: { examId: string; onSaved: () => void }) {
  const [exam, setExam] = useState<ExamDetail | null>(null);
  const [tree, setTree] = useState<SyllabusTree | null>(null);
  const [form, setForm] = useState<Record<string, string | boolean>>({});
  const [status, setStatus] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const apply = (detail: ExamDetail) => {
    setExam(detail);
    setForm({
      name: detail.name,
      conducting_body: detail.conducting_body ?? "",
      state: detail.state ?? "",
      category: detail.category ?? "",
      exam_date: detail.active_version?.exam_date ?? "",
      notification_date: detail.active_version?.notification_date ?? "",
      is_active: detail.is_active,
    });
  };

  useEffect(() => {
    (async () => {
      try {
        const [detail, syllabus] = await Promise.all([
          examAdmin.show(examId),
          examAdmin.syllabus(examId),
        ]);
        apply(detail.data);
        setTree(syllabus.data);
      } catch (e) {
        setError(message(e));
      }
    })();
  }, [examId]);

  if (!exam) {
    return <section className={`${PANEL} p-6 text-sm text-slate-500`}>{error ?? "Loading…"}</section>;
  }

  const save = async () => {
    setError(null);
    setStatus(null);
    try {
      const payload: Record<string, unknown> = {
        ...form,
        exam_date: form.exam_date || null,
        notification_date: form.notification_date || null,
      };
      const { data } = await examAdmin.update(exam.id, payload);
      apply(data);
      setStatus("Saved.");
      onSaved();
    } catch (e) {
      setError(message(e));
    }
  };

  const text = (key: string) => (form[key] as string) ?? "";

  return (
    <div className="space-y-4">
      <section className={`${PANEL} p-4`}>
        <div className="mb-3 flex items-center gap-3">
          <h2 className="text-sm font-semibold text-slate-100">Details</h2>
          <span className="font-mono text-[11px] text-slate-500">{exam.code}</span>
          {exam.is_platform && (
            <span className="rounded bg-sky-950/70 px-1.5 font-mono text-[10px] text-sky-300">
              shared with every organization
            </span>
          )}
        </div>

        <div className="grid gap-3 md:grid-cols-2">
          <label>
            <span className={LABEL}>Name</span>
            <input className={FIELD} value={text("name")} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          </label>
          <label>
            <span className={LABEL}>Conducting body</span>
            <input
              className={FIELD}
              value={text("conducting_body")}
              onChange={(e) => setForm({ ...form, conducting_body: e.target.value })}
            />
          </label>
          <label>
            <span className={LABEL}>Notification date</span>
            <input
              type="date"
              className={FIELD}
              value={text("notification_date")}
              onChange={(e) => setForm({ ...form, notification_date: e.target.value })}
            />
          </label>
          <label>
            <span className={LABEL}>Exam date</span>
            <input
              type="date"
              className={FIELD}
              value={text("exam_date")}
              onChange={(e) => setForm({ ...form, exam_date: e.target.value })}
            />
          </label>
          <label>
            <span className={LABEL}>State</span>
            <input className={FIELD} value={text("state")} onChange={(e) => setForm({ ...form, state: e.target.value })} />
          </label>
          <label>
            <span className={LABEL}>Category</span>
            <input
              className={FIELD}
              value={text("category")}
              onChange={(e) => setForm({ ...form, category: e.target.value })}
            />
          </label>
        </div>

        <div className="mt-4 flex flex-wrap items-center gap-3">
          <label className="flex items-center gap-2 text-sm text-slate-300">
            <input
              type="checkbox"
              checked={Boolean(form.is_active)}
              onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
            />
            Active (visible to students)
          </label>
          <button className={`ml-auto ${BUTTON}`} onClick={save}>
            Save details
          </button>
        </div>

        {status && <p className="mt-2 text-xs text-emerald-400">{status}</p>}
        {error && <p className="mt-2 text-xs text-red-400">{error}</p>}
      </section>

      {exam.active_version?.papers.map((paper) => (
        <PaperEditor key={paper.id} examId={exam.id} paper={paper} onSaved={apply} />
      ))}

      {exam.active_version && exam.active_version.languages.length > 0 && (
        <section className={`${PANEL} px-4 py-3 text-sm text-slate-400`}>
          <span className={LABEL}>Languages</span>
          <div className="flex flex-wrap gap-1.5">
            {exam.active_version.languages.map((l) => (
              <span key={l.id} className="rounded bg-slate-800 px-2 py-0.5 text-xs text-slate-300">
                {l.name}
                {l.is_default && " · default"}
              </span>
            ))}
          </div>
        </section>
      )}

      <SyllabusView examId={exam.id} tree={tree} onTree={setTree} />
    </div>
  );
}

function PaperEditor({
  examId,
  paper,
  onSaved,
}: {
  examId: string;
  paper: ExamPaperRow;
  onSaved: (detail: ExamDetail) => void;
}) {
  const [values, setValues] = useState({
    duration_minutes: String(paper.duration_minutes ?? ""),
    total_questions: String(paper.total_questions ?? ""),
    total_marks: String(paper.total_marks ?? ""),
    negative_marking: String(paper.negative_marking),
  });
  const [note, setNote] = useState<string | null>(null);

  const save = async () => {
    setNote(null);
    try {
      const payload = Object.fromEntries(
        Object.entries(values)
          .filter(([, v]) => v !== "")
          .map(([k, v]) => [k, Number(v)]),
      );
      const { data } = await examAdmin.updatePaper(examId, paper.id, payload);
      onSaved(data);
      setNote("Saved.");
    } catch (e) {
      setNote(message(e));
    }
  };

  return (
    <section className={`${PANEL} p-4`}>
      <h2 className="mb-3 text-sm font-semibold text-slate-100">
        {paper.stage} · {paper.name}
      </h2>

      <div className="grid gap-3 sm:grid-cols-4">
        {(
          [
            ["duration_minutes", "Minutes"],
            ["total_questions", "Questions"],
            ["total_marks", "Marks"],
            ["negative_marking", "Negative per wrong"],
          ] as const
        ).map(([key, label]) => (
          <label key={key}>
            <span className={LABEL}>{label}</span>
            <input
              inputMode="decimal"
              className={FIELD}
              value={values[key]}
              onChange={(e) => setValues({ ...values, [key]: e.target.value })}
            />
          </label>
        ))}
      </div>

      {paper.subjects.length > 0 && (
        <ul className="mt-4 divide-y divide-slate-800/70 rounded-lg border border-slate-800">
          {paper.subjects.map((s) => (
            <li key={s.id} className="flex items-center gap-3 px-3 py-2 text-sm">
              <span className="flex-1 text-slate-300">{s.name}</span>
              <span className="font-mono text-[11px] text-slate-500">
                {s.question_count ?? "—"} questions · {(s.weight * 100).toFixed(1)}%
              </span>
            </li>
          ))}
        </ul>
      )}

      <div className="mt-3 flex items-center gap-3">
        {note && <span className="text-xs text-slate-400">{note}</span>}
        <button className={`ml-auto ${GHOST}`} onClick={save}>
          Save paper
        </button>
      </div>
    </section>
  );
}

function SyllabusView({
  examId,
  tree,
  onTree,
}: {
  examId: string;
  tree: SyllabusTree | null;
  onTree: (tree: SyllabusTree) => void;
}) {
  if (!tree) return null;

  const total = tree.units.reduce((sum, u) => sum + u.weight, 0);

  return (
    <section className={PANEL}>
      <div className="flex flex-wrap items-center gap-3 border-b border-slate-800 px-4 py-2.5">
        <h2 className="text-sm font-semibold text-slate-100">Syllabus</h2>
        {tree.versions.length > 0 && (
          <select
            className="rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-200"
            value={tree.selected_version_id ?? ""}
            onChange={async (e) => onTree((await examAdmin.syllabus(examId, e.target.value)).data)}
          >
            {tree.versions.map((v) => (
              <option key={v.id} value={v.id}>
                v{v.version_number} · {v.status}
              </option>
            ))}
          </select>
        )}
        <a href={`/admin/syllabus?exam=${examId}`} className="ml-auto text-xs text-sky-400 hover:text-sky-300">
          Edit syllabus →
        </a>
        <span className="font-mono text-[11px] text-slate-500">
          weights total {(total * 100).toFixed(0)}%
        </span>
      </div>

      <ul className="divide-y divide-slate-800/70">
        {tree.units.map((unit) => (
          <li key={unit.id} className="px-4 py-3">
            <div className="flex items-center gap-3">
              <span className="flex-1 text-sm text-slate-200">{unit.name}</span>
              <span className="h-1.5 w-32 overflow-hidden rounded-full bg-slate-800">
                <span className="block h-1.5 bg-sky-500" style={{ width: `${Math.min(100, unit.weight * 400)}%` }} />
              </span>
              <span className="w-14 text-right font-mono text-[11px] text-slate-400">
                {(unit.weight * 100).toFixed(1)}%
              </span>
            </div>
            <p className="mt-1 text-xs text-slate-500">{unit.topics.map((t) => t.name).join(" · ")}</p>
          </li>
        ))}
        {tree.units.length === 0 && (
          <li className="px-4 py-8 text-center text-sm text-slate-500">
            This syllabus version has no units yet.
          </li>
        )}
      </ul>
    </section>
  );
}

function CreateExam({
  languages,
  onCancel,
  onCreated,
}: {
  languages: Language[];
  onCancel: () => void;
  onCreated: (id: string) => void;
}) {
  const [form, setForm] = useState({
    code: "",
    name: "",
    conducting_body: "",
    state: "",
    exam_date: "",
    duration_minutes: "180",
    total_questions: "200",
    total_marks: "300",
    negative_marking: "0",
    platform: true,
  });
  const [languageIds, setLanguageIds] = useState<string[]>([]);
  const [subjects, setSubjects] = useState([{ name: "", question_count: "" }]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      const { data } = await examAdmin.create({
        code: form.code,
        name: form.name,
        conducting_body: form.conducting_body || null,
        state: form.state || null,
        exam_date: form.exam_date || null,
        platform: form.platform,
        language_ids: languageIds,
        paper: {
          duration_minutes: Number(form.duration_minutes),
          total_questions: Number(form.total_questions),
          total_marks: Number(form.total_marks) || undefined,
          negative_marking: Number(form.negative_marking) || 0,
        },
        subjects: subjects
          .filter((s) => s.name.trim() !== "")
          .map((s) => ({ name: s.name.trim(), question_count: Number(s.question_count) || 0 })),
      });
      onCreated(data.id);
    } catch (e) {
      setError(message(e));
    } finally {
      setBusy(false);
    }
  };

  const input = (key: keyof typeof form, label: string, type = "text") => (
    <label>
      <span className={LABEL}>{label}</span>
      <input
        type={type}
        className={FIELD}
        value={String(form[key])}
        onChange={(e) => setForm({ ...form, [key]: e.target.value })}
      />
    </label>
  );

  return (
    <section className={`${PANEL} p-4`}>
      <h2 className="mb-3 text-sm font-semibold text-slate-100">New exam</h2>

      <div className="grid gap-3 md:grid-cols-2">
        {input("code", "Code (e.g. TNPSC_G2)")}
        {input("name", "Name")}
        {input("conducting_body", "Conducting body")}
        {input("state", "State (e.g. TN)")}
        {input("exam_date", "Exam date", "date")}
      </div>

      <h3 className="mb-2 mt-5 text-xs font-semibold uppercase tracking-wide text-slate-400">Paper</h3>
      <div className="grid gap-3 sm:grid-cols-4">
        {input("duration_minutes", "Minutes")}
        {input("total_questions", "Questions")}
        {input("total_marks", "Marks")}
        {input("negative_marking", "Negative per wrong")}
      </div>

      <h3 className="mb-2 mt-5 text-xs font-semibold uppercase tracking-wide text-slate-400">Subjects</h3>
      <div className="space-y-2">
        {subjects.map((s, i) => (
          <div key={i} className="flex gap-2">
            <input
              className={FIELD}
              placeholder="Subject name"
              value={s.name}
              onChange={(e) => setSubjects(subjects.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))}
            />
            <input
              className={`${FIELD} max-w-32`}
              placeholder="Questions"
              inputMode="numeric"
              value={s.question_count}
              onChange={(e) =>
                setSubjects(subjects.map((x, j) => (j === i ? { ...x, question_count: e.target.value } : x)))
              }
            />
          </div>
        ))}
        <button className={GHOST} onClick={() => setSubjects([...subjects, { name: "", question_count: "" }])}>
          Add subject
        </button>
      </div>

      <h3 className="mb-2 mt-5 text-xs font-semibold uppercase tracking-wide text-slate-400">
        Languages (first selected is the default)
      </h3>
      <div className="flex flex-wrap gap-2">
        {languages.map((l) => {
          const on = languageIds.includes(l.id);
          return (
            <button
              key={l.id}
              onClick={() => setLanguageIds(on ? languageIds.filter((id) => id !== l.id) : [...languageIds, l.id])}
              className={`rounded-md border px-2.5 py-1 text-xs ${
                on ? "border-sky-500 bg-sky-950/60 text-sky-200" : "border-slate-700 text-slate-400"
              }`}
            >
              {l.name}
            </button>
          );
        })}
      </div>

      <label className="mt-5 flex items-center gap-2 text-sm text-slate-300">
        <input
          type="checkbox"
          checked={form.platform}
          onChange={(e) => setForm({ ...form, platform: e.target.checked })}
        />
        Platform exam, shared with every organization (platform admins only)
      </label>

      {error && <p className="mt-3 text-sm text-red-400">{error}</p>}

      <div className="mt-4 flex justify-end gap-2">
        <button className={GHOST} onClick={onCancel}>
          Cancel
        </button>
        <button className={BUTTON} disabled={busy || !form.code || !form.name} onClick={submit}>
          {busy ? "Creating…" : "Create exam"}
        </button>
      </div>
    </section>
  );
}
