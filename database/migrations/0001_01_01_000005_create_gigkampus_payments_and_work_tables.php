<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE payments (
                id BIGSERIAL PRIMARY KEY,
                order_id BIGINT NOT NULL UNIQUE,
                amount NUMERIC(12,0) NOT NULL,
                payment_method VARCHAR(16) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                verified_proof_id BIGINT,
                verified_by_user_id BIGINT REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                verified_by_admin_id BIGINT REFERENCES admins (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                verified_at TIMESTAMPTZ,
                refund_status VARCHAR(16) NOT NULL DEFAULT 'none',
                refund_requested_at TIMESTAMPTZ,
                refund_confirmed_by_user_id BIGINT REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                refund_confirmed_by_admin_id BIGINT REFERENCES admins (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                refund_confirmed_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT payments_order_amount_fk FOREIGN KEY (order_id, amount)
                    REFERENCES orders (id, total_price) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT payments_amount_check CHECK (amount BETWEEN 1 AND 100000000),
                CONSTRAINT payments_method_check CHECK (payment_method IN ('bank_transfer', 'qris')),
                CONSTRAINT payments_status_check CHECK (status IN ('pending', 'verified', 'rejected')),
                CONSTRAINT payments_verification_check CHECK (
                    (status = 'verified' AND verified_proof_id IS NOT NULL AND verified_at IS NOT NULL
                        AND num_nonnulls(verified_by_user_id, verified_by_admin_id) = 1)
                    OR (status IN ('pending', 'rejected')
                        AND num_nonnulls(verified_proof_id, verified_by_user_id, verified_by_admin_id, verified_at) = 0)
                ),
                CONSTRAINT payments_refund_status_check CHECK (refund_status IN ('none', 'pending', 'confirmed')),
                CONSTRAINT payments_refund_request_check CHECK (
                    (refund_status = 'none' AND refund_requested_at IS NULL)
                    OR (refund_status IN ('pending', 'confirmed') AND status = 'verified' AND refund_requested_at IS NOT NULL)
                ),
                CONSTRAINT payments_refund_confirmation_check CHECK (
                    (refund_status = 'confirmed' AND refund_confirmed_at IS NOT NULL
                        AND num_nonnulls(refund_confirmed_by_user_id, refund_confirmed_by_admin_id) = 1)
                    OR (refund_status IN ('none', 'pending')
                        AND num_nonnulls(refund_confirmed_by_user_id, refund_confirmed_by_admin_id, refund_confirmed_at) = 0)
                )
            );

            CREATE INDEX payments_verified_proof_id_index ON payments (verified_proof_id);
            CREATE INDEX payments_verified_by_user_id_index ON payments (verified_by_user_id);
            CREATE INDEX payments_verified_by_admin_id_index ON payments (verified_by_admin_id);
            CREATE INDEX payments_refund_status_index ON payments (refund_status);
            CREATE INDEX payments_refund_confirmed_by_user_id_index ON payments (refund_confirmed_by_user_id);
            CREATE INDEX payments_refund_confirmed_by_admin_id_index ON payments (refund_confirmed_by_admin_id);

            CREATE TABLE payment_proofs (
                id BIGSERIAL PRIMARY KEY,
                payment_id BIGINT NOT NULL REFERENCES payments (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                attempt_no INTEGER NOT NULL,
                file_path TEXT NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                file_size INTEGER NOT NULL,
                seller_review_status VARCHAR(16) NOT NULL DEFAULT 'pending',
                reviewed_by_seller_id BIGINT REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                rejection_reason TEXT,
                submitted_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at TIMESTAMPTZ,

                CONSTRAINT payment_proofs_payment_attempt_unique UNIQUE (payment_id, attempt_no),
                CONSTRAINT payment_proofs_id_payment_unique UNIQUE (id, payment_id),
                CONSTRAINT payment_proofs_attempt_check CHECK (attempt_no >= 1),
                CONSTRAINT payment_proofs_file_check CHECK (
                    btrim(file_path, E' \t\n\r') <> '' AND btrim(original_filename, E' \t\n\r') <> ''
                    AND mime_type IN ('image/jpeg', 'image/png') AND file_size BETWEEN 1 AND 5242880
                ),
                CONSTRAINT payment_proofs_status_check CHECK (seller_review_status IN ('pending', 'verified', 'rejected')),
                CONSTRAINT payment_proofs_review_check CHECK (
                    (seller_review_status = 'pending' AND num_nonnulls(reviewed_by_seller_id, reviewed_at) = 0)
                    OR (seller_review_status IN ('verified', 'rejected') AND num_nonnulls(reviewed_by_seller_id, reviewed_at) = 2)
                ),
                CONSTRAINT payment_proofs_rejection_check CHECK (
                    (seller_review_status = 'rejected' AND rejection_reason IS NOT NULL
                        AND char_length(btrim(rejection_reason, E' \t\n\r')) BETWEEN 5 AND 1000)
                    OR (seller_review_status IN ('pending', 'verified') AND rejection_reason IS NULL)
                )
            );

            CREATE UNIQUE INDEX payment_proofs_pending_payment_unique ON payment_proofs (payment_id)
                WHERE seller_review_status = 'pending';
            CREATE INDEX payment_proofs_reviewed_by_seller_id_index ON payment_proofs (reviewed_by_seller_id);

            ALTER TABLE payments ADD CONSTRAINT payments_verified_proof_fk
                FOREIGN KEY (verified_proof_id, id) REFERENCES payment_proofs (id, payment_id)
                ON DELETE RESTRICT ON UPDATE RESTRICT;

            CREATE TABLE revisions (
                id BIGSERIAL PRIMARY KEY,
                order_id BIGINT NOT NULL REFERENCES orders (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                revision_no SMALLINT NOT NULL,
                revision_note TEXT NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'requested',
                requested_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMPTZ,

                CONSTRAINT revisions_order_number_unique UNIQUE (order_id, revision_no),
                CONSTRAINT revisions_id_order_unique UNIQUE (id, order_id),
                CONSTRAINT revisions_number_check CHECK (revision_no BETWEEN 1 AND 20),
                CONSTRAINT revisions_note_check CHECK (char_length(btrim(revision_note, E' \t\n\r')) BETWEEN 20 AND 5000),
                CONSTRAINT revisions_status_check CHECK (status IN ('requested', 'in_progress', 'completed')),
                CONSTRAINT revisions_completion_check CHECK ((status = 'completed') = (completed_at IS NOT NULL))
            );

            CREATE UNIQUE INDEX revisions_active_order_unique ON revisions (order_id)
                WHERE status IN ('requested', 'in_progress');

            CREATE OR REPLACE FUNCTION gigkampus_valid_attachment(file_path TEXT, original_filename TEXT, mime_type TEXT, file_size INTEGER)
                RETURNS BOOLEAN LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
                SELECT num_nonnulls(file_path, original_filename, mime_type, file_size) = 0
                    OR (num_nonnulls(file_path, original_filename, mime_type, file_size) = 4
                        AND btrim(file_path, E' \t\n\r') <> '' AND btrim(original_filename, E' \t\n\r') <> ''
                        AND mime_type IN (
                            'application/pdf',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                            'text/plain', 'text/csv', 'application/csv', 'text/x-csv',
                            'image/jpeg', 'image/png', 'application/zip', 'application/x-zip-compressed'
                        )
                        AND file_size BETWEEN 1 AND 20971520);
            $$;

            CREATE TABLE order_deliveries (
                id BIGSERIAL PRIMARY KEY,
                order_id BIGINT NOT NULL REFERENCES orders (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                revision_id BIGINT UNIQUE,
                file_path TEXT,
                original_filename VARCHAR(255),
                mime_type VARCHAR(100),
                file_size INTEGER,
                result_url VARCHAR(2048),
                delivery_note TEXT,
                submitted_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT order_deliveries_revision_order_fk FOREIGN KEY (revision_id, order_id)
                    REFERENCES revisions (id, order_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT order_deliveries_result_check CHECK (num_nonnulls(file_path, result_url) = 1),
                CONSTRAINT order_deliveries_file_check CHECK (
                    gigkampus_valid_attachment(file_path, original_filename, mime_type, file_size)
                ),
                CONSTRAINT order_deliveries_url_check CHECK (result_url ~* '^https://[^/?#[:space:]]+([/?#][^[:space:]]*)?$'),
                CONSTRAINT order_deliveries_note_check CHECK (
                    char_length(delivery_note) <= 2000 AND btrim(delivery_note, E' \t\n\r') <> ''
                )
            );

            CREATE UNIQUE INDEX order_deliveries_first_order_unique ON order_deliveries (order_id)
                WHERE revision_id IS NULL;
            CREATE INDEX order_deliveries_order_submitted_at_index ON order_deliveries (order_id, submitted_at);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE payments DROP CONSTRAINT payments_verified_proof_fk;
            DROP TABLE order_deliveries, revisions, payment_proofs, payments;
            DROP FUNCTION gigkampus_valid_attachment(TEXT, TEXT, TEXT, INTEGER);
            SQL);
    }
};
