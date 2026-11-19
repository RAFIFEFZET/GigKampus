<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE gigs (
                id BIGSERIAL PRIMARY KEY,
                seller_id BIGINT NOT NULL REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                category_id BIGINT NOT NULL REFERENCES categories (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                title VARCHAR(150) NOT NULL,
                description TEXT NOT NULL,
                base_price NUMERIC(12,0) NOT NULL,
                delivery_days SMALLINT NOT NULL,
                max_revisions SMALLINT NOT NULL,
                portfolio_path TEXT,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                version INTEGER NOT NULL DEFAULT 1,
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT gigs_title_check CHECK (char_length(btrim(title, E' \t\n\r')) BETWEEN 5 AND 150),
                CONSTRAINT gigs_description_check CHECK (char_length(btrim(description, E' \t\n\r')) BETWEEN 20 AND 5000),
                CONSTRAINT gigs_base_price_check CHECK (base_price BETWEEN 1 AND 100000000),
                CONSTRAINT gigs_delivery_days_check CHECK (delivery_days BETWEEN 1 AND 365),
                CONSTRAINT gigs_max_revisions_check CHECK (max_revisions BETWEEN 0 AND 20),
                CONSTRAINT gigs_portfolio_path_check CHECK (btrim(portfolio_path, E' \t\n\r') <> ''),
                CONSTRAINT gigs_status_check CHECK (status IN ('active', 'inactive', 'suspended')),
                CONSTRAINT gigs_version_check CHECK (version >= 1)
            );

            CREATE INDEX gigs_seller_id_index ON gigs (seller_id);
            CREATE INDEX gigs_category_id_index ON gigs (category_id);
            CREATE INDEX gigs_created_at_index ON gigs (created_at);

            CREATE TABLE service_requests (
                id BIGSERIAL PRIMARY KEY,
                buyer_id BIGINT NOT NULL REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                category_id BIGINT NOT NULL REFERENCES categories (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                title VARCHAR(150) NOT NULL,
                description TEXT NOT NULL,
                budget_min NUMERIC(12,0) NOT NULL,
                budget_max NUMERIC(12,0) NOT NULL,
                deadline_date DATE NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'open',
                is_hidden BOOLEAN NOT NULL DEFAULT false,
                version INTEGER NOT NULL DEFAULT 1,
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT service_requests_title_check CHECK (char_length(btrim(title, E' \t\n\r')) BETWEEN 5 AND 150),
                CONSTRAINT service_requests_description_check CHECK (
                    char_length(btrim(description, E' \t\n\r')) BETWEEN 20 AND 5000
                ),
                CONSTRAINT service_requests_budgets_check CHECK (
                    budget_min BETWEEN 0 AND 100000000
                    AND budget_max BETWEEN 1 AND 100000000
                    AND budget_min <= budget_max
                ),
                CONSTRAINT service_requests_status_check CHECK (status IN ('open', 'closed', 'ordered')),
                CONSTRAINT service_requests_version_check CHECK (version >= 1)
            );

            CREATE INDEX service_requests_buyer_id_index ON service_requests (buyer_id);
            CREATE INDEX service_requests_category_id_index ON service_requests (category_id);
            CREATE INDEX service_requests_deadline_date_index ON service_requests (deadline_date);
            CREATE INDEX service_requests_created_at_index ON service_requests (created_at);

            CREATE TABLE price_offers (
                id BIGSERIAL PRIMARY KEY,
                service_request_id BIGINT NOT NULL REFERENCES service_requests (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                seller_id BIGINT NOT NULL REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                offered_price NUMERIC(12,0) NOT NULL,
                max_revisions SMALLINT NOT NULL,
                proposal_note TEXT NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                status_reason VARCHAR(255),
                version INTEGER NOT NULL DEFAULT 1,
                offered_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT price_offers_request_seller_unique UNIQUE (service_request_id, seller_id),
                CONSTRAINT price_offers_offered_price_check CHECK (offered_price BETWEEN 1 AND 100000000),
                CONSTRAINT price_offers_max_revisions_check CHECK (max_revisions BETWEEN 0 AND 20),
                CONSTRAINT price_offers_proposal_note_check CHECK (
                    char_length(btrim(proposal_note, E' \t\n\r')) BETWEEN 20 AND 3000
                ),
                CONSTRAINT price_offers_status_check CHECK (status IN ('pending', 'accepted', 'rejected', 'withdrawn')),
                CONSTRAINT price_offers_status_reason_check CHECK (
                    (status IN ('rejected', 'withdrawn') AND status_reason IS NOT NULL
                        AND btrim(status_reason, E' \t\n\r') <> '')
                    OR (status IN ('pending', 'accepted') AND status_reason IS NULL)
                ),
                CONSTRAINT price_offers_version_check CHECK (version >= 1)
            );

            CREATE UNIQUE INDEX price_offers_accepted_request_unique ON price_offers (service_request_id)
                WHERE status = 'accepted';
            CREATE INDEX price_offers_seller_id_index ON price_offers (seller_id);

            CREATE TABLE orders (
                id BIGSERIAL PRIMARY KEY,
                creation_key UUID NOT NULL UNIQUE,
                buyer_id BIGINT NOT NULL REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                seller_id BIGINT NOT NULL REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                transaction_type VARCHAR(16) NOT NULL,
                gig_id BIGINT REFERENCES gigs (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                price_offer_id BIGINT UNIQUE REFERENCES price_offers (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                title_snapshot VARCHAR(150) NOT NULL,
                scope_description_snapshot TEXT NOT NULL,
                buyer_brief TEXT,
                seller_proposal_snapshot TEXT,
                category_name_snapshot VARCHAR(100) NOT NULL,
                total_price NUMERIC(12,0) NOT NULL,
                max_revisions_snapshot SMALLINT NOT NULL,
                delivery_days_snapshot SMALLINT,
                delivery_deadline_at TIMESTAMPTZ,
                status VARCHAR(32) NOT NULL DEFAULT 'awaiting_payment',
                stage_version INTEGER NOT NULL DEFAULT 1,
                bank_name_snapshot VARCHAR(100),
                bank_account_number_snapshot VARCHAR(50),
                bank_account_holder_name_snapshot VARCHAR(100),
                qris_image_path_snapshot TEXT,
                qris_holder_name_snapshot VARCHAR(100),
                cancellation_status VARCHAR(16) NOT NULL DEFAULT 'none',
                cancellation_requested_by_id BIGINT REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                cancellation_reason TEXT,
                cancellation_requested_at TIMESTAMPTZ,
                cancellation_version INTEGER NOT NULL DEFAULT 0,
                cancellation_response_reason TEXT,
                cancellation_closed_at TIMESTAMPTZ,
                order_date TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT orders_id_total_price_unique UNIQUE (id, total_price),
                CONSTRAINT orders_participants_check CHECK (buyer_id <> seller_id),
                CONSTRAINT orders_transaction_type_check CHECK (transaction_type IN ('direct_gig', 'custom_request')),
                CONSTRAINT orders_source_check CHECK (
                    (transaction_type = 'direct_gig' AND gig_id IS NOT NULL AND price_offer_id IS NULL)
                    OR (transaction_type = 'custom_request' AND gig_id IS NULL AND price_offer_id IS NOT NULL)
                ),
                CONSTRAINT orders_buyer_brief_check CHECK (
                    (transaction_type = 'direct_gig' AND buyer_brief IS NOT NULL
                        AND buyer_brief = btrim(buyer_brief, E' \t\n\r')
                        AND char_length(buyer_brief) BETWEEN 20 AND 5000)
                    OR (transaction_type = 'custom_request' AND buyer_brief IS NULL)
                ),
                CONSTRAINT orders_title_snapshot_check CHECK (
                    char_length(btrim(title_snapshot, E' \t\n\r')) BETWEEN 5 AND 150
                ),
                CONSTRAINT orders_scope_description_snapshot_check CHECK (
                    char_length(btrim(scope_description_snapshot, E' \t\n\r')) BETWEEN 20 AND 5000
                ),
                CONSTRAINT orders_seller_proposal_snapshot_check CHECK (
                    (transaction_type = 'direct_gig' AND seller_proposal_snapshot IS NULL)
                    OR (transaction_type = 'custom_request' AND seller_proposal_snapshot IS NOT NULL
                        AND char_length(btrim(seller_proposal_snapshot, E' \t\n\r')) BETWEEN 20 AND 3000)
                ),
                CONSTRAINT orders_category_name_snapshot_check CHECK (
                    char_length(btrim(category_name_snapshot, E' \t\n\r')) BETWEEN 2 AND 100
                ),
                CONSTRAINT orders_total_price_check CHECK (total_price BETWEEN 1 AND 100000000),
                CONSTRAINT orders_max_revisions_snapshot_check CHECK (max_revisions_snapshot BETWEEN 0 AND 20),
                CONSTRAINT orders_delivery_snapshot_check CHECK (
                    (transaction_type = 'direct_gig' AND delivery_days_snapshot IS NOT NULL
                        AND delivery_days_snapshot BETWEEN 1 AND 365)
                    OR (transaction_type = 'custom_request' AND delivery_days_snapshot IS NULL
                        AND delivery_deadline_at IS NOT NULL)
                ),
                CONSTRAINT orders_status_check CHECK (status IN (
                    'awaiting_payment', 'awaiting_validation', 'in_progress', 'delivered',
                    'revision', 'completed', 'cancelled', 'disputed'
                )),
                CONSTRAINT orders_stage_version_check CHECK (stage_version >= 1),
                CONSTRAINT orders_bank_snapshot_check CHECK (
                    num_nonnulls(bank_name_snapshot, bank_account_number_snapshot, bank_account_holder_name_snapshot) IN (0, 3)
                    AND (char_length(btrim(bank_name_snapshot, E' \t\n\r')) BETWEEN 2 AND 100) IS NOT FALSE
                    AND (bank_account_number_snapshot ~ '^[0-9]{1,50}$') IS NOT FALSE
                    AND (btrim(bank_account_holder_name_snapshot, E' \t\n\r') <> '') IS NOT FALSE
                ),
                CONSTRAINT orders_qris_snapshot_check CHECK (
                    num_nonnulls(qris_image_path_snapshot, qris_holder_name_snapshot) IN (0, 2)
                    AND (btrim(qris_image_path_snapshot, E' \t\n\r') <> '') IS NOT FALSE
                    AND (btrim(qris_holder_name_snapshot, E' \t\n\r') <> '') IS NOT FALSE
                ),
                CONSTRAINT orders_payment_destination_check CHECK (
                    bank_name_snapshot IS NOT NULL OR qris_image_path_snapshot IS NOT NULL
                ),
                CONSTRAINT orders_cancellation_status_check CHECK (
                    cancellation_status IN ('none', 'pending', 'approved', 'rejected', 'withdrawn', 'voided')
                ),
                CONSTRAINT orders_cancellation_request_check CHECK (
                    (cancellation_status = 'none' AND cancellation_version = 0
                        AND num_nonnulls(cancellation_requested_by_id, cancellation_reason, cancellation_requested_at) = 0)
                    OR (cancellation_status <> 'none' AND cancellation_version >= 1
                        AND num_nonnulls(cancellation_requested_by_id, cancellation_reason, cancellation_requested_at) = 3
                        AND cancellation_requested_by_id IN (buyer_id, seller_id)
                        AND char_length(btrim(cancellation_reason, E' \t\n\r')) BETWEEN 5 AND 1000)
                ),
                CONSTRAINT orders_cancellation_closed_check CHECK (
                    (cancellation_closed_at IS NOT NULL) = (cancellation_status IN ('approved', 'rejected', 'withdrawn', 'voided'))
                ),
                CONSTRAINT orders_cancellation_response_check CHECK (
                    (cancellation_status IN ('rejected', 'voided') AND cancellation_response_reason IS NOT NULL
                        AND char_length(btrim(cancellation_response_reason, E' \t\n\r')) BETWEEN 5 AND 1000)
                    OR (cancellation_status NOT IN ('rejected', 'voided') AND cancellation_response_reason IS NULL)
                )
            );

            CREATE INDEX orders_buyer_status_date_index ON orders (buyer_id, status, order_date);
            CREATE INDEX orders_seller_status_date_index ON orders (seller_id, status, order_date);
            CREATE INDEX orders_gig_id_index ON orders (gig_id);
            CREATE INDEX orders_cancellation_requester_index ON orders (cancellation_requested_by_id);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE orders, price_offers, service_requests, gigs');
    }
};
