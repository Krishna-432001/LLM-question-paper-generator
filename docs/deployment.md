# Deployment

Written against what the system actually needs, not what a generic Laravel
deployment guide would list. Where something is not yet installed anywhere, it
says so rather than pretending.

## What is running today

Development runs directly on the host: PHP 8.2 (XAMPP), Node 22, PostgreSQL 18.
**Docker, Redis and pgvector are not installed on the development machine**, so
the queue runs on the `database` driver and vector search uses a `real[]`
brute-force fallback.

That is fine for a few hundred questions and will not hold beyond a few
thousand. Both are addressed below.

## Prerequisites

| | Version | Notes |
|---|---|---|
| PHP | 8.3+ | Development is on 8.2.12; nothing yet requires 8.3 |
| PostgreSQL | 16+ | With `pgvector` — see below |
| Node | 20+ | Build only; the built frontend is static |
| poppler-utils | any | `pdftotext` — **required**, PDF reading fails without it |
| tesseract | 5+ | Only for scanned books with no text layer |

`pdftotext` is not optional. Every book and every past paper is read through it.

## Database

```sql
CREATE DATABASE examforge;
CREATE ROLE examforge LOGIN PASSWORD '…';
GRANT ALL ON DATABASE examforge TO examforge;

-- Requires superuser. Do this before migrating: the fallback is chosen at
-- runtime by checking for the extension, so installing it later means
-- re-embedding everything already ingested.
CREATE EXTENSION IF NOT EXISTS vector;
```

Then:

```bash
php artisan migrate --force
php artisan db:seed --force
```

Seeding creates languages, roles, plans, AI models, prompts, the TNPSC Group IV
syllabus, and the blueprint that turns the mark distribution into unit weights
and generation targets.

### Row-level security

Every tenant-scoped table has an RLS policy keyed on `app.org_id`, set per
request. The application role must **not** be `BYPASSRLS`, or the third
isolation layer silently does nothing.

`audit_logs` additionally refuses `UPDATE` and `DELETE` via trigger. Do not
grant the deploying role the ability to drop it.

## Queue

Every ingestion and generation job is queued. **Nothing works without a running
worker** — uploads sit at `uploaded` forever and it looks like a broken upload
rather than a missing worker.

```bash
php artisan queue:work \
  --queue=critical,ingest,ocr,structure,embed,generate,validate,translate,pdf,plan,predict,maintenance \
  --tries=2 --timeout=900
```

Two things learned the hard way:

- **Restart the worker after every deploy.** It loads classes once at boot, so
  new code is not picked up. A worker on stale code fails in ways that look
  like data problems.
- Run it under a supervisor. It does not survive a terminal closing, and a
  dead worker is invisible from the UI.

Moving to Redis is a config change (`QUEUE_CONNECTION=redis`) plus Horizon for
monitoring. The database driver is adequate for one operator and one book at a
time; it is not adequate for concurrent ingestion.

## Environment

```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=            # php artisan key:generate

DB_CONNECTION=pgsql
DB_HOST=…
DB_DATABASE=examforge
DB_USERNAME=examforge
DB_PASSWORD=…

QUEUE_CONNECTION=database        # redis once available

AI_DRIVER=openai                 # fake | openai | claude
OPENAI_API_KEY=
OPENAI_MODEL=gpt-4o
AI_DAILY_BUDGET_INR=2000

PDF_DRIVER=mpdf
OCR_DRIVER=tesseract             # fake when no binary is installed
EMBEDDING_DRIVER=http            # fake is hash-based and not semantic
```

`AI_DAILY_BUDGET_INR` is a hard circuit breaker checked before every model
call, not a report. Set it deliberately.

**The test environment refuses any driver but `fake`.** A test that reached a
real provider would spend money on every CI run, so this is enforced in
`AiServiceProvider::boot()` rather than left to discipline.

## Frontend

```bash
cd apps/web
npm ci
npm run build
```

Set `NEXT_PUBLIC_API_URL` to the API's public origin **before** building — it is
inlined at build time, so changing it later requires a rebuild.

Add the frontend origin to `config/cors.php`. The API is stateless and
`supports_credentials` is false; leave it that way.

## Storage

Uploaded books and generated documents go to the `local` disk by default, under
`storage/app`. For production use S3 or R2 and set `PDF_DISK` accordingly.

Documents are content-addressed and reused: the same test rendered twice returns
the stored bytes rather than re-rendering. Do not clear that directory as a
cache — it is the only copy.

## Fonts

Tamil papers render through mPDF using **FreeSerif**, which ships with mPDF and
covers both Latin and Tamil. Do not swap in a system font without checking:
Windows ships Nirmala only as a `.ttc`, and both it and current Noto Sans Tamil
builds contain `MarkGlyphSets`, which mPDF refuses outright — the renderer then
either throws or silently drops every Tamil glyph.

## After deploying

```bash
php artisan migrate --force
php artisan config:cache && php artisan route:cache
# restart the queue worker — it will not pick up new code otherwise
```

Then check `GET /api/v1/dashboard`: it reports the driver in use, jobs failed,
and pages needing review. If `driver` reads `fake` in production, generation is
producing placeholder text.

## Not yet done

- **Docker.** No `docker-compose.yml` exists. The stack runs directly on a host.
- **Horizon.** Needs Redis first.
- **pgvector.** Not installed anywhere yet; the fallback is in use.
- **Backups.** Nothing is configured. `audit_logs` and `questions` are the two
  that cannot be regenerated.
