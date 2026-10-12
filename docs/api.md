# API reference

Base URL `/api/v1`. Everything except `POST /auth/login`, `POST /auth/register`
and `GET /languages` requires a bearer token.

```
Authorization: Bearer {token}
Content-Type: application/json
```

The API is **stateless**. It does not use cookies or CSRF tokens — an earlier
version did, and browser requests failed with 419 while every `curl` test
passed, because curl sends no `Origin` header. Do not reintroduce session
middleware.

## Response shape

Success:

```json
{ "data": { }, "meta": { } }
```

Failure is RFC 7807:

```json
{
  "type": "https://api.examforge.in/problems/validation-failed",
  "title": "Validation failed",
  "status": 422,
  "detail": "The request payload failed validation.",
  "errors": { "field": ["message"] },
  "meta": { "request_id": "01a0…" }
}
```

Send `X-Request-Id` on any write. It is stored on every audit entry the request
produces, which is what makes one admin action reassemblable from the log.

---

## The main flow

A book becomes a paper in six calls.

```
POST   /sources                          upload (multipart)
POST   /sources/{id}/process             read it — async, returns a job
GET    /jobs/{id}                        poll until COMPLETED
POST   /questions/generate               generate — async
POST   /sources/{id}/questions/decide    approve
POST   /sources/{id}/paper               build the PDF
GET    /documents/{id}/download          fetch the bytes
```

### `POST /sources`

`multipart/form-data`. `file`, `kind`, `title`, `language_id`, `rights_basis`.

Sources are deduplicated on content hash: re-uploading the same PDF returns the
existing source rather than creating a second one.

### `POST /questions/generate`

```json
{
  "source_id": "…", "exam_id": "…", "language_id": "…",
  "target_count": 50,
  "formats": ["mcq4", "statement_based", "assertion_reason", "match_the_following"],
  "canonical_topic_id": null, "difficulty": null, "count_pending": true
}
```

**Orders the shortfall, not the total.** With 43 already approved, asking for 50
generates 7. If the target is already met it returns `200` with
`status: "SKIPPED"` and generates nothing. The response carries the full
arithmetic under `plan` so the decision is visible:

```json
{ "plan": { "requested": 50, "approved": 43, "pending_review": 0,
            "rejected": 12, "deficit": 7, "satisfied": false } }
```

`count_pending=false` ignores questions awaiting review. The default counts
them, because generating twice before reviewing once otherwise orders the same
shortfall twice.

### `POST /sources/{id}/questions/decide`

```json
{ "decision": "approve", "min_quality": 85, "question_ids": ["…"] }
```

All three filters are optional; with none, every pending question for that book
is decided. Runs through the same review workflow as a single decision, so the
queue item closes and the audit trail is written.

### `POST /sources/{id}/paper`

```json
{ "title": "…", "question_count": 50, "duration_minutes": 90,
  "format": "pdf", "include_answer_key": true, "include_explanations": false }
```

`format` is `pdf` | `docx` | `xlsx` | `json`. Non-PDF formats carry paper and
answers in **one** bundle, so `include_answer_key` is ignored for them.

Returns `409` rather than printing an empty paper. Reports `requested_count`
alongside `question_count`, so a short paper is visible rather than silent —
near-duplicates are dropped during selection.

**`json` is the handover format for a student site.** It carries
`schema_version`, and is emitted from the same composed payload as the PDF so
the two cannot disagree.

---

## Previous-year papers

```
POST /pyqs                      record a paper
POST /pyqs/{id}/parse           read it from an uploaded PDF  { "source_id": "…" }
POST /pyqs/{id}/questions       or enter them by hand (replaces, never appends)
POST /pyqs/{id}/map             file questions under canonical topics
POST /pyqs/{id}/analyze         rebuild the frequency table
GET  /pyqs/analysis/{exam}      what the exam favours
```

Parsing reports `parsed`, `skipped` and `keyed`. **Skipped questions are stated,
not hidden** — a paper of 100 that yields 61 must not look complete.

Mapping cannot invent a topic: a slug the model returns that is not in the
taxonomy is discarded and the question stays unmapped.

The analysis feeds `SelectionEngine`'s prediction weight. Every response carries
a disclaimer that it describes past papers rather than predicting future ones.

---

## Generation targets

```
GET    /generation-targets
POST   /generation-targets              { exam_id, language_id, target_count, … }
GET    /generation-targets/backlog/{exam}
DELETE /generation-targets/{target}
```

The backlog is every target with its current shortfall, largest first. Targets
are per exam, topic, language **and** difficulty: fifty English questions do not
make a Tamil paper printable, and forty easy plus two hard looks healthy until a
paper needs hard ones.

---

## Structure and OCR repair

```
GET    /sources/{id}/structure                 the chapter/lesson tree
PATCH  /sources/{id}/chapters/{chapter}
PATCH  /sources/{id}/lessons/{lesson}
POST   /sources/{id}/chapters/{c}/lessons
DELETE /sources/{id}/lessons/{lesson}
GET    /sources/{id}/pages?needs_review=1      worst-confidence first
PATCH  /sources/{id}/pages/{page}              a human correction
POST   /sources/{id}/pages/{page}/retry        re-read one page
POST   /sources/{id}/reprocess                 rebuild chunks and embeddings
```

Any structural edit marks `chunk`, `embed` and `map` stale and blocks generation
until they are rebuilt — an edit that left the old passages in place would be
worse than no edit. Extraction is never discarded, so fixing a heading does not
re-read a 200-page PDF.

`retry` re-asks the extractor for one page; `PATCH` records what a person typed
and never overwrites `raw_text`, which is the record of what the machine read.

---

## Reporting

```
GET /dashboard                  counts across the system
GET /dashboard/trend?days=30    daily series, zero-filled (7–90)
GET /ai-history                 every model call and what it cost
GET /audit-logs                 who did what
GET /audit-logs/subject/{id}    the history of one record
```

Trend series include **days with no activity as zero**. Omitting them makes a
fortnight of nothing read as steady output.

`GET /ai-history` groups cost by operation, which is the question worth asking:
one operation on the wrong model tier explains most surprising bills.

Audit logs are **read-only**. The table refuses `UPDATE` and `DELETE` at the
database level, so there is no write path to expose.

---

## Rate limits

| Route | Limit |
|---|---|
| `POST /auth/login` | 20/min, plus 5 failures per email+IP before a 5-minute lock |
| `POST /sources`, `/process` | 120/hour |
| `POST /questions/generate` | 30/min |
| `POST /sources/{id}/paper` | 30/min |
| `POST /sources/{id}/questions/decide` | 60/min |

Generation's real ceiling is `AI_DAILY_BUDGET_INR`, checked before every model
call. The throttle only stops a stuck loop.

---

## Pagination

`GET /pyqs`, `/questions`, `/audit-logs` and `/documents` paginate. Pass
`per_page` (capped) and read `meta.total`, `meta.current_page`, `meta.last_page`.
