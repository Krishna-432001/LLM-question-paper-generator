# ExamForge — AI Exam Preparation SaaS Platform

**Architecture & Engineering Specification v1.0**

> **Run it on your PC:** set it up once with [docs/LOCAL-SETUP.md](docs/LOCAL-SETUP.md),
> then double-click `scripts\start-local.bat` each day (and `scripts\stop-local.bat` to stop).

A multi-tenant SaaS platform that converts approved source material (textbooks, official
syllabi, previous-year papers) into a **source-grounded knowledge base**, a **centralized
master question bank**, and every downstream artifact a candidate needs: study plans,
tests, prediction papers, multilingual question papers and answer keys.

First target: **TNPSC Group IV** (Tamil + English, TN State Board Std 6–10).
Designed from day one to expand to all Indian government and private competitive exams.

---

## The one-sentence architecture

> Source documents are ingested once, chunked and embedded once, converted into
> language-neutral **master questions** with mandatory source citations, stored once in a
> shared question bank, and then *projected* — via exam mappings, language translations,
> selection policies and PDF templates — into an unlimited number of per-user, per-exam,
> per-language artifacts, with **zero LLM calls on the read path**.

Everything expensive happens **once, offline, in a queue**. Everything a user clicks is a
database read plus a signed URL.

---

## Documentation index

| # | Document | Covers (from the spec) |
|---|----------|------------------------|
| 01 | [System & SaaS Architecture](docs/01-system-architecture.md) | Deliverables 1, 2, 37 — component map, data flows, why each part exists |
| 02 | [Multi-Tenancy & Security](docs/02-multitenancy-security.md) | 20, 22 — tenant isolation, RLS, RBAC, threat model |
| 03 | [AI / LLM / RAG Architecture](docs/03-ai-llm-rag.md) | 3, 4, 26, 27 — model routing, RAG, prompt management, cost control |
| 04 | [Ingestion & OCR Pipeline](docs/04-ingestion-ocr.md) | 5 — upload → OCR → structure detection → chunk → embed |
| 05 | [Question Generation & Validation](docs/05-question-pipeline.md) | 6, 7, 8, 21 — overgenerate → validate → dedupe → select |
| 06 | [Syllabus, Study Plan & Prediction](docs/06-syllabus-plan-prediction.md) | 9, 10, 11 — syllabus parsing, 5 plan modes, prediction engine |
| 07 | [Multilingual & PDF Engines](docs/07-multilingual-pdf.md) | 12, 13 — translation validation, Unicode PDF rendering |
| 08 | [Database Schema & ER Model](docs/08-database-schema.md) | 14, 15 — full PostgreSQL DDL, every relationship |
| 09 | [API Architecture & Schemas](docs/09-api-architecture.md) | 16, 34, 35 — REST surface, JSON Schemas, request/response |
| 10 | [Queue & Job Architecture](docs/10-queue-jobs.md) | 17 — Horizon topology, pipeline orchestration, idempotency |
| 11 | [Admin, Student & Subscription](docs/11-admin-student-subscription.md) | 18, 19, 21 — review workflow, dashboards, plans & metering |
| 12 | [Project Structure](docs/12-project-structure.md) | 24, 25 — Laravel and Next.js folder layouts |
| 13 | [Deployment, Scaling & Monitoring](docs/13-deployment-scaling.md) | 23, 28, 31, 32 — infra, cost, 100 → 100,000 users |
| 14 | [Roadmap & Implementation Order](docs/14-roadmap.md) | 29, 30, 33 — MVP → Phase 7, build sequence |
| 15 | [End-to-End Worked Example](docs/15-end-to-end-example.md) | 36 — 6th Science PDF → 200+ Q → Tamil → PDFs |

---

## Non-negotiable architectural rules

These are enforced in code review and in CI, not just documented.

1. **No LLM call on a user-facing HTTP request.** Every generation is a queued job.
2. **No question without a citation.** `question_sources` row is `NOT NULL`-enforced by trigger; a question with no chunk reference cannot reach `APPROVED`.
3. **Never send a whole book to a model.** Retrieval-augmented, chunk-scoped prompts only.
4. **Overgenerate, then filter.** Ask for 1.6–1.75× the target; never ask for an exact count.
5. **One logical question, many projections.** Never duplicate a question per exam or per language.
6. **Structured output or reject.** JSON Schema validated; raw text is never trusted or stored as truth.
7. **Nothing is deleted.** Rejected questions, superseded translations and old syllabus versions are retained and versioned.
8. **Every artifact is materialized.** A download re-serves a stored object; it never re-runs AI.
9. **Every AI call is traced and costed.** `ai_job` → `ai_invocation` → tokens → ₹ → tenant.
10. **Tenant isolation is defence-in-depth.** Laravel global scope *and* PostgreSQL Row-Level Security.
11. **Prediction is probabilistic.** The UI must never state or imply guaranteed questions.
12. **Configuration lives in the database.** Blueprints, quotas, thresholds, difficulty mixes — never in code constants.

---

## Technology decisions at a glance

| Layer | Choice | Why |
|---|---|---|
| Frontend | Next.js 15 (App Router), TypeScript, Tailwind, shadcn/ui | SSR for SEO on public/exam pages, RSC for heavy dashboards, one deploy target |
| API | Laravel 11 (PHP 8.3), Sanctum, Spatie Permission | Requested stack; excellent queue/ORM/policy story; fast team ramp-up |
| Async | Redis 7 + Laravel Horizon, dedicated worker fleet | Isolates 10-minute AI jobs from 200 ms API |
| Primary DB | PostgreSQL 16 + `pgvector` + `pg_trgm` + partitioning | One store for relational + vector; no separate vector DB to operate |
| Object store | Cloudflare R2 (S3 API) | Zero egress fees — this platform is a PDF-download business |
| LLM | Claude API (Opus 5 / Sonnet 5 / Haiku 4.5 tiering) | Structured output, prompt caching, strong Tamil handling |
| Embeddings | Self-hosted multilingual-e5-large (1024-d) on the worker fleet | Recurring per-chunk cost → own it; Tamil/English shared space |
| OCR | Google Document AI (primary), Tesseract 5 + Tamil traineddata (fallback/cheap tier) | Tamil script accuracy is the hard constraint |
| PDF | Typst worker service (Unicode/Indic shaping) with Gotenberg fallback | Deterministic, template-driven, fast, no browser fleet |
| Search | PostgreSQL FTS + pgvector hybrid | Avoids an Elasticsearch dependency until >5 M questions |

---

## Reading order for engineers

New backend engineer → 01, 08, 10, 05.
New AI engineer → 03, 04, 05, 07.
New frontend engineer → 09, 11, 12.
Founder / product → 01, 14, 13, 15.
