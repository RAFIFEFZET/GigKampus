<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = [
        'admins', 'users', 'banks', 'categories', 'gigs', 'service_requests', 'price_offers',
        'orders', 'payments', 'payment_proofs', 'revisions', 'order_deliveries', 'reviews',
        'chat_rooms', 'chat_messages', 'reports', 'report_clarifications', 'report_clarification_responses',
        'ktm_verification_requests', 'notifications', 'audit_logs',
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION gigkampus_keep_id() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = format('%I primary key is immutable', TG_TABLE_NAME);
            END;
            $$;

            CREATE OR REPLACE FUNCTION gigkampus_keep_row() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = format('%I records cannot be deleted', TG_TABLE_NAME);
            END;
            $$;

            CREATE OR REPLACE FUNCTION gigkampus_only_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_NARGS = 0 OR (to_jsonb(OLD) - TG_ARGV) IS DISTINCT FROM (to_jsonb(NEW) - TG_ARGV) THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = format('%I record contains immutable fields', TG_TABLE_NAME);
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION gigkampus_keep_verified_identity() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.is_verified AND ROW(NEW.name, NEW.nim, NEW.is_verified)
                    IS DISTINCT FROM ROW(OLD.name, OLD.nim, OLD.is_verified)
                THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Verified identity is immutable';
                END IF;

                IF NEW.name IS DISTINCT FROM OLD.name AND EXISTS (
                    SELECT 1 FROM ktm_verification_requests
                    WHERE user_id = OLD.id AND status IN ('processing', 'pending_review')
                ) THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Student name is immutable while KTM verification is underway';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION gigkampus_guard_ktm_submission() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                current_name VARCHAR(100);
            BEGIN
                -- Serialize the name snapshot and a competing account name edit on the same user row.
                SELECT name INTO current_name FROM users WHERE id = NEW.user_id FOR NO KEY UPDATE;

                IF FOUND AND NEW.name_snapshot IS DISTINCT FROM current_name THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'KTM name snapshot must match the current student name';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION gigkampus_guard_order_deadline() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                first_verified_at TIMESTAMPTZ;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.transaction_type = 'direct_gig' AND NEW.delivery_deadline_at IS NOT NULL THEN
                        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Direct order deadline requires verified payment';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.delivery_deadline_at IS NOT DISTINCT FROM OLD.delivery_deadline_at THEN
                    RETURN NEW;
                END IF;

                IF OLD.transaction_type <> 'direct_gig' OR OLD.delivery_deadline_at IS NOT NULL THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Order deadline is immutable once established';
                END IF;

                SELECT verified_at INTO first_verified_at
                    FROM payments WHERE order_id = OLD.id AND status = 'verified';

                IF first_verified_at IS NULL OR NEW.delivery_deadline_at IS DISTINCT FROM
                    (first_verified_at + make_interval(hours => OLD.delivery_days_snapshot * 24))
                THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Direct order deadline must match the first verification plus the snapshot duration';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION gigkampus_keep_payment_decisions() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status = 'verified' AND ROW(NEW.status, NEW.payment_method, NEW.verified_proof_id,
                    NEW.verified_by_user_id, NEW.verified_by_admin_id, NEW.verified_at)
                    IS DISTINCT FROM ROW(OLD.status, OLD.payment_method, OLD.verified_proof_id,
                    OLD.verified_by_user_id, OLD.verified_by_admin_id, OLD.verified_at)
                THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Payment verification is immutable';
                END IF;

                IF (OLD.refund_requested_at IS NOT NULL AND NEW.refund_requested_at IS DISTINCT FROM OLD.refund_requested_at)
                    OR (OLD.refund_status = 'confirmed' AND ROW(NEW.refund_status, NEW.refund_confirmed_by_user_id,
                        NEW.refund_confirmed_by_admin_id, NEW.refund_confirmed_at)
                        IS DISTINCT FROM ROW(OLD.refund_status, OLD.refund_confirmed_by_user_id,
                        OLD.refund_confirmed_by_admin_id, OLD.refund_confirmed_at))
                THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Established refund history is immutable';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION gigkampus_keep_terminal_row() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW IS DISTINCT FROM OLD THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = format('%I record is final', TG_TABLE_NAME);
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION gigkampus_keep_revision_progress() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status = 'in_progress' AND NEW.status = 'requested' THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Revision status cannot return to requested';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION gigkampus_keep_room_link() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.order_id IS NOT NULL AND NEW.order_id IS DISTINCT FROM OLD.order_id THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Chat room order link is immutable once established';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION gigkampus_keep_report_handler() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.handled_by_admin_id IS NOT NULL AND NEW.handled_by_admin_id IS DISTINCT FROM OLD.handled_by_admin_id THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Report handler is immutable once established';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION gigkampus_keep_ktm_ocr_result() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status = 'processing' AND NEW.status NOT IN ('processing', 'ocr_failed', 'pending_review') THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'KTM initially requires an OCR result';
                END IF;

                IF OLD.status = 'pending_review' AND (
                    NEW.status NOT IN ('pending_review', 'approved', 'rejected')
                    OR ROW(NEW.ocr_nim_candidate, NEW.ocr_error, NEW.ocr_finished_at)
                        IS DISTINCT FROM ROW(OLD.ocr_nim_candidate, OLD.ocr_error, OLD.ocr_finished_at)
                ) THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'KTM OCR result is immutable once established';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER users_verified_identity_immutable BEFORE UPDATE OF name, nim, is_verified ON users
                FOR EACH ROW EXECUTE FUNCTION gigkampus_keep_verified_identity();

            CREATE TRIGGER price_offers_contract_fields_immutable BEFORE UPDATE ON price_offers
                FOR EACH ROW EXECUTE FUNCTION gigkampus_only_change(
                    'offered_price', 'max_revisions', 'proposal_note', 'status', 'status_reason', 'version', 'updated_at'
                );
            CREATE TRIGGER price_offers_terminal_immutable BEFORE UPDATE ON price_offers
                FOR EACH ROW WHEN (OLD.status <> 'pending') EXECUTE FUNCTION gigkampus_keep_terminal_row();

            CREATE TRIGGER orders_deadline_immutable BEFORE INSERT OR UPDATE OF delivery_deadline_at ON orders
                FOR EACH ROW EXECUTE FUNCTION gigkampus_guard_order_deadline();
            CREATE TRIGGER orders_snapshot_immutable BEFORE UPDATE ON orders
                FOR EACH ROW EXECUTE FUNCTION gigkampus_only_change(
                    'delivery_deadline_at', 'status', 'stage_version', 'cancellation_status', 'cancellation_requested_by_id',
                    'cancellation_reason', 'cancellation_requested_at', 'cancellation_version', 'cancellation_response_reason',
                    'cancellation_closed_at', 'updated_at'
                );

            CREATE TRIGGER payments_contract_fields_immutable BEFORE UPDATE ON payments
                FOR EACH ROW EXECUTE FUNCTION gigkampus_only_change(
                    'payment_method', 'status', 'verified_proof_id', 'verified_by_user_id', 'verified_by_admin_id', 'verified_at',
                    'refund_status', 'refund_requested_at', 'refund_confirmed_by_user_id', 'refund_confirmed_by_admin_id',
                    'refund_confirmed_at', 'updated_at'
                );
            CREATE TRIGGER payments_decisions_immutable BEFORE UPDATE ON payments
                FOR EACH ROW EXECUTE FUNCTION gigkampus_keep_payment_decisions();

            CREATE TRIGGER payment_proofs_upload_immutable BEFORE UPDATE ON payment_proofs
                FOR EACH ROW EXECUTE FUNCTION gigkampus_only_change(
                    'seller_review_status', 'reviewed_by_seller_id', 'rejection_reason', 'reviewed_at'
                );
            CREATE TRIGGER payment_proofs_decision_immutable BEFORE UPDATE ON payment_proofs
                FOR EACH ROW WHEN (OLD.seller_review_status <> 'pending') EXECUTE FUNCTION gigkampus_keep_terminal_row();

            CREATE TRIGGER revisions_request_immutable BEFORE UPDATE ON revisions
                FOR EACH ROW EXECUTE FUNCTION gigkampus_only_change('status', 'completed_at');
            CREATE TRIGGER revisions_keep_progress BEFORE UPDATE ON revisions
                FOR EACH ROW EXECUTE FUNCTION gigkampus_keep_revision_progress();
            CREATE TRIGGER revisions_terminal_immutable BEFORE UPDATE ON revisions
                FOR EACH ROW WHEN (OLD.status = 'completed') EXECUTE FUNCTION gigkampus_keep_terminal_row();

            CREATE TRIGGER order_deliveries_immutable BEFORE UPDATE ON order_deliveries
                FOR EACH ROW EXECUTE FUNCTION gigkampus_only_change();

            CREATE TRIGGER reviews_content_immutable BEFORE UPDATE ON reviews
                FOR EACH ROW EXECUTE FUNCTION gigkampus_only_change('is_hidden', 'hidden_by_admin_id', 'hide_reason', 'hidden_at');

            CREATE TRIGGER chat_rooms_participants_and_source_immutable BEFORE UPDATE ON chat_rooms
                FOR EACH ROW EXECUTE FUNCTION gigkampus_only_change('order_id', 'status', 'archived_at');
            CREATE TRIGGER chat_rooms_link_immutable BEFORE UPDATE ON chat_rooms
                FOR EACH ROW EXECUTE FUNCTION gigkampus_keep_room_link();
            CREATE TRIGGER chat_rooms_archive_immutable BEFORE UPDATE ON chat_rooms
                FOR EACH ROW WHEN (OLD.status = 'archived') EXECUTE FUNCTION gigkampus_keep_terminal_row();

            CREATE TRIGGER chat_messages_content_immutable BEFORE UPDATE ON chat_messages
                FOR EACH ROW EXECUTE FUNCTION gigkampus_only_change('read_at');

            CREATE TRIGGER reports_submission_immutable BEFORE UPDATE ON reports
                FOR EACH ROW EXECUTE FUNCTION gigkampus_only_change('status', 'handled_by_admin_id', 'decision_code', 'decision_note', 'decided_at');
            CREATE TRIGGER reports_handler_immutable BEFORE UPDATE ON reports
                FOR EACH ROW EXECUTE FUNCTION gigkampus_keep_report_handler();
            CREATE TRIGGER reports_decision_immutable BEFORE UPDATE ON reports
                FOR EACH ROW WHEN (OLD.status <> 'pending') EXECUTE FUNCTION gigkampus_keep_terminal_row();

            CREATE TRIGGER report_clarifications_immutable BEFORE UPDATE ON report_clarifications
                FOR EACH ROW EXECUTE FUNCTION gigkampus_only_change();

            CREATE TRIGGER report_clarification_responses_immutable BEFORE UPDATE ON report_clarification_responses
                FOR EACH ROW EXECUTE FUNCTION gigkampus_only_change();

            CREATE TRIGGER ktm_verification_requests_lock_name BEFORE INSERT ON ktm_verification_requests
                FOR EACH ROW WHEN (NEW.status IN ('processing', 'pending_review'))
                EXECUTE FUNCTION gigkampus_guard_ktm_submission();
            CREATE TRIGGER ktm_verification_requests_photo_immutable BEFORE UPDATE ON ktm_verification_requests
                FOR EACH ROW EXECUTE FUNCTION gigkampus_only_change('status', 'ocr_nim_candidate', 'ocr_error', 'ocr_finished_at',
                    'reviewed_by_admin_id', 'review_reason', 'reviewed_at');
            CREATE TRIGGER ktm_verification_requests_ocr_result_immutable BEFORE UPDATE ON ktm_verification_requests
                FOR EACH ROW EXECUTE FUNCTION gigkampus_keep_ktm_ocr_result();
            CREATE TRIGGER ktm_verification_requests_terminal_immutable BEFORE UPDATE ON ktm_verification_requests
                FOR EACH ROW WHEN (OLD.status IN ('ocr_failed', 'approved', 'rejected')) EXECUTE FUNCTION gigkampus_keep_terminal_row();

            CREATE TRIGGER notifications_content_immutable BEFORE UPDATE ON notifications
                FOR EACH ROW EXECUTE FUNCTION gigkampus_only_change('read_at');

            CREATE TRIGGER audit_logs_immutable BEFORE UPDATE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION gigkampus_only_change();
            SQL);

        foreach (self::TABLES as $table) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER gigkampus_immutable_id BEFORE UPDATE OF id ON {$table}
                    FOR EACH ROW WHEN (OLD.id IS DISTINCT FROM NEW.id) EXECUTE FUNCTION gigkampus_keep_id();
                CREATE TRIGGER gigkampus_keep_history AFTER DELETE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION gigkampus_keep_row();
                SQL);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::unprepared(<<<SQL
                DROP TRIGGER gigkampus_immutable_id ON {$table};
                DROP TRIGGER gigkampus_keep_history ON {$table};
                SQL);
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER audit_logs_immutable ON audit_logs;
            DROP TRIGGER notifications_content_immutable ON notifications;
            DROP TRIGGER ktm_verification_requests_terminal_immutable ON ktm_verification_requests;
            DROP TRIGGER ktm_verification_requests_ocr_result_immutable ON ktm_verification_requests;
            DROP TRIGGER ktm_verification_requests_photo_immutable ON ktm_verification_requests;
            DROP TRIGGER ktm_verification_requests_lock_name ON ktm_verification_requests;
            DROP TRIGGER report_clarification_responses_immutable ON report_clarification_responses;
            DROP TRIGGER report_clarifications_immutable ON report_clarifications;
            DROP TRIGGER reports_decision_immutable ON reports;
            DROP TRIGGER reports_handler_immutable ON reports;
            DROP TRIGGER reports_submission_immutable ON reports;
            DROP TRIGGER chat_messages_content_immutable ON chat_messages;
            DROP TRIGGER chat_rooms_archive_immutable ON chat_rooms;
            DROP TRIGGER chat_rooms_link_immutable ON chat_rooms;
            DROP TRIGGER chat_rooms_participants_and_source_immutable ON chat_rooms;
            DROP TRIGGER reviews_content_immutable ON reviews;
            DROP TRIGGER order_deliveries_immutable ON order_deliveries;
            DROP TRIGGER revisions_terminal_immutable ON revisions;
            DROP TRIGGER revisions_keep_progress ON revisions;
            DROP TRIGGER revisions_request_immutable ON revisions;
            DROP TRIGGER payment_proofs_decision_immutable ON payment_proofs;
            DROP TRIGGER payment_proofs_upload_immutable ON payment_proofs;
            DROP TRIGGER payments_decisions_immutable ON payments;
            DROP TRIGGER payments_contract_fields_immutable ON payments;
            DROP TRIGGER orders_snapshot_immutable ON orders;
            DROP TRIGGER orders_deadline_immutable ON orders;
            DROP TRIGGER price_offers_terminal_immutable ON price_offers;
            DROP TRIGGER price_offers_contract_fields_immutable ON price_offers;
            DROP TRIGGER users_verified_identity_immutable ON users;

            DROP FUNCTION gigkampus_keep_ktm_ocr_result();
            DROP FUNCTION gigkampus_keep_report_handler();
            DROP FUNCTION gigkampus_keep_room_link();
            DROP FUNCTION gigkampus_keep_revision_progress();
            DROP FUNCTION gigkampus_keep_terminal_row();
            DROP FUNCTION gigkampus_keep_payment_decisions();
            DROP FUNCTION gigkampus_guard_order_deadline();
            DROP FUNCTION gigkampus_guard_ktm_submission();
            DROP FUNCTION gigkampus_keep_verified_identity();
            DROP FUNCTION gigkampus_only_change();
            DROP FUNCTION gigkampus_keep_row();
            DROP FUNCTION gigkampus_keep_id();
            SQL);
    }
};
