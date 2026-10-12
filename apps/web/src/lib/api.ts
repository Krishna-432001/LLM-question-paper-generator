/**
 * Typed client for the ExamForge API.
 *
 * Every call goes through here so the token, the error envelope and the base
 * URL are handled in exactly one place — a component never builds a fetch.
 */

/**
 * An explicit NEXT_PUBLIC_API_URL wins. Otherwise the API is assumed to be on
 * port 8000 of whatever host served this page, so opening the console from
 * another machine on the LAN works without editing config when the IP changes.
 */
function apiBase(): string {
  if (process.env.NEXT_PUBLIC_API_URL) return process.env.NEXT_PUBLIC_API_URL;
  if (typeof window === "undefined") return "http://127.0.0.1:8000/api/v1";
  return `${window.location.protocol}//${window.location.hostname}:8000/api/v1`;
}

const BASE = apiBase();

const TOKEN_KEY = "examforge.token";

export function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return window.localStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string | null): void {
  if (typeof window === "undefined") return;
  if (token) window.localStorage.setItem(TOKEN_KEY, token);
  else window.localStorage.removeItem(TOKEN_KEY);
}

/** RFC 7807 problem+json, which is what the API returns for every failure. */
export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly detail?: string,
    readonly errors?: Record<string, string[]>,
  ) {
    super(message);
  }
}

async function request<T>(
  path: string,
  init: RequestInit = {},
): Promise<T> {
  const token = getToken();

  const response = await fetch(`${BASE}${path}`, {
    ...init,
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...init.headers,
    },
  });

  if (response.status === 204) return undefined as T;

  const body = await response.json().catch(() => null);

  if (!response.ok) {
    throw new ApiError(
      body?.title ?? "Request failed",
      response.status,
      body?.detail,
      body?.errors ?? undefined,
    );
  }

  return body as T;
}

export const api = {
  get: <T>(path: string) => request<T>(path),
  post: <T>(path: string, payload?: unknown) =>
    request<T>(path, {
      method: "POST",
      body: payload === undefined ? undefined : JSON.stringify(payload),
    }),
  patch: <T>(path: string, payload?: unknown) =>
    request<T>(path, {
      method: "PATCH",
      body: payload === undefined ? undefined : JSON.stringify(payload),
    }),
  delete: <T>(path: string) => request<T>(path, { method: "DELETE" }),
};

// ---------------------------------------------------------------- types

export type Envelope<T> = { data: T; meta?: Record<string, unknown> };

export interface Me {
  id: string;
  name: string;
  email: string;
  roles: string[];
  organization: { id: string; name: string | null; plan: string | null };
}

export interface ReviewCheck {
  code: string;
  passed: boolean;
  score: number | null;
  detail: Record<string, unknown>;
}

export interface ReviewSource {
  citation: string;
  supporting_quote: string | null;
  page_start: number | null;
  page_end: number | null;
  passage: string | null;
}

export interface ReviewItem {
  review_id: string;
  claim_expires_at: string | null;
  question: {
    id: string;
    status: string;
    difficulty: string;
    quality_score: number | null;
    ai_confidence: number | null;
    question_text: string | null;
    options: Record<string, string> | null;
    correct_option: string | null;
    explanation: string | null;
  };
  source: ReviewSource[];
  checks: ReviewCheck[];
}

export interface QueueRow {
  review_id: string;
  question_id: string;
  priority: number;
  quality_score: number | null;
  preview: string;
  waiting_since: string | null;
}

export interface QueueHealth {
  pending: number;
  in_review: number;
  oldest_hours: number | null;
  decided_24h: number;
}

export interface ReviewerMetrics {
  sample: number;
  approved: number;
  rejected: number;
  edited: number;
  override_rate: number;
  agreement_rate: number;
  median_seconds: number | null;
  per_hour: number | null;
  auto_approve_eligible: boolean;
}

export type Decision = "approve" | "reject" | "revision";

export interface DecisionPayload {
  decision: Decision;
  reason?: string;
  time_spent_seconds?: number;
  edits?: {
    question_text?: string;
    options?: Record<string, string>;
    correct_option?: string;
    explanation?: string;
    difficulty?: string;
  };
}

// ---------------------------------------------------------------- calls

export const reviews = {
  queue: () => api.get<Envelope<QueueRow[]> & { meta: QueueHealth }>("/reviews"),
  next: () => api.post<Envelope<ReviewItem | null>>("/reviews/next"),
  show: (id: string) => api.get<Envelope<ReviewItem>>(`/reviews/${id}`),
  decide: (id: string, payload: DecisionPayload) =>
    api.post<Envelope<{ question_id: string; status: string; version: number }>>(
      `/reviews/${id}/decide`,
      payload,
    ),
  metrics: () =>
    api.get<Envelope<{ me: ReviewerMetrics; scope: ReviewerMetrics; queue: QueueHealth }>>(
      "/reviews/metrics",
    ),
};

export const auth = {
  login: (email: string, password: string) =>
    api.post<Envelope<{ user: { id: string; name: string }; token: string }>>(
      "/auth/login",
      { email, password },
    ),
  me: () => api.get<Envelope<Me>>("/me"),
};

// ---------------------------------------------------------------- admin flow

/**
 * Multipart upload. Deliberately not routed through request(): setting
 * Content-Type by hand strips the multipart boundary the browser generates,
 * and the upload then arrives with no file attached.
 */
async function upload<T>(path: string, form: FormData): Promise<T> {
  const token = getToken();

  const response = await fetch(`${BASE}${path}`, {
    method: "POST",
    body: form,
    headers: {
      Accept: "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });

  const body = await response.json().catch(() => null);

  if (!response.ok) {
    throw new ApiError(
      body?.title ?? "Upload failed",
      response.status,
      body?.detail,
      body?.errors ?? undefined,
    );
  }

  return body as T;
}

export interface SourceRow {
  id: string;
  kind: string;
  title: string;
  status: string;
  language_id: string;
  page_count: number | null;
  file_size_bytes: number | null;
  is_platform: boolean;
  created_at: string | null;
}

export interface QuestionCounts {
  total: number;
  approved: number;
  pending_review: number;
  rejected: number;
}

export interface SourceDetail extends SourceRow {
  pipeline_state: Record<string, unknown> | null;
  questions: QuestionCounts;
}

export interface JobRow {
  id: string;
  type: string;
  status: string;
  status_label: string;
  progress: number;
  progress_message: string | null;
  /** Present only once a job has actually failed. */
  error?: { code: string; message: string | null } | null;
}

export interface DocumentRow {
  id: string;
  type: string;
  format?: string;
  title: string;
  document_code: string;
  page_count: number | null;
  file_size_bytes: number | null;
  download_url: string;
}

export interface PaperResult {
  test_id: string;
  question_count: number;
  requested_count: number;
  documents: DocumentRow[];
}

export interface Language {
  id: string;
  code: string;
  name: string;
}

export interface Exam {
  id: string;
  code: string;
  name: string;
}

export const sources = {
  list: () => api.get<Envelope<SourceRow[]>>("/sources"),
  show: (id: string) => api.get<Envelope<SourceDetail>>(`/sources/${id}`),
  upload: (form: FormData) => upload<Envelope<SourceRow>>("/sources", form),
  process: (id: string) =>
    api.post<Envelope<{ job_id: string; status: string }>>(`/sources/${id}/process`),
  paper: (id: string, payload: Record<string, unknown>) =>
    api.post<Envelope<PaperResult>>(`/sources/${id}/paper`, payload),
  decide: (
    id: string,
    payload: {
      decision: "approve" | "reject";
      question_ids?: string[];
      min_quality?: number;
    },
  ) => api.post<Envelope<DecideResult>>(`/sources/${id}/questions/decide`, payload),
};

export interface PendingQuestion {
  id: string;
  status: string;
  difficulty: string;
  quality_score: number | null;
  question_text: string | null;
  options: Record<string, string> | null;
  correct_option: string | null;
  explanation: string | null;
  sources: Array<{
    citation: string;
    supporting_quote: string | null;
  }>;
}

export interface DecideResult {
  decision: string;
  considered: number;
  decided: number;
  failed: Array<{ question_id: string; reason: string }>;
  questions: QuestionCounts;
}

export const questions = {
  list: (params: Record<string, string | number>) =>
    api.get<Envelope<PendingQuestion[]>>(
      `/questions?${new URLSearchParams(
        Object.entries(params).map(([k, v]) => [k, String(v)]),
      )}`,
    ),
  generate: (payload: {
    source_id: string;
    exam_id?: string | null;
    language_id: string;
    target_count: number;
    formats?: string[];
  }) =>
    api.post<Envelope<{ job_id: string; planned: { raw_questions: number } }>>(
      "/questions/generate",
      payload,
    ),
};

export interface LessonNode {
  id: string;
  number: string | null;
  title: string;
  page_start: number | null;
  page_end: number | null;
  sequence: number;
  usable_chunks: number;
  total_chunks: number;
}

export interface ChapterNode {
  id: string;
  number: string | null;
  title: string;
  page_start: number | null;
  page_end: number | null;
  sequence: number;
  lessons: LessonNode[];
}

export interface StructureTree {
  book_id: string | null;
  pages: number;
  chapters: ChapterNode[];
  source_status: string;
  needs_reprocess: boolean;
}

export interface PageRow {
  id: string;
  page_number: number;
  ocr_confidence: number | null;
  needs_review: boolean;
  reviewed_by: string | null;
  text: string | null;
  raw_text: string | null;
}

export const structure = {
  show: (sourceId: string) =>
    api.get<Envelope<StructureTree>>(`/sources/${sourceId}/structure`),
  updateChapter: (sourceId: string, chapterId: string, payload: Record<string, unknown>) =>
    api.patch<Envelope<unknown>>(`/sources/${sourceId}/chapters/${chapterId}`, payload),
  updateLesson: (sourceId: string, lessonId: string, payload: Record<string, unknown>) =>
    api.patch<Envelope<unknown>>(`/sources/${sourceId}/lessons/${lessonId}`, payload),
  deleteLesson: (sourceId: string, lessonId: string) =>
    api.delete<Envelope<unknown>>(`/sources/${sourceId}/lessons/${lessonId}`),
  pages: (sourceId: string, needsReviewOnly: boolean) =>
    api.get<Envelope<PageRow[]> & { meta: { needs_review: number } }>(
      `/sources/${sourceId}/pages?limit=200${needsReviewOnly ? "&needs_review=1" : ""}`,
    ),
  correctPage: (sourceId: string, pageId: string, text: string) =>
    api.patch<Envelope<unknown>>(`/sources/${sourceId}/pages/${pageId}`, { text }),
  reprocess: (sourceId: string) =>
    api.post<Envelope<{ job_id: string; status: string }>>(`/sources/${sourceId}/reprocess`),
};

export const jobs = {
  show: (id: string) => api.get<Envelope<JobRow>>(`/jobs/${id}`),
};

export const catalogue = {
  languages: () => api.get<Envelope<Language[]>>("/languages"),
  exams: () => api.get<Envelope<Exam[]>>("/exams"),
};

/**
 * The download route requires the bearer token, so a plain <a href> gets a
 * 401. Fetch the bytes, then hand the browser an object URL.
 */
export async function downloadDocument(id: string, filename: string): Promise<void> {
  const token = getToken();

  const response = await fetch(`${BASE}/documents/${id}/download`, {
    headers: {
      Accept: "application/pdf",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });

  if (!response.ok) {
    throw new ApiError("Download failed", response.status);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");

  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

// ------------------------------------------------- previous-year papers

export interface PyqPaper {
  id: string;
  exam_id: string;
  year: number;
  session: string | null;
  paper_name: string;
  total_questions: number;
  parsed_questions: number;
  question_count: number | null;
  status: string;
  created_at: string | null;
}

export interface PyqParseResult {
  pages: number;
  parsed: number;
  skipped: number;
  keyed: number;
}

export interface PyqMapResult {
  mapped: number;
  lexical: number;
  model: number;
  unmapped: number;
  review: number;
  note: string | null;
}

export interface TopicFrequencyRow {
  canonical_topic_id: string;
  topic: string | null;
  domain: string | null;
  total_asked: number;
  years_asked: number;
  years_covered: number;
  first_asked_year: number | null;
  last_asked_year: number | null;
  frequency_score: number;
  trend: "rising" | "stable" | "falling" | "dormant";
}

export const pyqs = {
  list: () => api.get<Envelope<PyqPaper[]>>("/pyqs"),
  create: (payload: {
    exam_id: string;
    year: number;
    paper_name: string;
    session?: string | null;
  }) => api.post<Envelope<PyqPaper>>("/pyqs", payload),
  parse: (id: string, sourceId: string) =>
    api.post<Envelope<PyqPaper & PyqParseResult>>(`/pyqs/${id}/parse`, {
      source_id: sourceId,
    }),
  map: (id: string) => api.post<Envelope<PyqMapResult>>(`/pyqs/${id}/map`),
  analyze: (id: string) =>
    api.post<Envelope<{ topics: number; questions: number; years: number }>>(
      `/pyqs/${id}/analyze`,
    ),
  analysis: (examId: string) =>
    api.get<
      Envelope<TopicFrequencyRow[]> & {
        meta: { topics: number; computed_at: string | null; disclaimer: string };
      }
    >(`/pyqs/analysis/${examId}`),
};

// ------------------------------------------------------ generation targets

export interface BacklogRow {
  requested: number;
  approved: number;
  pending_review: number;
  rejected: number;
  deficit: number;
  canonical_topic_id: string | null;
  difficulty: string | null;
  satisfied: boolean;
}

export const targets = {
  backlog: (examId: string) =>
    api.get<
      Envelope<BacklogRow[]> & {
        meta: {
          targets: number;
          total_deficit: number;
          satisfied: number;
          counts_pending_as_held: boolean;
        };
      }
    >(`/generation-targets/backlog/${examId}`),
};

// ------------------------------------------------------------- dashboard

export interface DashboardData {
  books: {
    total: number;
    ready: number;
    processing: number;
    failed: number;
    pages: number;
    pages_needing_review: number;
  };
  questions: {
    total: number;
    approved: number;
    pending_review: number;
    rejected: number;
    revision_required: number;
    top_rejection_reasons: Record<string, number>;
  };
  pyqs: {
    papers: number;
    years: string | null;
    questions: number;
    unmapped: number;
  };
  documents: {
    total: number;
    by_format: Record<string, number>;
    downloads: number;
  };
  ai: {
    calls: number;
    spend_inr: number;
    spend_today_inr: number;
    daily_budget_inr: number;
    driver: string;
    jobs_running: number;
    jobs_failed: number;
  };
}

export const dashboard = {
  load: () => api.get<Envelope<DashboardData>>("/dashboard"),
};

export interface TrendPoint {
  date: string;
  value: number;
}

export interface TrendData {
  days: number;
  from: string | null;
  to: string | null;
  series: {
    drafted: TrendPoint[];
    approved: TrendPoint[];
    documents: TrendPoint[];
    spend_inr: TrendPoint[];
  };
}

export const trends = {
  load: (days = 30) => api.get<Envelope<TrendData>>(`/dashboard/trend?days=${days}`),
};

// ------------------------------------------------------------- ai history

export interface AiOperationSummary {
  operation: string;
  calls: number;
  cost_inr: number;
  input_tokens: number;
  output_tokens: number;
  avg_latency_ms: number;
  cache_hits: number;
  errors: number;
}

export interface AiInvocation {
  id: string;
  operation: string;
  model: string | null;
  provider: string | null;
  tier: string | null;
  input_tokens: number;
  output_tokens: number;
  cached_input_tokens: number;
  cost_inr: number;
  latency_ms: number;
  cache_hit: boolean;
  degraded: boolean;
  error: string | null;
  created_at: string;
}

export interface AiHistoryMeta {
  total_calls: number;
  total_cost_inr: number;
  cache_hit_rate: number;
  errors: number;
  by_operation: AiOperationSummary[];
}

export const aiHistory = {
  load: (limit = 50) =>
    api.get<Envelope<AiInvocation[]> & { meta: AiHistoryMeta }>(`/ai-history?limit=${limit}`),
};

// ------------------------------------------------------------ audit trail

export interface AuditEntry {
  id: string;
  action: string;
  actor_user_id: string | null;
  actor_role: string | null;
  subject_type: string | null;
  subject_id: string | null;
  changes: Record<string, unknown>;
  ip: string | null;
  request_id: string | null;
  created_at: string | null;
}

export const audit = {
  load: (params: { action?: string; per_page?: number } = {}) =>
    api.get<
      Envelope<AuditEntry[]> & {
        meta: { total: number; per_page: number; current_page: number; last_page: number };
      }
    >(
      `/audit-logs?${new URLSearchParams(
        Object.entries(params).map(([k, v]) => [k, String(v)]),
      )}`,
    ),
};
