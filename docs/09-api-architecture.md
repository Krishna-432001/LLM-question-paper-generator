# 09 — API Architecture, JSON Schemas & Contracts

Covers required outputs **16 (API architecture)**, **34 (sample JSON schemas)** and
**35 (sample request/response structures)**.

---

## 9.1 Principles

| Principle | Implementation |
|---|---|
| REST first, versioned | `/api/v1/...`; version in the path; breaking changes only in `v2` |
| Async by default for AI | Any endpoint that could exceed 1 s returns `202 Accepted` + `job_id` |
| Consistent envelope | `{data, meta, links}` on success; RFC 7807 problem+json on error |
| Cursor pagination | Offset pagination is banned on question/attempt endpoints (deep pages + scraping) |
| Idempotency | `Idempotency-Key` header required on all `POST` that spend money or AI tokens |
| Tenant implicit | Never accept `organization_id` in a body; always derived from the token |
| Field selection | `?include=options,translations` (whitelisted) to keep payloads small |
| Rate limited | Headers `X-RateLimit-Limit/Remaining/Reset` on every response |

Auth: Sanctum SPA cookie session for the Next.js app; personal access tokens
(`Authorization: Bearer`) for institute integrations; both carry abilities.

---

## 9.2 Endpoint surface

### Auth & account
```
POST   /api/v1/auth/register
POST   /api/v1/auth/login
POST   /api/v1/auth/logout
POST   /api/v1/auth/refresh
POST   /api/v1/auth/forgot-password
POST   /api/v1/auth/reset-password
POST   /api/v1/auth/verify-email
POST   /api/v1/auth/mfa/enroll | /verify | /disable
GET    /api/v1/me
PATCH  /api/v1/me                          { name, preferred_language_id, timezone }
GET    /api/v1/me/organizations
POST   /api/v1/me/organizations/{id}/switch
POST   /api/v1/account/erasure             (DPDP right to erasure)
```

### Catalogue (mostly cacheable, public)
```
GET    /api/v1/languages
GET    /api/v1/exams                       ?state=TN&category=state_govt
GET    /api/v1/exams/{id}
GET    /api/v1/exams/{id}/versions
GET    /api/v1/exams/{id}/syllabus         ?version=active   → full tree
GET    /api/v1/exams/{id}/blueprints
GET    /api/v1/syllabus-versions/{id}/topics
GET    /api/v1/syllabus-versions/{id}/diff?from={id}
```

### Sources & ingestion (admin)
```
POST   /api/v1/uploads/presign             { filename, mime, size } → { url, fields, key }
POST   /api/v1/sources                     { kind, title, storage_key, language_id, rights_basis, meta }
GET    /api/v1/sources                     ?kind=textbook&status=ready
GET    /api/v1/sources/{id}
POST   /api/v1/sources/{id}/process        { stages?: [...] }            → 202 job
POST   /api/v1/sources/{id}/restart        { from_stage }                → 202 job
GET    /api/v1/sources/{id}/status
GET    /api/v1/books/{id}/structure                                     → chapter/lesson tree
PATCH  /api/v1/books/{id}/structure        (admin corrections)
GET    /api/v1/books/{id}/pages/{n}                                     → text + image + confidence
PATCH  /api/v1/books/{id}/pages/{n}        { normalized_text }          (OCR correction)
GET    /api/v1/books/{id}/coverage                                      → syllabus coverage report
```

### Syllabus engine
```
POST   /api/v1/syllabi                     { exam_id, name }
POST   /api/v1/syllabi/{id}/analyze        { source_id }                → 202 job
GET    /api/v1/syllabus-versions/{id}/review
POST   /api/v1/syllabus-versions/{id}/publish
```

### Question bank
```
POST   /api/v1/questions/generate          → 202 job     (see §9.4)
GET    /api/v1/questions                   ?exam_id&topic_id&difficulty&language&status&cursor
GET    /api/v1/questions/{id}              ?include=options,sources,translations,versions
PATCH  /api/v1/questions/{id}
POST   /api/v1/questions/{id}/approve      { note? }
POST   /api/v1/questions/{id}/reject       { reason_code, note }
POST   /api/v1/questions/{id}/revision     { note }
POST   /api/v1/questions/{id}/regenerate   → 202 job
POST   /api/v1/questions/{id}/translate    { language_id }              → 202 job
GET    /api/v1/questions/{id}/duplicates
GET    /api/v1/questions/rejected          ?code&cursor
POST   /api/v1/questions/bulk-approve      { question_ids[] }           (reviewer, ≤200)
GET    /api/v1/question-bank/health        ?exam_id  → counts by topic/difficulty/language
```

### Previous papers & prediction
```
POST   /api/v1/previous-papers             { source_id, exam_id, exam_year, ... }
POST   /api/v1/previous-papers/{id}/analyze                             → 202 job
GET    /api/v1/previous-papers/{id}/questions
PATCH  /api/v1/previous-paper-questions/{id}      (reviewer fixes)
POST   /api/v1/predictions/generate        { exam_id, target_year }     → 202 job
GET    /api/v1/predictions                 ?exam_id&target_year&band
GET    /api/v1/predictions/topics/{canonical_topic_id}                  → score + components
GET    /api/v1/predictions/backtest        ?exam_id                     → published accuracy
POST   /api/v1/predictions/model-papers    { exam_id, target_year, language_id, count: 5 }
                                                                         → 202 job (10 PDFs)
```

### Study plans
```
POST   /api/v1/study-plans/daily
POST   /api/v1/study-plans/three-day
POST   /api/v1/study-plans/weekly
POST   /api/v1/study-plans/monthly
POST   /api/v1/study-plans/model-test
GET    /api/v1/study-plans                 ?mode&status
GET    /api/v1/study-plans/{id}            ?include=days.items
GET    /api/v1/study-plans/{id}/days/{date}
POST   /api/v1/study-plans/{id}/items/{itemId}/complete
POST   /api/v1/study-plans/{id}/replan     { reason }                   → 202 job
DELETE /api/v1/study-plans/{id}
```

### Tests & attempts
```
POST   /api/v1/tests                       { exam_id, type, blueprint_id, filters, language_id }
POST   /api/v1/tests/model                 { exam_id, blueprint_id, language_id }
GET    /api/v1/tests                       ?type&exam_id&cursor
GET    /api/v1/tests/{id}                  ?include=questions  (options hidden until attempt)
POST   /api/v1/tests/{id}/attempt                                       → attempt with questions
PATCH  /api/v1/attempts/{id}/answers       { answers: [{test_question_id, selected_option, time_spent_seconds}] }
POST   /api/v1/attempts/{id}/submit
GET    /api/v1/attempts/{id}
GET    /api/v1/results/{attemptId}                                      → full result + breakdown
GET    /api/v1/results/{attemptId}/solutions                            → answers + explanations
```

### Documents
```
POST   /api/v1/documents/generate          { documentable_type, documentable_id, types[], language_id }
                                                                         → 202 job
GET    /api/v1/documents                   ?type&cursor
GET    /api/v1/documents/{id}
GET    /api/v1/documents/{id}/preview                                    → first-page PNG
GET    /api/v1/documents/{id}/download                                   → 302 signed URL
POST   /api/v1/documents/{id}/regenerate                                 → 202 job (new document)
POST   /api/v1/documents/{id}/share        { expires_at, password?, max_downloads? }
DELETE /api/v1/document-shares/{token}
GET    /s/{token}                          (public share resolver, rate-limited)
```

### Analytics
```
GET    /api/v1/analytics/overview                     ?exam_id&range=30d
GET    /api/v1/analytics/topics                       ?exam_id  → mastery per topic
GET    /api/v1/analytics/difficulty
GET    /api/v1/analytics/time
GET    /api/v1/analytics/streak
GET    /api/v1/analytics/weak-topics                  → ranked with recommended actions
```

### Jobs (universal async contract)
```
GET    /api/v1/jobs                        ?type&status&cursor
GET    /api/v1/jobs/{id}
GET    /api/v1/jobs/{id}/events            (SSE stream: progress updates)
POST   /api/v1/jobs/{id}/cancel
POST   /api/v1/jobs/{id}/retry             (admin)
```

### Subscription & billing
```
GET    /api/v1/plans
GET    /api/v1/subscription
POST   /api/v1/subscription/checkout       { plan_id, period }  → provider checkout payload
POST   /api/v1/subscription/cancel
GET    /api/v1/usage                       ?period=current      → quotas vs consumption
GET    /api/v1/invoices
POST   /api/v1/webhooks/razorpay           (signature-verified, no auth)
```

### Admin-only
```
GET    /api/v1/admin/dashboard
GET    /api/v1/admin/organizations | /users | /subscriptions | /payments
GET    /api/v1/admin/reviews               ?status=pending&assigned_to=me
POST   /api/v1/admin/reviews/{id}/claim
GET    /api/v1/admin/prompts | POST /api/v1/admin/prompts/{name}/versions
POST   /api/v1/admin/prompts/versions/{id}/evaluate | /promote | /rollback
GET    /api/v1/admin/models | PATCH /api/v1/admin/models/{id}
GET    /api/v1/admin/usage/costs           ?group_by=org|exam|operation
GET    /api/v1/admin/audit-logs
GET    /api/v1/admin/coverage-gaps         → the content backlog, ranked by demand
POST   /api/v1/admin/impersonate/{userId}  (audited, 30-min token)
```

---

## 9.3 Standard envelopes

**Success**
```json
{
  "data": { },
  "meta": { "request_id": "req_01J8...", "generated_at": "2026-08-13T10:00:00Z" },
  "links": { "next": "/api/v1/questions?cursor=eyJpZCI6..." }
}
```

**Async accepted (202)**
```json
{
  "data": {
    "job_id": "018f5c2a-...",
    "type": "generation",
    "status": "QUEUED",
    "progress": 0,
    "estimated_seconds": 420,
    "poll_url": "/api/v1/jobs/018f5c2a-...",
    "events_url": "/api/v1/jobs/018f5c2a-.../events"
  }
}
```

**Error (RFC 7807)**
```json
{
  "type": "https://api.examforge.in/problems/quota-exceeded",
  "title": "Monthly question generation quota exceeded",
  "status": 402,
  "detail": "Your BASIC plan allows 500 generated questions per month. You have used 500.",
  "instance": "/api/v1/questions/generate",
  "errors": null,
  "meta": {
    "metric": "questions_generated", "limit": 500, "used": 500,
    "resets_at": "2026-09-01T00:00:00Z",
    "upgrade_url": "https://app.examforge.in/subscription"
  }
}
```

Status-code contract: `400` malformed · `401` unauthenticated · `403` policy denied ·
`404` not found *or not in your tenant* (never leak existence) · `409` idempotency/state
conflict · `422` validation · `402` quota · `429` rate limit · `503` provider circuit open.

---

## 9.4 Worked request/response examples

### A. Generate questions from a book

```http
POST /api/v1/questions/generate HTTP/1.1
Authorization: Bearer <token>
Idempotency-Key: 7b1f9c50-gen-6sci-u3
Content-Type: application/json

{
  "scope": { "book_id": "018e9a1c-...", "book_chapter_ids": ["018e9a2f-..."] },
  "exam_id": "018e0001-...",
  "target_count": 200,
  "format": "mcq4",
  "difficulty_mix": { "easy": 0.40, "medium": 0.40, "hard": 0.20 },
  "language_id": "018e0000-en",
  "options": {
    "overgeneration_factor": 1.70,
    "auto_map_exams": ["TNPSC_G4", "TNUSRB_PC"],
    "exclude_existing": true
  }
}
```

```http
HTTP/1.1 202 Accepted
Location: /api/v1/jobs/018f5c2a-...

{ "data": { "job_id": "018f5c2a-...", "type": "generation", "status": "QUEUED",
            "progress": 0, "estimated_seconds": 900,
            "estimated_cost_inr": 412.50,
            "planned": { "raw_questions": 340, "batches": 14, "target_approved": 200 },
            "poll_url": "/api/v1/jobs/018f5c2a-..." } }
```

### B. Poll the job

```json
{
  "data": {
    "id": "018f5c2a-...", "type": "generation", "status": "VALIDATING", "progress": 72,
    "progress_message": "Validated 244/340 · 31 duplicates · 18 unsupported",
    "started_at": "2026-08-13T10:02:11Z",
    "children": [
      {"type":"generation","status":"COMPLETED","progress":100,"output":{"raw":340}},
      {"type":"validation","status":"PROCESSING","progress":72}
    ],
    "usage": { "input_tokens": 486210, "output_tokens": 214880, "cost_inr": 351.44 }
  }
}
```

### C. Completed job output

```json
{
  "data": {
    "id": "018f5c2a-...", "status": "COMPLETED", "progress": 100,
    "duration_ms": 842330,
    "output": {
      "raw_generated": 340,
      "rejected": { "DUPLICATE_EXACT": 12, "DUPLICATE_SEMANTIC": 19,
                    "UNSUPPORTED": 18, "OPTION_INVALID": 14, "ANSWER_MISMATCH": 6,
                    "SCHEMA_INVALID": 3, "LOW_QUALITY": 11 },
      "valid": 257,
      "pending_review": 257,
      "quality": { "mean": 82.4, "p10": 68.0, "p90": 93.5 },
      "coverage": { "lessons_covered": 9, "lessons_total": 9,
                    "topics_uncovered": [] },
      "question_ids_sample": ["018f5d01-...", "018f5d02-..."]
    },
    "usage": { "input_tokens": 612480, "output_tokens": 288150, "cost_inr": 438.92 }
  }
}
```

### D. Fetch a question with everything

```http
GET /api/v1/questions/018f5d01-...?include=options,sources,translations
```

```json
{
  "data": {
    "id": "018f5d01-...",
    "format": "mcq4", "origin": "ai_generated", "status": "APPROVED",
    "difficulty": "easy", "bloom_level": "remember",
    "quality_score": 91.5, "ai_confidence": 0.94,
    "master_language": "en",
    "topic": { "canonical_topic_id": "018e7a10-...", "name": "Human Circulatory System",
               "path": "science.biology.human_body.circulatory_system" },
    "options": [
      {"key":"A","position":1,"is_correct":false},
      {"key":"B","position":2,"is_correct":true},
      {"key":"C","position":3,"is_correct":false},
      {"key":"D","position":4,"is_correct":false}
    ],
    "translations": {
      "en": { "question_text": "Which organ pumps blood throughout the human body?",
              "option_a": "Lungs", "option_b": "Heart", "option_c": "Kidney",
              "option_d": "Liver",
              "explanation": "The heart is a muscular organ that pumps blood through the circulatory system.",
              "status": "HUMAN_APPROVED", "is_master": true },
      "ta": { "question_text": "மனித உடலில் இரத்தத்தை உந்தித் தள்ளும் உறுப்பு எது?",
              "option_a": "நுரையீரல்", "option_b": "இதயம்", "option_c": "சிறுநீரகம்",
              "option_d": "கல்லீரல்",
              "explanation": "இதயம் ஒரு தசை உறுப்பு ஆகும். இது இரத்த ஓட்ட மண்டலம் வழியாக இரத்தத்தை உந்தித் தள்ளுகிறது.",
              "status": "AI_VALIDATED", "quality_score": 96.0, "is_master": false }
    },
    "sources": [{
      "source_id": "018e9a1c-...", "book": "6th Standard Science (TN State Board)",
      "chapter": "Unit 3 — Living World", "lesson": "3.2 Circulatory System",
      "page_start": 42, "page_end": 43, "chunk_id": "018e9b77-...",
      "supporting_quote": "The heart is a muscular organ that pumps blood to all parts of the body."
    }],
    "exam_mappings": [
      {"exam_code":"TNPSC_G4","suitability":0.95,"difficulty_for_exam":"easy"},
      {"exam_code":"TNUSRB_PC","suitability":0.90,"difficulty_for_exam":"easy"},
      {"exam_code":"SSC_CGL","suitability":0.72,"difficulty_for_exam":"easy"}
    ],
    "stats": { "times_served": 18422, "times_attempted": 15230, "p_value": 0.83 }
  }
}
```

### E. Create a weekly study plan

```http
POST /api/v1/study-plans/weekly
{
  "exam_id": "018e0001-...",
  "syllabus_version_id": "018e0100-...",
  "start_date": "2026-08-17",
  "exam_date": "2027-02-15",
  "hours_per_day": 5,
  "off_days": [0],
  "language_id": "018e0000-ta",
  "focus_subjects": ["General Studies", "Aptitude"],
  "generate_documents": ["study_plan", "question_paper", "answer_key"]
}
```

```json
{ "data": { "job_id":"018f6a11-...", "status":"QUEUED",
            "study_plan_id":"018f6a10-...", "estimated_seconds": 45,
            "poll_url":"/api/v1/jobs/018f6a11-..." } }
```

### F. Submit an attempt

```http
POST /api/v1/attempts/018f7c00-.../submit
{ "answers": [ {"test_question_id":"018f7b01-...","selected_option":"B","time_spent_seconds":24} ] }
```

```json
{
  "data": {
    "attempt_id": "018f7c00-...", "submitted_at": "2026-08-20T11:31:04Z",
    "result": {
      "total_questions": 50, "attempted": 48, "correct": 39, "wrong": 9,
      "raw_score": 58.5, "negative_score": 4.5, "final_score": 54.0,
      "accuracy": 81.25, "percentile": 76.4, "time_taken_seconds": 3120,
      "topic_breakdown": {
        "Indian Polity": {"attempted":10,"correct":5,"accuracy":50.0},
        "TN History":    {"attempted":12,"correct":11,"accuracy":91.7}
      },
      "weak_topics": [{"id":"018e7b21-...","name":"Indian Polity","mastery":0.42,
                       "action":"3 extra revision slots scheduled for 22, 25, 31 Aug"}]
    },
    "plan_updated": true,
    "solutions_url": "/api/v1/results/018f7c00-.../solutions"
  }
}
```

---

## 9.5 JSON Schemas (LLM contracts)

### `question_generation.output`

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "title": "QuestionGenerationOutput",
  "type": "object", "additionalProperties": false,
  "required": ["questions"],
  "properties": {
    "questions": {
      "type": "array", "minItems": 1, "maxItems": 30,
      "items": {
        "type": "object", "additionalProperties": false,
        "required": ["question_text","options","correct_option","explanation",
                     "difficulty","bloom_level","topic","source_refs",
                     "supporting_quote","confidence"],
        "properties": {
          "question_text":   {"type":"string","minLength":15,"maxLength":600},
          "options": {
            "type":"object","additionalProperties":false,
            "required":["A","B","C","D"],
            "properties":{"A":{"type":"string","minLength":1,"maxLength":300},
                          "B":{"type":"string","minLength":1,"maxLength":300},
                          "C":{"type":"string","minLength":1,"maxLength":300},
                          "D":{"type":"string","minLength":1,"maxLength":300}}
          },
          "correct_option":  {"type":"string","enum":["A","B","C","D"]},
          "explanation":     {"type":"string","minLength":25,"maxLength":900},
          "difficulty":      {"type":"string","enum":["easy","medium","hard"]},
          "bloom_level":     {"type":"string",
                              "enum":["remember","understand","apply","analyse"]},
          "topic":           {"type":"string","maxLength":150},
          "subtopic":        {"type":["string","null"],"maxLength":150},
          "source_refs":     {"type":"array","minItems":1,"maxItems":4,
                              "items":{"type":"string","pattern":"^S[0-9]{1,2}$"}},
          "supporting_quote":{"type":"string","minLength":10,"maxLength":300},
          "confidence":      {"type":"number","minimum":0,"maximum":1}
        }
      }
    },
    "notes": {"type":["string","null"],"maxLength":500}
  }
}
```

### `question_validation.output`

```json
{
  "type": "object", "additionalProperties": false,
  "required": ["results"],
  "properties": {
    "results": {
      "type": "array",
      "items": {
        "type": "object", "additionalProperties": false,
        "required": ["ref","supported","answer_correct","single_correct",
                     "options_valid","issues","confidence"],
        "properties": {
          "ref":            {"type":"string"},
          "supported":      {"type":"boolean"},
          "support_span":   {"type":["string","null"]},
          "answer_correct": {"type":"boolean"},
          "model_choice":   {"type":"string","enum":["A","B","C","D"]},
          "single_correct": {"type":"boolean"},
          "options_valid":  {"type":"boolean"},
          "grammar_ok":     {"type":"boolean"},
          "level_appropriate": {"type":"boolean"},
          "suggested_difficulty": {"type":"string","enum":["easy","medium","hard"]},
          "issues": {"type":"array","items":{"type":"string",
                     "enum":["UNSUPPORTED","ANSWER_MISMATCH","MULTIPLE_CORRECT",
                             "DISTRACTOR_WEAK","ANSWER_LEAK","AMBIGUOUS_STEM",
                             "GRAMMAR","LEVEL_MISMATCH","OPTION_OVERLAP"]}},
          "confidence":     {"type":"number","minimum":0,"maximum":1}
        }
      }
    }
  }
}
```

### `syllabus_extraction.output`

```json
{
  "type":"object","additionalProperties":false,
  "required":["exam_name","papers"],
  "properties":{
    "exam_name":{"type":"string"},
    "version_label":{"type":["string","null"]},
    "papers":{"type":"array","items":{
      "type":"object","additionalProperties":false,
      "required":["name","subjects"],
      "properties":{
        "name":{"type":"string"},
        "duration_minutes":{"type":["integer","null"]},
        "total_questions":{"type":["integer","null"]},
        "subjects":{"type":"array","items":{
          "type":"object","additionalProperties":false,
          "required":["name","units"],
          "properties":{
            "name":{"type":"string"},
            "weight":{"type":["number","null"],"minimum":0,"maximum":1},
            "units":{"type":"array","items":{
              "type":"object","additionalProperties":false,
              "required":["name","topics"],
              "properties":{
                "code":{"type":["string","null"]},
                "name":{"type":"string"},
                "topics":{"type":"array","items":{"$ref":"#/$defs/topic"}}
              }}}
          }}}
      }}}
  },
  "$defs":{
    "topic":{
      "type":"object","additionalProperties":false,
      "required":["name"],
      "properties":{
        "name":{"type":"string"},
        "name_native":{"type":["string","null"]},
        "children":{"type":"array","items":{"$ref":"#/$defs/topic"},"maxItems":50}
      }}
  }
}
```

### `translation.output`

```json
{
  "type":"object","additionalProperties":false,
  "required":["translations"],
  "properties":{
    "translations":{"type":"array","items":{
      "type":"object","additionalProperties":false,
      "required":["ref","question_text","options","explanation",
                  "preserved_placeholders","terminology_used","confidence"],
      "properties":{
        "ref":{"type":"string"},
        "question_text":{"type":"string","minLength":5},
        "options":{"type":"object","additionalProperties":false,
                   "required":["A","B","C","D"],
                   "properties":{"A":{"type":"string"},"B":{"type":"string"},
                                 "C":{"type":"string"},"D":{"type":"string"}}},
        "explanation":{"type":"string"},
        "preserved_placeholders":{"type":"array","items":{"type":"string"}},
        "terminology_used":{"type":"array","items":{
            "type":"object","required":["source","target"],
            "properties":{"source":{"type":"string"},"target":{"type":"string"}}}},
        "confidence":{"type":"number","minimum":0,"maximum":1}
      }}}
  }
}
```

### `prediction_report.payload` (internal, PDF render contract)

```json
{
  "type":"object","required":["exam","target_year","model_version","disclaimer","topics"],
  "properties":{
    "exam":{"type":"string"},
    "target_year":{"type":"integer"},
    "model_version":{"type":"string"},
    "backtest":{"type":"object",
      "properties":{"tested_year":{"type":"integer"},
                    "very_high_band_share":{"type":"number"}}},
    "disclaimer":{"type":"string","minLength":40},
    "topics":{"type":"array","items":{
      "type":"object",
      "required":["topic","score","band","years_appeared","priority"],
      "properties":{
        "topic":{"type":"string"},
        "topic_native":{"type":["string","null"]},
        "score":{"type":"number","minimum":0,"maximum":100},
        "band":{"type":"string","enum":["very_high","high","medium","low","very_low"]},
        "weighted_frequency":{"type":"number"},
        "years_appeared":{"type":"array","items":{"type":"integer"}},
        "syllabus_weight":{"type":"number"},
        "question_pattern":{"type":"string"},
        "priority":{"type":"string","enum":["HIGH","MEDIUM","LOW"]},
        "study_reference":{"type":["string","null"]},
        "components":{"type":"object"}
      }}}
  }
}
```

---

## 9.6 API design details that matter

**Cursor pagination** — `cursor` is a signed, opaque base64 of `{id, sort_key}`. Prevents
deep-page scans and makes bulk scraping detectable (a scraper must walk sequentially and
hits the rate limiter).

**Options hidden pre-attempt** — `GET /tests/{id}` returns question text and option *count*
but not option text until an attempt is created. Prevents harvesting full papers without
attempting.

**Idempotency** — `Idempotency-Key` is stored on `ai_jobs.idempotency_key` with a unique
index. A repeat POST within 24 h returns the original `202` payload instead of spending
tokens twice. This single index has saved more money than any caching layer in systems of
this shape.

**SSE for progress** — `/jobs/{id}/events` streams `progress`, `stage`, `message` events;
the Next.js job-tracker hook subscribes and falls back to 3-second polling when SSE is
unavailable (corporate proxies).

**Webhooks out** (Institute plan) — `POST` to a tenant URL on `job.completed`,
`document.ready`, `attempt.submitted`, HMAC-SHA256 signed with a per-tenant secret,
with exponential retry for 24 h and a delivery log.
