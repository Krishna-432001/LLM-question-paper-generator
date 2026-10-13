import type { ReactNode } from "react";

/**
 * Shared shell for the admin surface.
 *
 * The platform has exactly one kind of user — an operator running books
 * through a pipeline — so the chrome stays out of the way: one bar, three
 * destinations, no dashboard-of-dashboards.
 */
export default function AdminLayout({ children }: { children: ReactNode }) {
  return (
    <div className="min-h-screen bg-slate-950 text-slate-200">
      <nav className="border-b border-slate-800 bg-slate-900/60 backdrop-blur">
        <div className="mx-auto flex max-w-[1400px] items-center gap-6 px-4 py-2.5 lg:px-6">
          <span className="flex items-center gap-2 text-sm font-semibold text-slate-100">
            <span className="grid h-6 w-6 place-items-center rounded bg-sky-600 font-mono text-[11px] text-white">
              EF
            </span>
            ExamForge
          </span>

          <div className="flex items-center gap-1 text-xs">
            <NavLink href="/admin/dashboard">Overview</NavLink>
            <NavLink href="/admin">Books</NavLink>
            <NavLink href="/admin/exams">Exams</NavLink>
            <NavLink href="/admin/syllabus">Syllabus</NavLink>
            <NavLink href="/admin/pyq">Past papers</NavLink>
            <NavLink href="/admin/backlog">Backlog</NavLink>
            <NavLink href="/admin/translations">Translations</NavLink>
            <NavLink href="/admin/ai">AI history</NavLink>
            <NavLink href="/admin/audit">Audit</NavLink>
            <NavLink href="/review">Review queue</NavLink>
          </div>
        </div>
      </nav>

      <div className="mx-auto max-w-[1400px] px-4 py-5 lg:px-6">{children}</div>
    </div>
  );
}

function NavLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <a
      href={href}
      className="rounded-md px-3 py-1.5 text-slate-400 transition-colors hover:bg-slate-800 hover:text-slate-100"
    >
      {children}
    </a>
  );
}
