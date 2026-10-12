-- ExamForge — local PostgreSQL bootstrap
--
-- Run this ONCE as the postgres superuser, then fill DB_PASSWORD in apps/api/.env
-- with whatever you set below.
--
--   psql -U postgres -h 127.0.0.1 -f apps/api/database/setup.sql
--
-- Or paste it into pgAdmin's Query Tool connected as postgres.

-- 1. Application role.
--    In production this role is NOT the table owner, so RLS applies to it
--    unconditionally. Locally it owns the schema for convenience; RLS is
--    still enforced because every policy uses FORCE ROW LEVEL SECURITY.
CREATE ROLE examforge WITH LOGIN PASSWORD 'change-this-password' CREATEDB;

-- 2. Databases.
CREATE DATABASE examforge      OWNER examforge ENCODING 'UTF8';
CREATE DATABASE examforge_test OWNER examforge ENCODING 'UTF8';

-- 3. Extensions must be created by a superuser, so do it here rather than in
--    a migration. The migration only calls CREATE EXTENSION IF NOT EXISTS.
\connect examforge
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gin;
CREATE EXTENSION IF NOT EXISTS ltree;
CREATE EXTENSION IF NOT EXISTS citext;
GRANT ALL ON SCHEMA public TO examforge;

\connect examforge_test
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gin;
CREATE EXTENSION IF NOT EXISTS ltree;
CREATE EXTENSION IF NOT EXISTS citext;
GRANT ALL ON SCHEMA public TO examforge;

-- 4. pgvector is NOT required until Phase 2 (embeddings). When you get there:
--    Windows: download the pgvector build matching PostgreSQL 18 and copy
--      vector.dll        -> C:\Program Files\PostgreSQL\18\lib\
--      vector*.sql,      -> C:\Program Files\PostgreSQL\18\share\extension\
--      vector.control    -> C:\Program Files\PostgreSQL\18\share\extension\
--    then run:  CREATE EXTENSION vector;
--    The Phase 1 migrations deliberately do not depend on it.
