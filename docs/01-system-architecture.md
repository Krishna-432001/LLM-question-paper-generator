# 01 — System & SaaS Architecture

Covers required outputs **1 (system architecture diagram)**, **2 (SaaS architecture)**,
**38 (final architecture)** and **37 (why every component exists)**.

---

## 1.1 Design premise

The economics of this business are decided by one number: **AI cost per served artifact**.

A naive implementation calls an LLM when a student clicks "generate test" — cost scales
linearly with users and the platform dies at scale. This architecture instead separates
the system into two planes:

| Plane | Trigger | Latency budget | Cost | Runs |
|---|---|---|---|---|
| **Production plane** (expensive, rare) | Admin uploads a source, or a scheduled refresh | Minutes to hours | AI tokens, OCR pages | Once per source document |
| **Projection plane** (cheap, constant) | Student clicks anything | < 300 ms | ~0 | Millions of times |

Everything in §3–§13 of the spec belongs to the production plane. Everything a student
touches is a projection: a SQL query with a selection policy, plus a stored PDF.

> One 6th-standard Science book costs roughly ₹180 to ingest and ₹1,100 to convert into
> 500 approved questions. Those 500 questions then serve 50,000 students at ₹0 marginal
> AI cost. That ratio *is* the product.

---

## 1.2 Complete system architecture

```mermaid
flowchart TB
    subgraph EDGE["Edge"]
        U["Students · Teachers · Org Admins · Platform Admins"]
        CF["Cloudflare CDN + WAF + Rate Limiting + Bot Mgmt"]
    end

    subgraph FE["Presentation"]
        NX["Next.js 15 App Router<br/>public site · student app · admin console"]
    end

    subgraph API["Application tier — stateless, autoscaled"]
        LB["Load Balancer / Ingress"]
        L1["Laravel API pod 1..N<br/>Sanctum auth · TenantContext · Policies<br/>Validation · Rate limits · Job dispatch"]
    end

    subgraph QUEUE["Coordination"]
        RD["Redis 7<br/>queues · cache · locks · rate counters"]
        HZ["Horizon supervisor<br/>autoscaling · retries · failed-job store"]
    end

    subgraph WORKERS["Worker fleet — independently scaled pools"]
        W1["ingest<br/>validate · virus scan · store"]
        W2["ocr<br/>Document AI / Tesseract"]
        W3["structure<br/>chapter · lesson · topic detect"]
        W4["embed<br/>e5-large on CPU/GPU"]
        W5["generate<br/>RAG + question generation"]
        W6["validate<br/>schema · grounding · answer · dedupe"]
        W7["syllabus + plan<br/>hierarchy parse · scheduler"]
        W8["prediction<br/>frequency · recency · scoring"]
        W9["translate<br/>master → target language"]
        W10["pdf<br/>Typst render service"]
        W11["maintenance<br/>reindex · metering · rollups"]
    end

    subgraph DATA["Data tier"]
        PG[("PostgreSQL 16<br/>primary + 2 read replicas<br/>pgvector · pg_trgm · partitions")]
        S3[("Object storage (R2/S3)<br/>sources · generated PDFs")]
        CH[("ClickHouse / TimescaleDB<br/>optional: analytics rollups @ scale")]
    end

    subgraph EXT["External services"]
        LLM["Claude API<br/>Opus 5 / Sonnet 5 / Haiku 4.5"]
        DOC["Google Document AI (OCR)"]
        PAY["Razorpay (subs, UPI, webhooks)"]
        AV["ClamAV / VirusTotal"]
        MAIL["Email + WhatsApp/SMS notifications"]
    end

    subgraph OBS["Observability & control"]
        OT["OpenTelemetry Collector"]
        GRA["Grafana · Loki · Tempo · Prometheus"]
        SEN["Sentry (errors)"]
        LFT["LLM trace store (ai_invocations)"]
    end

    U --> CF --> NX --> LB --> L1
    L1 --> RD
    L1 --> PG
    L1 --> S3
    HZ -.supervises.-> WORKERS
    RD <--> WORKERS
    WORKERS --> PG
    WORKERS --> S3
    W2 --> DOC
    W5 --> LLM
    W6 --> LLM
    W7 --> LLM
    W8 --> LLM
    W9 --> LLM
    W1 --> AV
    L1 --> PAY
    PAY -.signed webhook.-> L1
    L1 --> MAIL
    L1 & WORKERS --> OT --> GRA
    L1 & WORKERS --> SEN
    W5 & W6 & W9 --> LFT --> PG
    PG -.read replicas.-> L1
    WORKERS -.rollups.-> CH
```

### Why each component exists

| Component | Why it is required | What breaks without it |
|---|---|---|
| **Cloudflare CDN/WAF** | PDFs are the payload; egress and bot scraping are the top cost/abuse risks | Bandwidth bill, question-bank scraping by competitors |
| **Next.js** | Public exam pages need SEO; dashboards need heavy client interactivity | Either no organic acquisition or a sluggish app |
| **Laravel API (stateless)** | Business rules, tenancy, entitlement checks, job dispatch in one place | Rules leak into frontend → tenant/quota bypass |
| **Redis + Horizon** | AI jobs take 30 s–20 min; HTTP cannot hold them | 504s, lost uploads, no retries, no visibility |
| **Separate worker pools** | OCR is CPU-bound, embedding is GPU/CPU-bound, generation is network-bound | One slow book upload starves all translations |
| **PostgreSQL + pgvector** | Relational integrity (citations, mappings) *and* semantic search in one transaction | Dual-write drift between a vector DB and Postgres |
| **Read replicas** | Analytics and question-bank browsing are read-heavy | Student dashboards contend with ingestion writes |
| **Object storage** | Books are 50–300 MB; PDFs are generated by the million | Postgres bloat, unrestorable backups, slow WAL |
| **Prompt registry in DB** | Reproducibility and A/B of generation quality | Cannot explain or reproduce why a question was generated |
| **ai_invocations trace** | Cost attribution per tenant and per feature | Unbounded, unattributable AI spend |
| **PDF worker service** | Indic shaping and pagination are deterministic work | Malformed Tamil ligatures, broken answer keys |
| **ClickHouse (later)** | Per-answer analytics at 100 k users exceeds OLTP comfort | Analytics queries lock up the OLTP primary |

---

## 1.3 The four data flows

```mermaid
flowchart LR
    subgraph F1["FLOW 1 — Knowledge → Questions"]
        A1[Book PDF] --> A2[OCR + Structure] --> A3[Chunks + Embeddings]
        A3 --> A4[RAG Generation] --> A5[Validation + Dedupe]
        A5 --> A6[(Master Question Bank)]
    end
    subgraph F2["FLOW 2 — Syllabus → Plans → Tests"]
        B1[Syllabus PDF] --> B2[Hierarchy Extraction] --> B3[(Versioned Syllabus)]
        B3 --> B4[Study Plan Engine] --> B5[Daily / 3-Day / Weekly / Monthly / Model]
        B5 --> B6[Test Assembly]
    end
    subgraph F3["FLOW 3 — Past Papers → Prediction"]
        C1[Previous Papers] --> C2[Question Extraction] --> C3[Topic Classification]
        C3 --> C4[Frequency · Recency · Trend] --> C5[Prediction Scores]
        C5 --> C6[5 Model Papers + Topic Report]
    end
    subgraph F4["FLOW 4 — Master → Language → PDF"]
        D1[Master Question] --> D2[Language Selection] --> D3[Translate + Validate]
        D3 --> D4[(question_translations)] --> D5[Typst Render] --> D6[Q Paper + Answer Key]
    end

    A6 --> B6
    A6 --> C6
    C5 --> B6
    B6 --> D1
    C6 --> D1
```

The critical property: **all four flows converge on one question bank**. Flow 2 and Flow 3
do not generate questions — they *select* from Flow 1's output using different policies.
Flow 4 does not generate questions — it *projects* them into a language. This is what makes
adding the 6th language or the 40th exam cheap.

---

## 1.4 Layered internal architecture (Laravel)

```
HTTP / Console entrypoints
  └── Http\Controllers\Api\V1        thin: validate → authorize → call Action → return Resource
        └── Http\Requests            FormRequest validation (never inline)
        └── Policies                 per-model authorization (org + role)
  └── Actions (application layer)    single-purpose use cases, unit-testable, no HTTP knowledge
        └── Domain\*\Services        pure domain logic: SelectionEngine, PredictionScorer, PlanScheduler
        └── Domain\*\Contracts       interfaces: LlmClient, OcrEngine, EmbeddingProvider, PdfRenderer
              └── Infrastructure\*   Claude, DocumentAI, E5, Typst, R2 adapters
  └── Jobs (queued)                  orchestration only — call Actions, own retries/idempotency
  └── Models (Eloquent)              persistence + relationships + global tenant scope
```

**Rule:** a Controller and a Job must be able to invoke the *same* Action. A Job never
contains business logic; an Action never knows whether it is queued.

---

## 1.5 SaaS architecture

```mermaid
flowchart TB
    subgraph PLATFORM["Platform tenant (organization_id = NULL)"]
        PC["Canonical content:<br/>exams · syllabi · state-board books ·<br/>previous papers · approved questions"]
    end
    subgraph T1["Tenant: Anna Academy"]
        T1U["120 students, 8 teachers"]
        T1C["Private: own notes, own questions,<br/>own branded tests"]
    end
    subgraph T2["Tenant: individual student (personal org)"]
        T2U["1 user"]
    end
    PC -->|read-only inheritance| T1C
    PC -->|read-only inheritance| T2U
    T1C -.->|never visible| T2U
```

Every user belongs to an organization — including individual students, who get an
auto-provisioned personal organization. This removes all `if (individual) … else …`
branching from the codebase: **there is only the tenant path.**

**Content visibility rule** (one line, used everywhere):

```sql
WHERE organization_id IS NULL OR organization_id = current_setting('app.org_id')::uuid
```

Platform-owned content (`organization_id IS NULL`) is the shared asset that makes the
product valuable on day one for a new tenant. Tenant-owned content is private forever.
Promotion of tenant content to platform content is an explicit, audited admin action.

---

## 1.6 Request lifecycle — a student generating a weekly test

```mermaid
sequenceDiagram
    participant S as Student (Next.js)
    participant A as Laravel API
    participant R as Redis
    participant W as Worker
    participant P as PostgreSQL
    participant O as Object storage

    S->>A: POST /api/v1/study-plans/weekly {exam, dates, language}
    A->>A: auth → tenant context → policy → quota check (usage_records)
    A->>P: INSERT ai_jobs (QUEUED) + study_plans (DRAFT)
    A->>R: dispatch GenerateWeeklyPlan(job_id)
    A-->>S: 202 Accepted {job_id, poll_url}
    S->>A: GET /api/v1/jobs/{id} (poll 2s / or SSE)
    W->>R: reserve job
    W->>P: read syllabus version + coverage state + performance
    W->>W: PlanScheduler (deterministic) → 7 days of topics
    W->>P: read question bank via SelectionEngine (no LLM)
    W->>P: INSERT study_plan_days, tests, test_questions
    W->>R: chain → RenderPdf(plan) , RenderPdf(paper), RenderPdf(key)
    W->>O: PUT 3 PDFs
    W->>P: INSERT generated_documents, ai_jobs → COMPLETED
    S->>A: GET /api/v1/jobs/{id} → COMPLETED {document_ids}
    S->>A: GET /api/v1/documents/{id}/download
    A->>O: presign (5 min, tenant-scoped)
    A-->>S: 302 → signed URL
```

Note what is absent: **no LLM call**. A weekly plan for an existing exam+syllabus is pure
computation over existing data. LLM involvement in plan generation is limited to an
optional "coach note" per day, cached per (syllabus_version, plan_shape) and reused across
all tenants.

---

## 1.7 Environments

| Env | Purpose | Data | AI |
|---|---|---|---|
| `local` | Dev | Seeded fixtures, 1 book, 50 questions | Recorded LLM fixtures (VCR), no spend |
| `ci` | Tests | Ephemeral Postgres+Redis in containers | Fake drivers only — network blocked |
| `staging` | Pre-prod, content team rehearsal | Anonymized subset | Real models, hard ₹ cap/day |
| `production` | Live | Full | Real models, per-tenant quotas |

CI blocks the merge if any test performs a real network call to an LLM provider.
