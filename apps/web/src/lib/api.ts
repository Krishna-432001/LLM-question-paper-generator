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
    /** A stable reason the page can act on, when the server sends one. */
    readonly code?: string,
    /** Anything else the refusal carries, e.g. the counts per part of a mock. */
    readonly meta?: Record<string, unknown>,
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
      body?.code ?? undefined,
      body?.meta ?? undefined,
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
  put: <T>(path: string, payload?: unknown) =>
    request<T>(path, {
      method: "PUT",
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
  is_platform_admin?: boolean;
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
  /** Absent from servers older than paper language modes. */
  language_mode?: LanguageMode;
  languages?: string[];
  /** Approved questions this book has in the paper's language(s). */
  availability?: { primary: number; secondary: number | null; both: number | null };
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

// ------------------------------------------------------------ exam admin

export interface ExamRow extends Exam {
  conducting_body: string | null;
  state: string | null;
  category: string | null;
  level: string | null;
  is_active: boolean;
  is_platform: boolean;
}

export interface ExamSubjectRow {
  id: string;
  name: string;
  question_count: number | null;
  weight: number;
}

export interface ExamPaperRow {
  id: string;
  stage: string;
  name: string;
  duration_minutes: number | null;
  total_questions: number | null;
  total_marks: number | null;
  negative_marking: number;
  subjects: ExamSubjectRow[];
}

export interface ExamDetail extends ExamRow {
  country: string;
  description: string | null;
  active_version: {
    id: string;
    label: string;
    notification_date: string | null;
    exam_date: string | null;
    languages: Array<{ id: string; code: string; name: string; is_default: boolean }>;
    papers: ExamPaperRow[];
  } | null;
}

export interface SyllabusTopicNode {
  id: string;
  parent_id: string | null;
  name: string;
  weight: number;
  estimated_minutes: number;
  canonical_topic_id: string | null;
  canonical_slug: string | null;
}

export interface SyllabusUnitNode {
  id: string;
  subject_id: string;
  subject: string;
  code: string | null;
  name: string;
  weight: number;
  topics: SyllabusTopicNode[];
}

export interface SyllabusVersionRow {
  id: string;
  version_number: number;
  status: "draft" | "active" | "superseded" | "archived";
  effective_from: string | null;
  published_at: string | null;
}

export interface SyllabusTree {
  exam_id: string;
  versions: SyllabusVersionRow[];
  selected_version_id: string | null;
  units: SyllabusUnitNode[];
}

export interface CreateExamPayload {
  code: string;
  name: string;
  conducting_body?: string | null;
  state?: string | null;
  category?: string | null;
  level?: string | null;
  platform?: boolean;
  exam_date?: string | null;
  language_ids?: string[];
  paper: {
    duration_minutes: number;
    total_questions: number;
    total_marks?: number;
    negative_marking?: number;
  };
  subjects?: Array<{ name: string; question_count?: number }>;
}

export interface CanonicalTopicRow {
  id: string;
  slug: string;
  name: string;
  domain: string;
}

export interface LessonTopicRow {
  lesson_id: string;
  chapter: string;
  lesson: string;
  canonical_topic_id: string | null;
  topic: string | null;
  topic_slug: string | null;
  confidence: number | null;
  mapped_by: "lexical" | "ai" | "human" | null;
  needs_review: boolean;
}

export interface SyllabusDiff {
  base_version: number;
  target_version: number;
  units_added: Array<{ unit: string; weight: number; topics: string[] }>;
  units_removed: Array<{ unit: string; weight: number; topics: string[] }>;
  weight_changes: Array<{ unit: string; from: number; to: number }>;
  topics_added: Array<{ unit: string; topic: string }>;
  topics_removed: Array<{ unit: string; topic: string }>;
  unchanged: boolean;
}

export const topicsApi = {
  canonical: (q = "", limit = 200) =>
    api.get<Envelope<CanonicalTopicRow[]>>(
      `/canonical-topics?limit=${limit}${q ? `&q=${encodeURIComponent(q)}` : ""}`,
    ),
  lessons: (sourceId: string) =>
    api.get<Envelope<LessonTopicRow[]> & { meta: { lessons: number; mapped: number; needs_review: number } }>(
      `/sources/${sourceId}/topic-mappings`,
    ),
  assign: (sourceId: string, lessonId: string, canonicalTopicId: string | null) =>
    api.put<Envelope<{ questions_refiled: number }>>(`/sources/${sourceId}/lessons/${lessonId}/topic`, {
      canonical_topic_id: canonicalTopicId,
    }),
  remap: (sourceId: string) =>
    api.post<Envelope<{ job_id: string; status: string }>>(`/sources/${sourceId}/map-topics`),
};

export const syllabusAdmin = {
  createDraft: (examId: string) =>
    api.post<Envelope<SyllabusVersionRow>>(`/exams/${examId}/syllabus/drafts`, {}),
  discard: (versionId: string) => api.post<Envelope<SyllabusVersionRow>>(`/syllabus-versions/${versionId}/discard`),
  publish: (versionId: string) => api.post<Envelope<SyllabusVersionRow>>(`/syllabus-versions/${versionId}/publish`),
  diff: (versionId: string) => api.get<Envelope<SyllabusDiff>>(`/syllabus-versions/${versionId}/diff`),
  addUnit: (versionId: string, payload: { exam_subject_id: string; name: string; weight?: number }) =>
    api.post<Envelope<unknown>>(`/syllabus-versions/${versionId}/units`, payload),
  updateUnit: (versionId: string, unitId: string, payload: Record<string, unknown>) =>
    api.patch<Envelope<unknown>>(`/syllabus-versions/${versionId}/units/${unitId}`, payload),
  removeUnit: (versionId: string, unitId: string) =>
    api.delete<unknown>(`/syllabus-versions/${versionId}/units/${unitId}`),
  /** A topic of the unit, or with parent_id a sub-topic of one of its topics. */
  addTopic: (
    versionId: string,
    unitId: string,
    payload: { name: string; canonical_topic_id?: string | null; parent_id?: string | null },
  ) => api.post<Envelope<unknown>>(`/syllabus-versions/${versionId}/units/${unitId}/topics`, payload),
  /** Import from an uploaded syllabus PDF (a source of kind "syllabus"). */
  importSource: (versionId: string, payload: { exam_subject_id: string; source_id: string; replace: boolean }) =>
    api.post<Envelope<{ units: number; topics: number; weighted_by_counts: boolean }>>(
      `/syllabus-versions/${versionId}/import`,
      payload,
    ),
  removeTopic: (versionId: string, topicId: string) =>
    api.delete<unknown>(`/syllabus-versions/${versionId}/topics/${topicId}`),
  importText: (versionId: string, payload: { exam_subject_id: string; text: string; replace: boolean }) =>
    api.post<Envelope<{ units: number; topics: number; weighted_by_counts: boolean }>>(
      `/syllabus-versions/${versionId}/import`,
      payload,
    ),
};

export const examAdmin = {
  list: () => api.get<Envelope<ExamRow[]>>("/exams?include_inactive=1"),
  show: (id: string) => api.get<Envelope<ExamDetail>>(`/exams/${id}`),
  create: (payload: CreateExamPayload) => api.post<Envelope<ExamDetail>>("/exams", payload),
  update: (id: string, payload: Record<string, unknown>) =>
    api.patch<Envelope<ExamDetail>>(`/exams/${id}`, payload),
  updatePaper: (id: string, paperId: string, payload: Record<string, unknown>) =>
    api.patch<Envelope<ExamDetail>>(`/exams/${id}/papers/${paperId}`, payload),
  syllabus: (id: string, versionId?: string) =>
    api.get<Envelope<SyllabusTree>>(
      `/exams/${id}/syllabus${versionId ? `?version_id=${versionId}` : ""}`,
    ),
};

/**
 * The download route requires the bearer token, so a plain <a href> gets a
 * 401. Fetch the bytes, then hand the browser an object URL.
 */
export async function downloadDocument(id: string, filename: string): Promise<void> {
  const token = getToken();

  const response = await fetch(`${BASE}/documents/${id}/download`, {
    headers: {
      // Any stored format: PDF, Word, Excel or JSON.
      Accept: "*/*",
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

// ------------------------------------------------------------ student app

export interface StudentProfile {
  id: string;
  name: string;
  email: string;
  timezone: string;
  locale: string;
  preferred_language_id: string | null;
  target_exam: { id: string; code: string; name: string } | null;
}

export interface MyTestRow {
  id: string;
  title: string;
  type: string;
  question_count: number;
  duration_minutes: number | null;
  language_mode?: LanguageMode;
  /** Language codes in print order, primary first. */
  languages?: string[];
  created_at: string | null;
  attempts: number;
  best_score: number | null;
  best_accuracy: number | null;
  open_attempt_id: string | null;
}

export interface AttemptRow {
  attempt_id: string;
  test_id: string;
  title: string;
  type: string;
  status: string;
  started_at: string;
  submitted_at: string | null;
  total_questions: number | null;
  correct: number | null;
  wrong: number | null;
  final_score: number | null;
  accuracy: number | null;
  time_taken_seconds: number | null;
}

export interface PaperQuestion {
  test_question_id: string;
  sequence: number;
  marks: number;
  /** The part of a mock in parts ("Part A — …"); absent or null otherwise. */
  section?: string | null;
  /** The primary language, kept for older clients; `texts` has every language. */
  question_text: string | null;
  options: Record<string, string> | null;
  /** Keyed by language code, one entry per language of the paper. */
  texts?: Record<string, QuestionText>;
}

export interface OpenAttempt {
  attempt_id: string;
  status: "in_progress";
  test: { id: string; title: string; duration_minutes: number | null; language_mode?: LanguageMode };
  language_mode?: LanguageMode;
  /** Print order: the primary first. */
  languages?: PaperLanguage[];
  started_at: string | null;
  expires_at: string | null;
  questions: PaperQuestion[];
}

export interface AttemptResult {
  attempt_id: string;
  status: "submitted";
  test: { id: string; title: string | null };
  submitted_at: string | null;
  result: {
    total_questions: number;
    attempted: number;
    correct: number;
    wrong: number;
    unattempted: number;
    final_score: number;
    /** Null when nothing could be marked (an unkeyed paper). */
    accuracy: number | null;
    time_taken_seconds: number | null;
    topics: Array<{ topic: string; attempted: number; correct: number; accuracy?: number }>;
    difficulty: Record<string, { attempted: number; correct: number; accuracy?: number }>;
    /** A mock in parts: the score per part, in paper order. */
    sections?: SectionScore[];
  } | null;
}

export interface Solution {
  sequence: number;
  question_text: string | null;
  options?: Record<string, string> | null;
  correct_option: string | null;
  explanation: string | null;
  /** Keyed by language code; each carries its own explanation. */
  texts?: Record<string, QuestionText>;
  sources: Array<{ citation: string; supporting_quote: string | null }>;
}

export interface MasteryRow {
  canonical_topic_id: string;
  topic: string;
  attempts: number;
  correct: number;
  mastery: number;
  last_practiced_at: string | null;
  review_due: boolean;
}

export interface Progress {
  exam_id: string;
  totals: { tests: number; questions_answered: number; accuracy: number | null; topics_practised: number };
  streak_days: number;
  weak_topics: MasteryRow[];
  strong_topics: MasteryRow[];
  review_due: number;
  mastery: MasteryRow[];
  trend: Array<{ date: string; tests: number; accuracy: number | null }>;
}

export type PlanMode = "exam-prep" | "daily" | "three-day" | "weekly" | "monthly" | "model-test";

export interface PlanSummary {
  id: string;
  mode: string;
  status: string;
  start_date: string;
  end_date: string;
  exam_date: string | null;
  hours_per_day: number;
  day_count: number;
  coverage_percent: number | null;
  dropped_topics: unknown;
}

export interface PlanItem {
  id: string;
  type: string;
  /** A test item: daily | weekly | monthly | three_day | model | mock. */
  test_kind?: string | null;
  topic: string | null;
  exam_topic_id: string | null;
  minutes: number;
  question_count: number | null;
  is_revision: boolean;
  completed_at: string | null;
}

export interface PlanDay {
  id?: string;
  day_number: number;
  date: string;
  role: string;
  target_minutes: number;
  completion_pct?: number;
  items: PlanItem[];
}

export interface PlanDetail extends PlanSummary {
  days: PlanDay[];
}

export const authApi = {
  register: (payload: { name: string; email: string; password: string; password_confirmation: string }) =>
    api.post<Envelope<{ user: { id: string; name: string }; token: string }>>("/auth/register", payload),
  logout: () => api.post<unknown>("/auth/logout"),
};

export const student = {
  profile: () => api.get<Envelope<StudentProfile>>("/me/profile"),
  updateProfile: (payload: Partial<{ name: string; preferred_language_id: string | null; target_exam_id: string | null }>) =>
    api.patch<Envelope<StudentProfile>>("/me/profile", payload),
  tests: (examId?: string) => api.get<Envelope<MyTestRow[]>>(`/me/tests${examId ? `?exam_id=${examId}` : ""}`),
  attempts: (examId?: string, limit = 50) =>
    api.get<Envelope<AttemptRow[]>>(`/me/attempts?limit=${limit}${examId ? `&exam_id=${examId}` : ""}`),
  progress: (examId: string, days = 30) =>
    api.get<Envelope<Progress>>(`/me/progress?exam_id=${examId}&days=${days}`),
  attempt: (attemptId: string) => api.get<Envelope<OpenAttempt | AttemptResult>>(`/attempts/${attemptId}`),
};

/** One part of an exam's pattern, as a refusal reports it: what it needs and what the bank has. */
export interface PatternPartCount {
  name: string;
  needed: number;
  available: number;
  units?: Array<{ name: string; needed: number; available: number }>;
}

/** A part of a mock in parts, as a test reports it. */
export interface TestSection {
  name: string;
  questions: number;
  marks: number;
}

/** A part's score: marks against its maximum, and its qualifying mark where it has one. */
export interface SectionScore {
  name: string;
  questions: number;
  attempted: number;
  correct: number;
  wrong: number;
  marks: number;
  max_marks: number;
  qualifying_marks: number | null;
  qualified: boolean | null;
}

export const testsApi = {
  /**
   * A full mock in the exam's own pattern. Refused with code
   * "exam_pattern_short" and meta.sections (PatternPartCount[]) when the bank
   * cannot fill it; allow_short builds the shorter mock instead.
   */
  createExamPattern: (payload: {
    exam_id: string;
    language_id: string;
    type: "model" | "prediction";
    allow_short?: boolean;
    language_mode?: LanguageMode;
    secondary_language_id?: string;
  }) =>
    api.post<Envelope<{ id: string; title: string; question_count: number; duration_minutes: number | null; sections: TestSection[] }>>(
      "/tests/exam-pattern",
      payload,
    ),
  create: (payload: {
    exam_id: string;
    language_id: string;
    type: string;
    question_count: number;
    title?: string;
    duration_minutes?: number;
    difficulty_mix?: Record<string, number>;
    language_mode?: LanguageMode;
    /** Required when language_mode is bilingual; must differ from language_id. */
    secondary_language_id?: string;
    /** A topic-wise test: whole syllabus units and/or single topics (with their sub-topics). */
    exam_unit_ids?: string[];
    exam_topic_ids?: string[];
  }) =>
    api.post<
      Envelope<{
        id: string;
        title: string;
        question_count: number;
        language_mode?: LanguageMode;
        languages?: PaperLanguage[];
      }>
    >("/tests", payload),
  start: (testId: string) =>
    api.post<
      Envelope<{
        attempt_id: string;
        expires_at: string | null;
        language_mode?: LanguageMode;
        languages?: PaperLanguage[];
        questions: PaperQuestion[];
      }>
    >(`/tests/${testId}/attempt`),
  submit: (attemptId: string, answers: Array<{ test_question_id: string; selected_option: string | null; time_spent_seconds?: number }>) =>
    api.post<Envelope<{ attempt_id: string; final_score: number; accuracy: number }>>(
      `/attempts/${attemptId}/submit`,
      { answers },
    ),
  solutions: (attemptId: string) =>
    api.get<Envelope<Solution[]> & { meta?: { language_mode: LanguageMode; languages: PaperLanguage[] } }>(
      `/results/${attemptId}/solutions`,
    ),
};

export const plansApi = {
  list: () => api.get<Envelope<PlanSummary[]>>("/study-plans"),
  create: (
    mode: PlanMode,
    payload: { exam_id: string; language_id: string; start_date?: string; exam_date?: string; hours_per_day?: number; off_days?: number[] },
  ) => api.post<Envelope<PlanSummary>>(`/study-plans/${mode}`, payload),
  show: (id: string) => api.get<Envelope<PlanDetail>>(`/study-plans/${id}`),
  today: (id: string) => api.get<Envelope<PlanDay | null> & { meta?: { message: string } }>(`/study-plans/${id}/today`),
  complete: (planId: string, itemId: string) =>
    api.post<Envelope<{ item_id: string; day_completion_pct: number }>>(`/study-plans/${planId}/items/${itemId}/complete`),
  dayTest: (planId: string, dayId: string) =>
    api.post<Envelope<{ test_id: string; created: boolean }>>(`/study-plans/${planId}/days/${dayId}/test`),
};

export const documentsApi = {
  list: (subjectId?: string) => api.get<Envelope<DocumentRow[]>>(`/documents${subjectId ? `?subject_id=${subjectId}` : ""}`),
  generate: (subjectType: "tests" | "study-plans", subjectId: string, types?: string[]) =>
    api.post<Envelope<DocumentRow[]>>(`/${subjectType}/${subjectId}/documents`, types ? { types } : {}),
};

// ------------------------------------------------- paper language modes

/**
 * single: every question in the test's language. bilingual: every question in
 * the test's language and again in its second language beneath it.
 */
export type LanguageMode = "single" | "bilingual";

export interface PaperLanguage {
  id: string;
  code: string;
  name: string;
  native_name: string | null;
}

/** One question in one language. `explanation` is present on solutions only. */
export interface QuestionText {
  question_text: string | null;
  options: Record<string, string>;
  explanation?: string | null;
  /**
   * False when the paper has no printable text in this language. The text is
   * then null — never another language's text under this language's code.
   */
  available?: boolean;
}
// ------------------------------------------------- practice papers (PDF to timed test)
// Appended block owned by the practice-paper feature. Sitting the paper and
// reading its solutions use testsApi; only upload, list and key live here.

/**
 * Something to check about an uploaded paper. `code` and `params` are what
 * the app translates (papers.warn.<code>); `message` is the server's English.
 */
export interface PaperWarning {
  code: string;
  params: Record<string, string | number>;
  message: string;
}

/** What was read from an uploaded paper. Also the output of its OCR job. */
export interface PracticePaperReport {
  test_id: string;
  title: string;
  question_count: number;
  duration_minutes: number;
  parsed: number;
  skipped: number;
  keyed: number;
  pages: number;
  sections: number;
  languages_detected: string[];
  language: string;
  ocr: boolean;
  warnings: PaperWarning[];
}

/** A scanned PDF: the paper is being read by OCR; poll the job for the report. */
export interface PracticePaperQueued {
  job_id: string;
  status: "queued";
  message: string;
}

export interface PracticePaperRow {
  test_id: string;
  title: string;
  question_count: number;
  keyed: number;
  duration_minutes: number | null;
  languages_detected: string[];
  skipped: number;
  created_at: string | null;
  attempts: number;
  best_score: number | null;
  best_accuracy: number | null;
  open_attempt_id: string | null;
}

export interface AnswerKeyResult {
  test_id: string;
  updated: number;
  unchanged: number;
  /** Paper numbers with no question; "2.3" style when the paper has parts. */
  unmatched: Array<number | string>;
  keyed: number;
  question_count: number;
  sections: number;
  rescored: boolean;
  message: string;
}

export interface AttemptScoring {
  attempt_id: string;
  submitted: boolean;
  total_questions: number | null;
  unscored: number;
  scored: number | null;
}

/** A failed OCR job keeps the reason code beside the ledger's own error. */
export type PracticePaperJob = JobRow & {
  output: PracticePaperReport | { error: { code: string; message: string } } | null;
};

/** An API refusal that also says why in a code the app can translate. */
export class PaperApiError extends ApiError {
  constructor(
    message: string,
    status: number,
    detail?: string,
    errors?: Record<string, string[]>,
    readonly code?: string,
  ) {
    super(message, status, detail, errors);
  }
}

/**
 * The practice-paper calls go through here rather than request()/upload()
 * only so the `code` on a refusal survives to the page.
 */
async function paperCall<T>(path: string, init: RequestInit): Promise<T> {
  const token = getToken();
  const response = await fetch(`${BASE}${path}`, {
    ...init,
    headers: {
      Accept: "application/json",
      ...(init.body instanceof FormData ? {} : { "Content-Type": "application/json" }),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });
  const body = await response.json().catch(() => null);

  if (!response.ok) {
    throw new PaperApiError(
      body?.title ?? "Request failed",
      response.status,
      body?.detail,
      body?.errors ?? undefined,
      typeof body?.code === "string" ? body.code : undefined,
    );
  }

  return body as T;
}

export const practicePapers = {
  list: () => api.get<Envelope<PracticePaperRow[]>>("/practice-papers"),
  upload: (form: FormData) =>
    paperCall<Envelope<PracticePaperReport | PracticePaperQueued>>("/practice-papers", { method: "POST", body: form }),
  job: (jobId: string) => api.get<Envelope<PracticePaperJob>>(`/jobs/${jobId}`),
  setAnswerKey: (testId: string, key: string | Record<string, string>) =>
    paperCall<Envelope<AnswerKeyResult>>(`/practice-papers/${testId}/answer-key`, {
      method: "POST",
      body: JSON.stringify({ key }),
    }),
  scoring: (attemptId: string) =>
    api.get<Envelope<AttemptScoring>>(`/practice-papers/attempts/${attemptId}/scoring`),
};
// ------------------------------------------------- end practice papers

// ------------------------------------------------------ translation engine

export type TranslationStatus =
  | "MACHINE"
  | "AI_VALIDATED"
  | "NEEDS_REVIEW"
  | "HUMAN_APPROVED"
  | "STALE"
  | "REJECTED";

export interface TranslationStats {
  language: { id: string; code: string; name: string };
  exam_id: string | null;
  approved: number;
  available: number;
  mastered_in_language: number;
  translated_usable: number;
  needs_translation: number;
  coverage: number;
  by_status: Record<TranslationStatus, number>;
  has_prompt: boolean;
  running_job_id: string | null;
}

export interface TranslationCheck {
  passed: boolean;
  problems?: string[];
  missing?: Array<{ source: string; expected: string }>;
  meaning_score?: number | null;
  expected?: string | null;
  model_choice?: string | null;
  skipped?: boolean;
  script_ratio?: number | null;
  length_ratio?: number | null;
  [key: string]: unknown;
}

export interface TranslationText {
  question_text: string | null;
  options: Record<string, string>;
  explanation: string | null;
}

export interface TranslationReviewItem {
  id: string;
  question_id: string;
  language: string | null;
  source_language: string | null;
  status: TranslationStatus;
  translation_version: number;
  source_question_version: number;
  question_version: number | null;
  quality_score: number | null;
  failures: string[];
  validation_report: {
    failures?: string[];
    checks?: Record<string, TranslationCheck>;
    back_translation?: string | null;
    notes?: string[];
    protected_spans?: Array<{ kind: string; text: string }>;
    [key: string]: unknown;
  };
  correct_option: string | null;
  source: TranslationText;
  translation: TranslationText;
  reviewed_by: string | null;
  reviewed_at: string | null;
  created_at: string | null;
}

export interface TranslationJob extends JobRow {
  output?: {
    language?: string;
    requested?: number;
    translated?: number;
    validated?: number;
    needs_review?: number;
    failed?: number;
    skipped?: number;
    errors?: string[];
  } | null;
}

export interface GlossaryEntry {
  id: string;
  source_language_id: string;
  target_language_id: string;
  kind: "term" | "name";
  domain: string | null;
  source_term: string;
  target_term: string;
  notes: string | null;
  is_active: boolean;
  updated_at: string | null;
}

export interface TranslationEdits {
  question_text?: string;
  options?: Partial<Record<"A" | "B" | "C" | "D", string>>;
  explanation?: string | null;
}

export const translationsApi = {
  start: (payload: { language_id: string; exam_id?: string; test_id?: string; question_ids?: string[]; limit?: number }) =>
    api.post<Envelope<{ job_id: string; status: string; poll_url: string; reused: boolean }>>("/translations", payload),
  stats: (languageId: string, examId?: string) =>
    api.get<Envelope<TranslationStats>>(
      `/translations/stats?language_id=${languageId}${examId ? `&exam_id=${examId}` : ""}`,
    ),
  review: (params: { language_id: string; status?: TranslationStatus; exam_id?: string; limit?: number; offset?: number }) => {
    const query = new URLSearchParams(
      Object.entries(params)
        .filter(([, v]) => v !== undefined && v !== "")
        .map(([k, v]) => [k, String(v)]),
    );
    return api.get<Envelope<TranslationReviewItem[]> & { meta: { total: number } }>(`/translations/review?${query}`);
  },
  decide: (id: string, payload: { decision: "approve" | "reject"; reason?: string; edits?: TranslationEdits }) =>
    api.post<Envelope<TranslationReviewItem>>(`/translations/${id}/decide`, payload),
  job: (id: string) => api.get<Envelope<TranslationJob>>(`/jobs/${id}`),
};

export const glossaryApi = {
  list: (params: {
    source_language_id?: string;
    target_language_id?: string;
    q?: string;
    kind?: "term" | "name";
    domain?: string;
    include_inactive?: boolean;
    limit?: number;
    offset?: number;
  }) => {
    const query = new URLSearchParams(
      Object.entries(params)
        .filter(([, v]) => v !== undefined && v !== "" && v !== false)
        .map(([k, v]) => [k, v === true ? "1" : String(v)]),
    );
    return api.get<Envelope<GlossaryEntry[]> & { meta: { total: number; domains: string[] } }>(`/glossary?${query}`);
  },
  create: (payload: {
    source_language_id: string;
    target_language_id: string;
    source_term: string;
    target_term: string;
    kind?: "term" | "name";
    domain?: string | null;
    notes?: string | null;
  }) => api.post<Envelope<GlossaryEntry>>("/glossary", payload),
  update: (id: string, payload: Partial<Pick<GlossaryEntry, "source_term" | "target_term" | "kind" | "domain" | "notes" | "is_active">>) =>
    api.patch<Envelope<GlossaryEntry>>(`/glossary/${id}`, payload),
  deactivate: (id: string) => api.delete<Envelope<GlossaryEntry>>(`/glossary/${id}`),
  importCsv: (payload: { source_language_id: string; target_language_id: string; csv: string }) =>
    api.post<Envelope<{ created: number; updated: number; skipped: number; errors: string[] }>>("/glossary/import", payload),
};

// ---- Chapter review -------------------------------------------------------
// Written questions approved a chapter at a time, on a spot-checked sample.

export interface ChapterRow {
  key: string;
  source: string;
  breadcrumb: string;
  pending: number;
  approved: number;
  rejected: number;
}

export interface ChapterSampleQuestion {
  question_id: string;
  review_id: string | null;
  format: string;
  difficulty: string;
  topic: string | null;
  page: number | null;
  quote: string | null;
  correct_option: string | null;
  master: { question_text: string | null; options: Record<string, string> | null; explanation: string | null };
  /** Absent for Tamil-language chapters, whose master is already Tamil. */
  tamil: { question_text: string | null; options: Record<string, string> | null; explanation: string | null } | null;
}

export const chapterReviews = {
  list: () =>
    api.get<Envelope<ChapterRow[]> & { meta: { chapters: number; pending: number } }>("/chapter-reviews"),
  sample: (key: string, size = 5) =>
    api.get<Envelope<{ chapter: ChapterRow; questions: ChapterSampleQuestion[] }>>(
      `/chapter-reviews/${key}/sample?size=${size}`,
    ),
  /** One batch; call again while `remaining` is above zero. */
  approve: (key: string, checkedQuestionIds: string[]) =>
    api.post<Envelope<{ approved: number; skipped: number; remaining: number }>>(
      `/chapter-reviews/${key}/approve`,
      { checked_question_ids: checkedQuestionIds },
    ),
};
// ---- end chapter review ---------------------------------------------------
