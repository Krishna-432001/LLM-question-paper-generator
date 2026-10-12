<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE subscription_plans (
                id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                code                text NOT NULL UNIQUE,
                name                text NOT NULL,
                description         text,
                price_inr_monthly   numeric(12,2) NOT NULL DEFAULT 0,
                price_inr_yearly    numeric(12,2),
                currency            char(3) NOT NULL DEFAULT 'INR',
                billing_provider_id text,
                features            jsonb NOT NULL DEFAULT '{}'::jsonb,
                is_public           boolean NOT NULL DEFAULT true,
                sort_order          int NOT NULL DEFAULT 0,
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now()
            );
            CREATE TRIGGER subscription_plans_touch BEFORE UPDATE ON subscription_plans
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

            -- Quotas are DATA. Changing a limit must never require a deploy.
            CREATE TABLE plan_quotas (
                id                uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                plan_id           uuid NOT NULL REFERENCES subscription_plans(id) ON DELETE CASCADE,
                metric            text NOT NULL,
                period            text NOT NULL DEFAULT 'month',
                limit_value       bigint NOT NULL,          -- -1 = unlimited
                overage_allowed   boolean NOT NULL DEFAULT false,
                overage_price_inr numeric(12,4),
                created_at        timestamptz NOT NULL DEFAULT now(),
                updated_at        timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT plan_quotas_period_check
                    CHECK (period IN ('day','month','year','lifetime')),
                CONSTRAINT plan_quotas_unique UNIQUE (plan_id, metric, period)
            );

            CREATE TABLE subscriptions (
                id                       uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                organization_id          uuid NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
                plan_id                  uuid NOT NULL REFERENCES subscription_plans(id),
                status                   text NOT NULL,
                provider                 text NOT NULL DEFAULT 'razorpay',
                provider_subscription_id text,
                seats                    int NOT NULL DEFAULT 1,
                current_period_start     timestamptz NOT NULL,
                current_period_end       timestamptz NOT NULL,
                cancel_at_period_end     boolean NOT NULL DEFAULT false,
                cancelled_at             timestamptz,
                created_at               timestamptz NOT NULL DEFAULT now(),
                updated_at               timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT subscriptions_status_check CHECK (status IN
                    ('trialing','active','past_due','cancelled','expired'))
            );
            CREATE UNIQUE INDEX subscriptions_one_active_per_org
                ON subscriptions (organization_id)
                WHERE status IN ('trialing','active','past_due');
            CREATE TRIGGER subscriptions_touch BEFORE UPDATE ON subscriptions
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

            CREATE TABLE payments (
                id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                organization_id     uuid NOT NULL REFERENCES organizations(id),
                subscription_id     uuid REFERENCES subscriptions(id),
                provider            text NOT NULL,
                provider_payment_id text UNIQUE,
                provider_order_id   text,
                provider_event_id   text UNIQUE,        -- webhook replay protection
                amount_inr          numeric(12,2) NOT NULL,
                tax_inr             numeric(12,2) NOT NULL DEFAULT 0,
                status              text NOT NULL,
                method              text,
                invoice_number      text UNIQUE,
                invoice_url         text,
                raw_payload         jsonb,
                paid_at             timestamptz,
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX payments_org_idx ON payments (organization_id, created_at DESC);

            -- Metering. Partitioned monthly: this table grows with every AI call
            -- and every download, and must stay cheap to age out.
            CREATE TABLE usage_records (
                id              uuid NOT NULL DEFAULT uuid_generate_v7(),
                organization_id uuid NOT NULL,
                user_id         uuid,
                metric          text NOT NULL,
                quantity        bigint NOT NULL,
                cost_inr        numeric(12,4) NOT NULL DEFAULT 0,
                ai_job_id       uuid,
                reference_type  text,
                reference_id    uuid,
                occurred_at     timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at);

            CREATE INDEX usage_records_org_metric_idx
                ON usage_records (organization_id, metric, occurred_at DESC);
        SQL);

        // Rolling partitions: previous month .. +3 months. PartitionManager
        // (scheduled monthly) keeps the window open from here on.
        \App\Support\Database\PartitionManager::ensureMonthlyRange('usage_records', -1, 3);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS usage_records CASCADE;
            DROP TABLE IF EXISTS payments;
            DROP TABLE IF EXISTS subscriptions;
            DROP TABLE IF EXISTS plan_quotas;
            DROP TABLE IF EXISTS subscription_plans CASCADE;
        SQL);
    }
};
