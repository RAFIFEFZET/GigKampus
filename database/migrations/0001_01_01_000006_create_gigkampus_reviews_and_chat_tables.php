<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE reviews (
                id BIGSERIAL PRIMARY KEY,
                order_id BIGINT NOT NULL UNIQUE REFERENCES orders (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                reviewer_id BIGINT NOT NULL REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                seller_id BIGINT NOT NULL REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                rating SMALLINT NOT NULL,
                comment TEXT,
                is_hidden BOOLEAN NOT NULL DEFAULT false,
                hidden_by_admin_id BIGINT REFERENCES admins (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                hide_reason TEXT,
                hidden_at TIMESTAMPTZ,
                review_date TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT reviews_rating_check CHECK (rating BETWEEN 1 AND 5),
                CONSTRAINT reviews_comment_check CHECK (char_length(comment) <= 2000 AND btrim(comment, E' \t\n\r') <> ''),
                CONSTRAINT reviews_hide_check CHECK (
                    (is_hidden AND num_nonnulls(hidden_by_admin_id, hide_reason, hidden_at) = 3
                        AND char_length(btrim(hide_reason, E' \t\n\r')) BETWEEN 5 AND 1000)
                    OR (NOT is_hidden AND num_nonnulls(hidden_by_admin_id, hide_reason, hidden_at) = 0)
                )
            );

            CREATE INDEX reviews_reviewer_id_index ON reviews (reviewer_id);
            CREATE INDEX reviews_seller_hidden_index ON reviews (seller_id, is_hidden);
            CREATE INDEX reviews_hidden_by_admin_id_index ON reviews (hidden_by_admin_id);

            CREATE TABLE chat_rooms (
                id BIGSERIAL PRIMARY KEY,
                buyer_id BIGINT NOT NULL REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                seller_id BIGINT NOT NULL REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                gig_id BIGINT REFERENCES gigs (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                price_offer_id BIGINT REFERENCES price_offers (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                order_id BIGINT UNIQUE REFERENCES orders (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                archived_at TIMESTAMPTZ,

                CONSTRAINT chat_rooms_participants_check CHECK (buyer_id <> seller_id),
                CONSTRAINT chat_rooms_source_check CHECK (num_nonnulls(gig_id, price_offer_id) = 1),
                CONSTRAINT chat_rooms_status_check CHECK (status IN ('active', 'archived')),
                CONSTRAINT chat_rooms_archive_check CHECK ((status = 'archived') = (archived_at IS NOT NULL))
            );

            CREATE UNIQUE INDEX chat_rooms_active_gig_unique ON chat_rooms (buyer_id, seller_id, gig_id)
                WHERE order_id IS NULL AND status = 'active' AND gig_id IS NOT NULL;
            CREATE UNIQUE INDEX chat_rooms_active_offer_unique ON chat_rooms (buyer_id, seller_id, price_offer_id)
                WHERE order_id IS NULL AND status = 'active' AND price_offer_id IS NOT NULL;
            CREATE INDEX chat_rooms_buyer_created_at_index ON chat_rooms (buyer_id, created_at);
            CREATE INDEX chat_rooms_seller_created_at_index ON chat_rooms (seller_id, created_at);
            CREATE INDEX chat_rooms_gig_id_index ON chat_rooms (gig_id);
            CREATE INDEX chat_rooms_price_offer_id_index ON chat_rooms (price_offer_id);

            CREATE TABLE chat_messages (
                id BIGSERIAL PRIMARY KEY,
                chat_room_id BIGINT NOT NULL REFERENCES chat_rooms (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                sender_id BIGINT NOT NULL REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                client_message_key UUID NOT NULL,
                message_type VARCHAR(16) NOT NULL DEFAULT 'regular',
                message_body TEXT,
                file_path TEXT,
                original_filename VARCHAR(255),
                mime_type VARCHAR(100),
                file_size INTEGER,
                link_url VARCHAR(2048),
                sent_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                read_at TIMESTAMPTZ,

                CONSTRAINT chat_messages_room_client_key_unique UNIQUE (chat_room_id, client_message_key),
                CONSTRAINT chat_messages_type_check CHECK (message_type IN ('regular', 'refund_proof')),
                CONSTRAINT chat_messages_content_check CHECK (
                    num_nonnulls(message_body, file_path, link_url) >= 1 AND num_nonnulls(file_path, link_url) <= 1
                ),
                CONSTRAINT chat_messages_body_check CHECK (
                    char_length(message_body) <= 5000 AND btrim(message_body, E' \t\n\r') <> ''
                ),
                CONSTRAINT chat_messages_file_check CHECK (
                    gigkampus_valid_attachment(file_path, original_filename, mime_type, file_size)
                ),
                CONSTRAINT chat_messages_url_check CHECK (link_url ~* '^https://[^/?#[:space:]]+([/?#][^[:space:]]*)?$'),
                CONSTRAINT chat_messages_refund_proof_check CHECK (
                    message_type <> 'refund_proof'
                    OR (num_nonnulls(file_path, mime_type, file_size) = 3
                        AND mime_type IN ('image/jpeg', 'image/png') AND file_size BETWEEN 1 AND 5242880)
                )
            );

            CREATE INDEX chat_messages_room_sent_at_index ON chat_messages (chat_room_id, sent_at);
            CREATE INDEX chat_messages_sender_id_index ON chat_messages (sender_id);
            CREATE INDEX chat_messages_room_read_at_index ON chat_messages (chat_room_id, read_at);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE chat_messages, chat_rooms, reviews');
    }
};
