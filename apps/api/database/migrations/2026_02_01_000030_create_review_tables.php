<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Human review of AI-generated content.
 *
 * The review queue is the platform's real throughput constraint: after Phase 2
 * the engineering is done and the limit is how fast reviewed questions enter
 * the bank. Everything here exists to make that rate measurable and fast.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS reviews (
                id                 uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                organization_id    uuid REFERENCES organizations(id) ON DELETE CASCADE,
                reviewable_type    text NOT NULL,
                reviewable_id      uuid NOT NULL,
                assigned_to        uuid REFERENCES users(id) ON DELETE SET NULL,
                assigned_at        timestamptz,
                -- Claims expire so a reviewer who closes their laptop does not
                -- park an item in the queue indefinitely.
                claim_expires_at   timestamptz,
                priority           int NOT NULL DEFAULT 5,
                status             text NOT NULL DEFAULT 'pending',
                decision           text,
                decision_reason    text,
                -- Recorded so "reviewer agreed with the machine" can be
                -- measured. That rate is the gate for enabling auto-approval.
                machine_status     text,
                was_edited         boolean NOT NULL DEFAULT false,
                time_spent_seconds int,
                reviewed_by        uuid REFERENCES users(id) ON DELETE SET NULL,
                reviewed_at        timestamptz,
                created_at         timestamptz NOT NULL DEFAULT now(),
                updated_at         timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT reviews_status_check CHECK (status IN
                    ('pending','in_review','approved','rejected','revision_required')),
                CONSTRAINT reviews_reviewable_unique UNIQUE (reviewable_type, reviewable_id)
            );

            -- The queue query: unclaimed work, highest priority first.
            CREATE INDEX IF NOT EXISTS reviews_queue
                ON reviews (status, priority DESC, created_at)
                WHERE status IN ('pending','in_review');
            CREATE INDEX IF NOT EXISTS reviews_assignee ON reviews (assigned_to, status);

            CREATE TRIGGER reviews_touch BEFORE UPDATE ON reviews
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

            CREATE TABLE IF NOT EXISTS review_comments (
                id         uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                review_id  uuid NOT NULL REFERENCES reviews(id) ON DELETE CASCADE,
                user_id    uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                body       text NOT NULL,
                -- Anchors a comment to a field, e.g. "options.B", so feedback
                -- points at the defect rather than at the whole question.
                field_path text,
                created_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS review_comments_review ON review_comments (review_id);

            ALTER TABLE reviews ENABLE ROW LEVEL SECURITY;
            ALTER TABLE reviews FORCE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS reviews_tenant ON reviews;
            CREATE POLICY reviews_tenant ON reviews
            USING (
                rls_bypass() OR is_platform_admin()
                OR organization_id IS NULL
                OR organization_id = current_org_id()
            )
            WITH CHECK (
                rls_bypass() OR is_platform_admin()
                OR organization_id IS NULL
                OR organization_id = current_org_id()
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS review_comments;
            DROP TABLE IF EXISTS reviews CASCADE;
        SQL);
    }
};
