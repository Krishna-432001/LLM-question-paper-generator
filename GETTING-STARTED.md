# Getting Started

Phase 1 (Foundation) of the [architecture](README.md) is implemented in `apps/api`.

> **Phase 1 verified on 2026-08-14.** 16 migrations applied, 6 seeders run,
> 7/7 tests green, live API confirmed on `http://10.239.102.54:8000`.
> Five bugs were found and fixed during first execution — see "Bugs found" below.

## What exists today

| Area | Status |
|---|---|
| PostgreSQL schema (identity, tenancy, billing, exams, syllabi, sources, AI ledger, audit) | ✅ Complete, raw DDL with triggers, partitions and RLS |
| Three-layer tenant isolation + proof suite | ✅ Complete |
| `ai_jobs` ledger, `TrackedJob`, tenant-aware + cancellable job middleware | ✅ Complete |
| `AiGateway` — prompt registry, schema validation, response cache, budget guard, cost accounting | ✅ Complete |
| Fake LLM driver (offline, deterministic, schema-derived) | ✅ Complete |
| Claude provider adapter (structured outputs, prompt caching, refusal handling) | ✅ Complete |
| REST API: auth, me, catalogue, sources, jobs | ✅ 15 routes |
| Seeders: languages, roles/permissions, plans+quotas, AI models, prompts, TNPSC Group IV | ✅ Complete |
| **Ingestion pipeline** — extract → normalize → structure → chunk → embed → map | ✅ **Phase 2a complete**, all 7 stages real and resumable |
| PDF text extraction (`pdftotext`), Tamil/Indic normalization, structure heuristics, chunker | ✅ Validated on a real 99-page PDF |
| OCR adapters (Tesseract, fake) + embedding providers (HTTP e5, fake) | ✅ Behind contracts; degrade cleanly when a binary is absent |
| **Hybrid RAG retrieval** (vector + BM25, RRF fusion, coverage guard) | ✅ **Phase 2b complete** |
| **Question generation** — overgeneration, batching, citation-bound | ✅ Complete |
| **Validation gauntlet V1–V10** incl. the independent answer re-solve | ✅ Complete |
| **3-tier deduplication + quality scoring + review routing** | ✅ Complete |
| Master question bank: 13 tables, one question → many exams → many languages | ✅ Complete |
| **Review workflow API** — atomic claim/lease, versioned edits, reviewer metrics | ✅ **Phase 2c complete** |
| **Test assembly** — constrained selection, quotas, diversity, exposure control | ✅ **Phase 3a complete** |
| **Attempts + scoring + adaptive mastery** (EWMA per student/topic) | ✅ Complete |
| Solutions with citations, released only after submission | ✅ Complete |
| **Study-plan scheduler** — 5 modes, spaced repetition, capacity fitting | ✅ **Phase 3b complete** |
| **PDF engine** — question paper, answer key, explanations, study plan | ✅ **Complete** (mPDF, Indic shaping) |
| Reviewer console UI (Next.js) | ✅ Complete |
| **Admin console UI** — upload → ingest → generate → download | ✅ **Complete** (`/admin`) |
| **`POST /sources/{id}/paper`** — one call from book to PDF | ✅ Complete |
| **Bulk review** — approve/reject a book's batch, by quality or by hand | ✅ Complete, 6 tests |
| **Structure editor** — fix chapter/lesson boundaries, rebuild derived stages | ✅ **Complete** (`/admin/structure`) |
| **OCR correction** — worst-confidence-first page review, raw text preserved | ✅ **Complete** |
| **Citation snapshotting** — a printed citation survives a re-chunk | ✅ Complete |
| **OpenAI provider** — strict structured outputs, refusal + cached-token handling | ✅ Complete, 7 tests |
| **Previous-year papers** — entry, bulk questions, per-exam analysis | ✅ Complete |
| **PYQ PDF parsing** — bilingual option labels, answer grids, skip-not-guess | ✅ Complete, 8 tests |
| **PYQ topic mapping** — lexical first, constrained model, allow-list enforced | ✅ Complete, 6 tests |
| **Generation targets** — order the shortfall, never the whole number | ✅ Complete, 8 tests |
| **TNPSC blueprint** — real mark distribution as unit weights + backlog | ✅ Complete, 5 tests |
| **PYQ frequency analysis** — frequency + recency scoring, trend detection | ✅ Complete, 6 tests |
| **Malware scan on upload** — ClamAV (clamd INSTREAM), quarantine, permanent failure | ✅ Complete, 7 tests |
| **Exam management** — create/edit exams, papers, dates; `/admin/exams` | ✅ Complete, 9 tests |
| **Syllabus editor** — drafts, text import, compare, publish; `/admin/syllabus` | ✅ Complete, 9 tests |
| **Lesson → topic mapping** — lexical + aliases + constrained model, human override | ✅ Complete, 9 tests |
| TNPSC Group IV Part A (Tamil, 100 questions) in syllabus and blueprint | ✅ Complete |
| Translation engine | ⛔ In progress |
| Student UI | ⛔ In progress |

## The product flow

Everything else exists to serve these six steps, which live at
`http://<host>:3000/admin`:

1. **Upload a book** — `POST /sources` (multipart)
2. **Read it** — `POST /sources/{id}/process`, then poll `GET /jobs/{id}`
3. **Check what was read** — `/admin/structure`: fix chapter and lesson
   boundaries, correct badly read pages, then rebuild the passages
4. **Generate questions** — `POST /questions/generate`, same polling
5. **Approve them** — `POST /sources/{id}/questions/decide`, all at once, above a
   quality threshold, or one at a time with the passage on screen
6. **Download the paper** — `POST /sources/{id}/paper` returns the PDF links

Step 3 exists because detection is heuristics over a PDF text dump: it will
misplace a heading or merge two lessons, and the page range of a lesson decides
which passages a question may cite. Editing the tree marks the chunk, embed and
map stages stale and blocks generation until they are rebuilt — an edit that
left the old passages in place would be worse than no edit at all. Extraction is
never thrown away, so fixing a title does not re-run OCR on a 200-page book.

Step 5 exists because generated questions land in `PENDING_REVIEW` and nothing
unapproved is ever printed. Approving thirty questions one screen at a time is
what stops people reviewing at all, so the batch can be cleared in one click —
but it still runs through the same review workflow as a single decision, so the
queue item closes, the audit trail is written, and a claimed item is respected.

Step 6 assembles a test scoped to that one book, renders the paper and answer
key, and returns download URLs. It refuses with 409 rather than printing an
empty paper, and it reports `requested_count` alongside `question_count` so a
short paper is visible rather than silent.

**A queue worker must be running** or steps 2 and 3 stay queued forever:

```bash
php artisan queue:work --queue=critical,ingest,ocr,structure,embed,generate,validate,translate,pdf,plan,predict,maintenance
```

### Verified end to end on 2026-08-15

A 4-page textbook uploaded through the API, with no network and no API key:

```
upload      →  38,543 bytes, status uploaded
ingest      →  7 stages complete, 2 chapters, 10 chunks (8 usable, 2 exercises)
generate    →  34 drafted → 31 kept → 3 rejected (DUPLICATE_EXACT)
approve     →  5 at quality 88+, then the remaining 26 in one call
paper       →  25 questions, 3 pages, answer key 1 page, explanations 4 pages
download    →  200, 47,888 bytes
```

Extracted from the downloaded PDF:

```
Time: 30 min          TNPSC Group IV                     Marks: 25
                  General Science Unit Test
                        Questions: 25

1. Fill in the blank: Mercury has almost no atmosphere and therefore ______
   extreme temperature differences between day and night.
   (A) distillation      (B) atmosphere
   (C) experiences       (D) terrestrial
```

## Syllabus versions

A published syllabus never changes in place: plans and tests point at its id.
Change goes through a draft, at `/admin/syllabus`:

```
POST /api/v1/exams/{exam}/syllabus/drafts        copy the live version into a draft
POST /api/v1/syllabus-versions/{v}/import        paste a notification's text into it
GET  /api/v1/syllabus-versions/{v}/diff          what changed against the live version
POST /api/v1/syllabus-versions/{v}/publish       supersede the live version
```

Import is a parser, not a model. `UNIT I : GENERAL SCIENCE (5 Questions)` starts
a unit and its count becomes the weight; `(i)`, `1.` or `;` separate topics.
Publishing refuses a draft whose weights do not add up to 100%, a unit with no
topics, or a topic not linked to the question bank. A topic typed by name joins
the existing canonical topic of the same name, so re-typing "Indian Polity"
never forks the bank.

## Lesson → topic mapping

Until this existed every generated question had no topic, so the backlog never
counted them and blueprint weights never reached them. The ingestion `map`
stage now files each lesson under a canonical topic, and generation passes the
topic on to every question:

1. **Lexical** — topic phrases in the lesson or chapter title, recurring in the
   text, or a curated **alias** naming the lesson ("Plants and Photosynthesis"
   → Botany). Aliases are seeded for State Board Std 6–10 vocabulary.
2. **Model** — constrained classification for the rest; it cannot invent a
   topic or answer for a lesson it was not asked about.
3. **Human** — the *Syllabus topics* tab of `/admin/structure`. A person's
   choice survives every re-run and re-files the lesson's questions.

On `AI_DRIVER=fake` only steps 1 and 3 work: on the development database the
English science books map fully, and the Tamil reader is left unmapped until a
real model runs.

## Previous-year paper analysis

`SelectionEngine` has reserved a `prediction` weight in every profile since it
was written, and was fed an empty array the whole time. Previous-year papers
are what fill it.

```
POST /api/v1/pyqs                     record a paper
POST /api/v1/pyqs/{paper}/parse       read it out of an uploaded PDF
POST /api/v1/pyqs/{paper}/questions   or enter them by hand (replaces, never appends)
POST /api/v1/pyqs/{paper}/map         file its questions under canonical topics
POST /api/v1/pyqs/{paper}/analyze     rebuild the frequency table for that exam
GET  /api/v1/pyqs/analysis/{exam}     what the exam actually favours
```

Parsing is regex, not a model. A past paper is a fixed, highly regular document
and its questions are facts about what was asked — paying a model to read one
invites paraphrase into the single corpus that must stay verbatim, and costs
money per paper forever. Where regexes fail they fail visibly.

Two things make it harder than it looks. TNPSC papers are bilingual, labelling
options `(A)`–`(D)` in English and `அ) ஆ) இ) ஈ)` in Tamil, often in the same
paper — a Latin-only parser returns stems with every option still glued on. And
the answer key is a grid at the end (`1 - A  2 - C`), so it is matched back by
number, with a minimum run length so that prose like "Article 21 - a right" is
not mistaken for a key.

The rule throughout is **skip, never guess**: a block with three options, a
stem under eight characters, or a blank choice is dropped rather than repaired,
and the count of dropped questions is reported so a paper that parsed 61 of 100
cannot be mistaken for a complete one.

Verified on a rendered paper: `pages=1 parsed=6 skipped=0 keyed=6`, answers
matching the printed grid.

### Topic mapping

A parsed question carries no topic, and frequency analysis groups by topic, so
until mapping runs a paper contributes nothing. Cheapest first, as elsewhere:

1. **Lexical** — a topic name or alias appears verbatim in the stem. Free, and
   settles the obvious cases. Requires two words, or one long one: matching on
   "history" alone would file half the paper under history.
2. **Model** — constrained classification for the rest, batched 20 at a time.

The guarantee that matters: **the model cannot introduce a topic.** Its answer
is checked against the real slug list and dropped if absent, so a hallucinated
category never enters the taxonomy — the question simply stays unmapped, which
is the honest outcome. The schema keeps `topic_slug` a plain string rather than
an enum precisely so the allow-list is enforced in code, for every provider.

Three confidence bands: above 0.70 trusted, 0.45–0.70 recorded but marked
`ai_review`, below 0.45 discarded. A wrong topic is worse than no topic because
it silently skews every score derived from it.

A human mapping is never overwritten by a re-run, and a classifier outage
leaves the paper unmapped and retryable rather than half-filed.

On `AI_DRIVER=fake` mapping correctly yields nothing: the fake's synthesised
slug is not in the taxonomy, so the allow-list rejects all of it. Real mapping
needs a real model.

Scoring blends **how often** a topic appears with **how recently**, because
those disagree more often than not. Verified on five years of entered papers:

```
Scientific Knowledge    asked=9  yrs=3  last=2023  score=1.0000  rising
Physics: Force, Motion  asked=8  yrs=2  last=2020  score=0.8228  dormant
```

A raw count would rank those nearly level. Recency separates them, which is the
whole point — a topic that stopped appearing three years ago is not a good bet.

Two deliberate refusals: a topic the mapper could not place is **excluded**
rather than assigned to a default, and fewer than four papers never yields a
trend, so ordinary year-to-year variation is not reported as one.

## Switching AI provider

`AI_DRIVER=openai` with an `OPENAI_API_KEY`. Prompt versions name models in the
platform's own registry, so nothing in the prompt table changes — the adapter
maps registry names onto OpenAI ids.

Three things OpenAI needs that Anthropic does not, each pinned by a test:
strict-mode schemas require every property to be listed in `required`
(our optional fields become required-nullable), refusals arrive as HTTP 200
with a `refusal` field rather than an error, and reasoning models reject
`temperature` outright.

## Generation targets

Asking for fifty when forty-three exist used to order fifty. The tokens are
spent on the way in, so dedup then discarded forty-three questions that had
already been paid for. Generation now consults the bank first and orders only
the shortfall.

```
POST /api/v1/generation-targets            set a target for a topic
GET  /api/v1/generation-targets/backlog/{exam}   every shortfall, largest first
POST /api/v1/questions/generate            orders the deficit, not the total
```

Measured on the live bank:

```
ask for 40, 71 already approved   →  SKIPPED, nothing generated
ask for 100, 66 already held      →  ordered 34; model drafts 58, not 170
```

Three rules decide the arithmetic:

- **Rejected questions do not hold a slot.** A rejected draft is a gap, not a
  holding; counting it would leave a topic permanently short by however many
  bad drafts it once produced.
- **Questions awaiting review do**, by default. Generating twice before
  reviewing once would otherwise order the same shortfall twice and double the
  queue that is already the real bottleneck. Overridable with
  `count_pending=false`, and both numbers are always reported.
- **A target is per language and difficulty.** Fifty English questions do not
  make a Tamil paper printable, and a bank of forty easy and two hard ones
  looks healthy right up until a paper needs hard questions.

## The TNPSC Group IV blueprint

Two weights in `SelectionEngine` were reserved from the start and never
supplied. Both defaulted to a constant, which quietly asserted that every topic
matters equally:

| Weight | Filled by |
|---|---|
| `predictionByTopic` | previous-year paper frequency |
| `syllabusWeightByTopic` | this blueprint |

The mark distribution from the notification is now the unit weight:

```
Unit VII  Aptitude and Mental Ability                          0.25   (25 questions)
Unit V    Indian Economy and Development Administration in TN  0.20   (20)
Unit VI   History, Culture, Heritage and Socio-Political
          Movements of Tamil Nadu                               0.20   (20)
Unit IV   Indian Polity                                         0.15   (15)
Unit III  History, Culture of India and Indian National Movement 0.10  (10)
Unit I    General Science                                       0.05   (5)
Unit II   Geography                                             0.05   (5)
```

Polity is worth exactly three times General Science, which is what the
notification says and what the engine now acts on.

The same blueprint orders the backlog: **29 targets, 1,006 questions**, at ten
papers' depth per topic. One paper's worth would mean every paper drawn is the
same paper, with nothing for the exposure penalty to work with.

**Corrected 2026-10-12.** The first seed summed to 85 questions: it scored
Current Events as a 5-question unit, which Group IV does not have, and was
missing Unit VI (Tamil Nadu history and heritage, 20) altogether. Current
Events is kept with weight 0 and its targets deactivated, because questions
were already filed under its topics. Units were renamed in place, so existing
topics, targets and mappings stayed attached.

**Still not modelled:** Part A, the 100-question Tamil eligibility-cum-scoring
test. The weights above cover the other 100 questions of Paper I.

## Bugs found while running the admin flow

Five of these were invisible to the 37 tests that were green at the time.

| # | Bug | Why it mattered |
|---|---|---|
| 1 | **`TrackedJob::$aiJobId` was `readonly`** — PHP forbids reflectively initialising a parent's readonly property from a child scope, which is exactly what `SerializesModels::__unserialize` does | **Every queued job in the system failed on unserialize**, before `handle()` ran. Background processing did not work at all |
| 2 | `AiJob::create()` never set `status`, relying on the column DEFAULT | Eloquent does not read defaults back, so `$job->status` was `null` and `POST /questions/generate` returned 500 |
| 3 | `updateOrInsert` put `demand_count + 1` into an INSERT's VALUES clause | Postgres rejected the statement, failing generation on any coverage shortfall — the common path |
| 4 | Operation-named AI fixtures overrode every local generation | The same five questions came back for **every book uploaded**, and dedup then rejected them as duplicates. Fixtures now apply only under `runningUnitTests()` |
| 5 | The fake LLM synthesised each question from the JSON Schema | Every question came out with identical text, so semantic dedup correctly rejected all but one and no offline run could produce a bank. The fake now writes distinct passage-grounded questions and re-solves them by substituting each option back into the source |
| 6 | `questionCounts()` read statuses lower-case; they are stored upper-case | A book full of approved questions reported zero |
| 7 | **`citation()` read the breadcrumb through `source_chunk_id`, which is `ON DELETE SET NULL`** | Re-chunking an edited book deleted those chunks, so every previously approved question would have started printing **"Unknown source"** — silently, on papers already handed out. The breadcrumb is now snapshotted onto `question_sources` at write time. Verified: after a rebuild, 34 citations had lost their chunk and all 34 still rendered correctly |

Bug 1 is the one worth remembering: the test suite never exercised the real
queue transport, so a completely broken background pipeline stayed green.

### Accounts

```
admin@examforge.test     / ExamForge@2026      platform admin
reviewer@examforge.test  / reviewer-pass-99    content reviewer
```

### Phase 2a validated on real input

A 99-page Hindi PDF, end to end, no network:

| Metric | Result |
|---|---|
| Text layer detected | ✅ took the **free path** — no OCR spend |
| Pages extracted | 99 |
| Characters | 113,053 |
| Devanagari preserved | ✅ |
| Removed by normalizer | 2,238 chars of running headers/footers/page numbers |
| Chunks | 81, at 464 / 503 / 527 tokens — inside the 350–600 target band |
| Embeddings | 1024-dim, unit norm |


---

## Bugs found on first execution

Every one of these was invisible to linting and only surfaced against a real database.

| # | Bug | Fix |
|---|---|---|
| 1 | `TenantContext::forUser()` passed a `null` `is_platform_admin` — a freshly created model hasn't read the column DEFAULT back | Cast via `User::isPlatformAdmin()` |
| 2 | **The `organizations` RLS policy blocked the lookup that establishes the tenant.** Membership is resolved via `current_user_id()`, which wasn't set yet, so every authenticated request returned 403 | `TenantContext::seedUser()` publishes identity before tenancy |
| 3 | **`SET LOCAL app.org_id = ?` — PostgreSQL's `SET` rejects bind parameters.** The entire tenant context was broken; interpolating the id would have been an injection hole | Use `SELECT set_config(?, ?, ?)`, the parameterised function form |
| 4 | `setPermissionsTeamId()` called on the User model; it lives on Spatie's registrar | Call `app(PermissionRegistrar::class)` |
| 5 | **Spatie's team context was never set on the request, so every `->can()` check silently returned false** — authorization failed closed and invisibly | Set the active team in `ResolveTenant` |
| — | ENUM types survive `migrate:fresh`, breaking the second test run | Guard every `CREATE TYPE` with `EXCEPTION WHEN duplicate_object` |
| — | `email:rfc,dns` rejected `.test` addresses locally | DNS check only in production |

Bugs 2, 3 and 5 were security-relevant. This is the case for not building Phase 2 on
unexecuted Phase 1 code.

### Phase 2b validated: a real generation funnel

12-page book → 4 lessons → 16 chunks → generation:

```
raw generated:   20
rejected:
  ANSWER_MISMATCH        4     <- V8 independent re-solve caught mis-keyed items
  DUPLICATE_SEMANTIC    12     <- fixture repeats across lessons; dedup caught it
accepted:         4
shortfall:        8            <- reported, not hidden; logged as a coverage gap

BEST QUESTION  (quality 98.54/100)
  Which organ pumps blood throughout the human body?
    A   Lungs      B * Heart      C   Kidney      D   Liver
  citation: Debug Science › The Living World › Circulatory System › pp.1-3
  quote:    "It pumps blood through blood vessels called arteries"
```

Every surviving question carries a chunk-level citation and a verbatim quote.

## 1. Create the database (one time)

Run as the postgres superuser — **edit the password on line 12 first**:

```bash
psql -U postgres -h 127.0.0.1 -f apps/api/database/setup.sql
```

No psql on PATH? It's at `C:\Program Files\PostgreSQL\18\bin\psql.exe`, or paste the
file into pgAdmin's Query Tool.

## 2. Point the app at it

In `apps/api/.env`, set `DB_PASSWORD` to the password you chose:

```
DB_PASSWORD=change-this-password
```

## 3. Migrate and seed

```bash
cd apps/api
php artisan key:generate
php artisan migrate --seed
```

You should see ~10 migrations run, then the seeders create 12 languages, 6 roles,
5 plans with 40 quota rows, 4 AI models, 4 prompt versions, and the full TNPSC
Group IV exam → syllabus → unit → topic tree.

## 4. Run the test suite

```bash
php artisan db:create-test 2>/dev/null || true   # examforge_test already exists from setup.sql
php artisan test
```

The suite that matters is `tests/Tenancy/TenantIsolationTest.php`. It proves all
three isolation layers independently — including a **raw SQL** query that bypasses
Eloquent entirely to confirm PostgreSQL RLS is doing its job.

## 5. Start it

```bash
php artisan serve            # http://localhost:8000
php artisan queue:work --queue=critical,ingest,ocr,structure,generate,validate,pdf
```

### Try it end to end

```bash
# Register (auto-provisions a personal organization + free plan)
curl -sX POST http://localhost:8000/api/v1/auth/register \
  -H 'Content-Type: application/json' \
  -d '{"name":"Krishna","email":"k@example.com","password":"correct-horse-9","password_confirmation":"correct-horse-9"}'

# Use the returned token
TOKEN=...
curl -s http://localhost:8000/api/v1/me       -H "Authorization: Bearer $TOKEN"
curl -s http://localhost:8000/api/v1/exams    -H "Authorization: Bearer $TOKEN"
curl -s http://localhost:8000/api/v1/languages
```

---

## Turning on real AI

Everything runs on `AI_DRIVER=fake` by default — offline, deterministic, zero cost,
and it exercises the full pipeline because the fake synthesises its output from each
prompt's real JSON Schema.

To use Claude, set in `.env`:

```
AI_DRIVER=claude
ANTHROPIC_API_KEY=sk-ant-...
AI_DAILY_BUDGET_INR=2000
```

The daily budget is a hard circuit breaker checked before every call. Tests are
blocked from using a real provider by `AiServiceProvider::boot()`.

---

## Layout

```
apps/api/
├── app/
│   ├── Actions/          use cases — callable from both HTTP and queue
│   ├── Domain/           framework-light logic (Tenancy, Ai, Jobs)
│   ├── Infrastructure/   the ONLY place a vendor SDK may be imported
│   ├── Jobs/             orchestration only; TrackedJob owns status + retries
│   ├── Models/           Eloquent + BelongsToOrganization + HasUuidV7
│   └── Http/             thin controllers, FormRequests, ResolveTenant
├── database/
│   ├── migrations/       raw Postgres DDL (triggers, partitions, RLS)
│   ├── seeders/          platform content
│   └── setup.sql         one-time superuser bootstrap
└── tests/Tenancy/        the suite that must never go red
```

## Next: Phase 2

Per [docs/14-roadmap.md](docs/14-roadmap.md), in order:

1. OCR adapters (Document AI + Tesseract) and the digital-vs-scanned branch
2. Tamil text normalization + the low-confidence page quarantine
3. Structure detection (heuristics → cheap LLM repair) + the admin structure editor
4. pgvector install → chunking → embeddings → hybrid retrieval
5. Generation with overgeneration, then the validation gauntlet **in the same phase**
6. Reviewer workstation

The Phase 2 gate is in the roadmap and is not negotiable: ≥2,000 approved questions,
100% cited, reviewer override rate <15%, cost per approved question <₹6.
