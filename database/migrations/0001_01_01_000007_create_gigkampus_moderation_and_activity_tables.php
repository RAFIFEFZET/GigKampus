<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE reports (
                id BIGSERIAL PRIMARY KEY,
                reporter_id BIGINT NOT NULL REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                report_type VARCHAR(32) NOT NULL,
                gig_id BIGINT REFERENCES gigs (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                service_request_id BIGINT REFERENCES service_requests (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                review_id BIGINT REFERENCES reviews (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                chat_room_id BIGINT REFERENCES chat_rooms (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                order_id BIGINT REFERENCES orders (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                category VARCHAR(32) NOT NULL,
                description TEXT NOT NULL,
                file_path TEXT,
                original_filename VARCHAR(255),
                mime_type VARCHAR(100),
                file_size INTEGER,
                evidence_url VARCHAR(2048),
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                handled_by_admin_id BIGINT REFERENCES admins (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                order_status_before_dispute VARCHAR(32),
                order_stage_version INTEGER,
                decision_code VARCHAR(64),
                decision_note TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                decided_at TIMESTAMPTZ,

                CONSTRAINT reports_type_check CHECK (report_type IN (
                    'gig_report', 'request_report', 'review_report', 'chat_report', 'order_complaint'
                )),
                CONSTRAINT reports_target_check CHECK (
                    num_nonnulls(gig_id, service_request_id, review_id, chat_room_id, order_id) = 1
                    AND (CASE report_type
                        WHEN 'gig_report' THEN gig_id
                        WHEN 'request_report' THEN service_request_id
                        WHEN 'review_report' THEN review_id
                        WHEN 'chat_report' THEN chat_room_id
                        WHEN 'order_complaint' THEN order_id
                    END) IS NOT NULL
                ),
                CONSTRAINT reports_category_check CHECK (category IN (
                    'academic_integrity', 'illegal_dangerous', 'copyright_privacy', 'misleading_spam',
                    'transaction_issue', 'harassment_sara', 'identity_feature_abuse'
                )),
                CONSTRAINT reports_description_check CHECK (char_length(btrim(description, E' \t\n\r')) BETWEEN 20 AND 5000),
                CONSTRAINT reports_evidence_check CHECK (num_nonnulls(file_path, evidence_url) <= 1),
                CONSTRAINT reports_file_check CHECK (gigkampus_valid_attachment(file_path, original_filename, mime_type, file_size)),
                CONSTRAINT reports_url_check CHECK (evidence_url ~* '^https://[^/?#[:space:]]+([/?#][^[:space:]]*)?$'),
                CONSTRAINT reports_status_check CHECK (status IN ('pending', 'resolved', 'dismissed')),
                CONSTRAINT reports_previous_order_status_check CHECK (order_status_before_dispute IN (
                    'awaiting_payment', 'awaiting_validation', 'in_progress', 'delivered', 'revision', 'cancelled'
                )),
                CONSTRAINT reports_order_snapshot_check CHECK (
                    (report_type = 'order_complaint' AND order_status_before_dispute IS NOT NULL
                        AND order_stage_version IS NOT NULL AND order_stage_version >= 1)
                    OR (report_type <> 'order_complaint' AND num_nonnulls(order_status_before_dispute, order_stage_version) = 0)
                ),
                CONSTRAINT reports_decision_check CHECK (
                    (status = 'pending' AND num_nonnulls(decision_code, decision_note, decided_at) = 0)
                    OR (status IN ('resolved', 'dismissed')
                        AND num_nonnulls(handled_by_admin_id, decision_code, decision_note, decided_at) = 4
                        AND btrim(decision_code, E' \t\n\r') <> ''
                        AND char_length(btrim(decision_note, E' \t\n\r')) BETWEEN 5 AND 5000)
                )
            );

            CREATE UNIQUE INDEX reports_pending_order_unique ON reports (order_id)
                WHERE order_id IS NOT NULL AND status = 'pending';
            CREATE UNIQUE INDEX reports_pending_gig_unique ON reports (reporter_id, gig_id)
                WHERE gig_id IS NOT NULL AND status = 'pending';
            CREATE UNIQUE INDEX reports_pending_request_unique ON reports (reporter_id, service_request_id)
                WHERE service_request_id IS NOT NULL AND status = 'pending';
            CREATE UNIQUE INDEX reports_pending_review_unique ON reports (reporter_id, review_id)
                WHERE review_id IS NOT NULL AND status = 'pending';
            CREATE UNIQUE INDEX reports_pending_room_unique ON reports (reporter_id, chat_room_id)
                WHERE chat_room_id IS NOT NULL AND status = 'pending';

            CREATE INDEX reports_reporter_created_at_index ON reports (reporter_id, created_at);
            CREATE INDEX reports_handler_status_created_at_index ON reports (handled_by_admin_id, status, created_at);
            CREATE INDEX reports_order_stage_reporter_index ON reports (order_id, order_stage_version, reporter_id);
            CREATE INDEX reports_gig_id_index ON reports (gig_id);
            CREATE INDEX reports_service_request_id_index ON reports (service_request_id);
            CREATE INDEX reports_review_id_index ON reports (review_id);
            CREATE INDEX reports_chat_room_id_index ON reports (chat_room_id);

            CREATE TABLE report_clarifications (
                id BIGSERIAL PRIMARY KEY,
                report_id BIGINT NOT NULL REFERENCES reports (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                requested_by_admin_id BIGINT NOT NULL REFERENCES admins (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                recipient_user_id BIGINT NOT NULL REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                question TEXT NOT NULL,
                requested_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                due_at TIMESTAMPTZ NOT NULL,

                CONSTRAINT report_clarifications_question_check CHECK (char_length(btrim(question, E' \t\n\r')) BETWEEN 5 AND 2000),
                CONSTRAINT report_clarifications_due_at_check CHECK (due_at = requested_at + INTERVAL '24 hours')
            );

            CREATE INDEX report_clarifications_report_requested_at_index ON report_clarifications (report_id, requested_at);
            CREATE INDEX report_clarifications_admin_index ON report_clarifications (requested_by_admin_id);
            CREATE INDEX report_clarifications_recipient_due_at_index ON report_clarifications (recipient_user_id, due_at);

            CREATE TABLE report_clarification_responses (
                id BIGSERIAL PRIMARY KEY,
                clarification_id BIGINT NOT NULL REFERENCES report_clarifications (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                respondent_id BIGINT NOT NULL REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                response_text TEXT,
                file_path TEXT,
                original_filename VARCHAR(255),
                mime_type VARCHAR(100),
                file_size INTEGER,
                evidence_url VARCHAR(2048),
                submitted_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT report_clarification_responses_content_check CHECK (
                    num_nonnulls(response_text, file_path, evidence_url) >= 1 AND num_nonnulls(file_path, evidence_url) <= 1
                ),
                CONSTRAINT report_clarification_responses_text_check CHECK (
                    char_length(response_text) <= 5000 AND btrim(response_text, E' \t\n\r') <> ''
                ),
                CONSTRAINT report_clarification_responses_file_check CHECK (
                    gigkampus_valid_attachment(file_path, original_filename, mime_type, file_size)
                ),
                CONSTRAINT report_clarification_responses_url_check CHECK (evidence_url ~* '^https://[^/?#[:space:]]+([/?#][^[:space:]]*)?$')
            );

            CREATE INDEX report_clarification_responses_clarification_submitted_at_index
                ON report_clarification_responses (clarification_id, submitted_at);
            CREATE INDEX report_clarification_responses_respondent_id_index ON report_clarification_responses (respondent_id);

            CREATE TABLE ktm_verification_requests (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                name_snapshot VARCHAR(100) NOT NULL,
                file_path TEXT NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                file_size INTEGER NOT NULL,
                ocr_nim_candidate VARCHAR(20),
                status VARCHAR(16) NOT NULL DEFAULT 'processing',
                ocr_error VARCHAR(1000),
                reviewed_by_admin_id BIGINT REFERENCES admins (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                review_reason TEXT,
                submitted_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ocr_finished_at TIMESTAMPTZ,
                reviewed_at TIMESTAMPTZ,

                CONSTRAINT ktm_verification_requests_name_check CHECK (char_length(btrim(name_snapshot, E' \t\n\r')) BETWEEN 2 AND 100),
                CONSTRAINT ktm_verification_requests_file_check CHECK (
                    btrim(file_path, E' \t\n\r') <> '' AND btrim(original_filename, E' \t\n\r') <> ''
                    AND mime_type IN ('image/jpeg', 'image/png') AND file_size BETWEEN 1 AND 5242880
                ),
                CONSTRAINT ktm_verification_requests_nim_check CHECK (ocr_nim_candidate ~ '^[A-Z0-9]{1,20}$'),
                CONSTRAINT ktm_verification_requests_status_check CHECK (
                    status IN ('processing', 'ocr_failed', 'pending_review', 'approved', 'rejected')
                ),
                CONSTRAINT ktm_verification_requests_ocr_check CHECK (
                    (status = 'processing' AND num_nonnulls(ocr_nim_candidate, ocr_error, ocr_finished_at) = 0)
                    OR (status = 'ocr_failed' AND ocr_nim_candidate IS NULL AND ocr_finished_at IS NOT NULL
                        AND btrim(ocr_error, E' \t\n\r') <> '' IS NOT FALSE)
                    OR (status IN ('pending_review', 'approved', 'rejected')
                        AND ocr_nim_candidate IS NOT NULL AND ocr_finished_at IS NOT NULL AND ocr_error IS NULL)
                ),
                CONSTRAINT ktm_verification_requests_review_check CHECK (
                    (status IN ('approved', 'rejected') AND num_nonnulls(reviewed_by_admin_id, reviewed_at) = 2)
                    OR (status NOT IN ('approved', 'rejected') AND num_nonnulls(reviewed_by_admin_id, review_reason, reviewed_at) = 0)
                ),
                CONSTRAINT ktm_verification_requests_review_reason_check CHECK (
                    (status <> 'rejected' OR review_reason IS NOT NULL)
                    AND (char_length(btrim(review_reason, E' \t\n\r')) BETWEEN 5 AND 1000) IS NOT FALSE
                )
            );

            CREATE UNIQUE INDEX ktm_verification_requests_active_user_unique ON ktm_verification_requests (user_id)
                WHERE status IN ('processing', 'pending_review');
            CREATE UNIQUE INDEX ktm_verification_requests_approved_user_unique ON ktm_verification_requests (user_id)
                WHERE status = 'approved';
            CREATE INDEX ktm_verification_requests_user_submitted_at_index ON ktm_verification_requests (user_id, submitted_at);
            CREATE INDEX ktm_verification_requests_reviewed_by_admin_id_index ON ktm_verification_requests (reviewed_by_admin_id);

            CREATE TABLE notifications (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                admin_id BIGINT REFERENCES admins (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                type VARCHAR(64) NOT NULL,
                title VARCHAR(150) NOT NULL,
                body TEXT NOT NULL,
                order_id BIGINT REFERENCES orders (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                report_id BIGINT REFERENCES reports (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                data JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                read_at TIMESTAMPTZ,

                CONSTRAINT notifications_recipient_check CHECK (num_nonnulls(user_id, admin_id) = 1),
                CONSTRAINT notifications_type_check CHECK (btrim(type, E' \t\n\r') <> ''),
                CONSTRAINT notifications_title_check CHECK (btrim(title, E' \t\n\r') <> ''),
                CONSTRAINT notifications_body_check CHECK (char_length(btrim(body, E' \t\n\r')) BETWEEN 1 AND 2000),
                CONSTRAINT notifications_data_check CHECK (jsonb_typeof(data) = 'object')
            );

            CREATE INDEX notifications_user_read_created_at_index ON notifications (user_id, read_at, created_at);
            CREATE INDEX notifications_admin_read_created_at_index ON notifications (admin_id, read_at, created_at);
            CREATE INDEX notifications_order_id_index ON notifications (order_id);
            CREATE INDEX notifications_report_id_index ON notifications (report_id);

            CREATE TABLE audit_logs (
                id BIGSERIAL PRIMARY KEY,
                actor_type VARCHAR(8) NOT NULL,
                actor_user_id BIGINT REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                actor_admin_id BIGINT REFERENCES admins (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                action VARCHAR(64) NOT NULL,
                entity_type VARCHAR(64) NOT NULL,
                entity_id BIGINT NOT NULL,
                order_id BIGINT REFERENCES orders (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                report_id BIGINT REFERENCES reports (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                before_data JSONB,
                after_data JSONB,
                reason TEXT,
                occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT audit_logs_actor_type_check CHECK (actor_type IN ('user', 'admin', 'system')),
                CONSTRAINT audit_logs_actor_check CHECK (
                    (actor_type = 'user' AND actor_user_id IS NOT NULL AND actor_admin_id IS NULL)
                    OR (actor_type = 'admin' AND actor_user_id IS NULL AND actor_admin_id IS NOT NULL)
                    OR (actor_type = 'system' AND num_nonnulls(actor_user_id, actor_admin_id) = 0)
                ),
                CONSTRAINT audit_logs_action_check CHECK (btrim(action, E' \t\n\r') <> ''),
                CONSTRAINT audit_logs_entity_type_check CHECK (btrim(entity_type, E' \t\n\r') <> ''),
                CONSTRAINT audit_logs_reason_check CHECK (btrim(reason, E' \t\n\r') <> '')
            );

            CREATE INDEX audit_logs_entity_occurred_at_index ON audit_logs (entity_type, entity_id, occurred_at);
            CREATE INDEX audit_logs_order_occurred_at_index ON audit_logs (order_id, occurred_at);
            CREATE INDEX audit_logs_report_occurred_at_index ON audit_logs (report_id, occurred_at);
            CREATE INDEX audit_logs_actor_user_id_index ON audit_logs (actor_user_id);
            CREATE INDEX audit_logs_actor_admin_id_index ON audit_logs (actor_admin_id);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE audit_logs, notifications, ktm_verification_requests, report_clarification_responses, report_clarifications, reports');
    }
};
