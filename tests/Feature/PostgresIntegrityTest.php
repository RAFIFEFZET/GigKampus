<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgresTestCase;

class PostgresIntegrityTest extends PostgresTestCase
{
    public static function invalidAttributes(): iterable
    {
        yield 'invalid Gig status' => ['gigs', [], ['status' => 'published']];
        yield 'free Gig price' => ['gigs', [], ['base_price' => 0]];
        yield 'long delivery duration' => ['gigs', [], ['delivery_days' => 366]];
        yield 'request maximum below minimum' => ['service_requests', [], ['budget_min' => 200, 'budget_max' => 100]];
        yield 'empty offer note' => ['price_offers', [], ['proposal_note' => str_repeat(' ', 50)]];
        yield 'missing reason for rejected proof' => ['payment_proofs', ['seller_review_status' => 'rejected'], ['rejection_reason' => null]];
        yield 'impossible revision number' => ['revisions', [], ['revision_no' => 21]];
        yield 'delivery empty' => ['order_deliveries', [], [
            'file_path' => null, 'original_filename' => null, 'mime_type' => null, 'file_size' => null, 'result_url' => null,
        ]];
        yield 'delivery URL is not HTTPS' => ['order_deliveries', [], [
            'file_path' => null, 'original_filename' => null, 'mime_type' => null, 'file_size' => null, 'result_url' => 'http://example.test/result',
        ]];
        yield 'rating out of range' => ['reviews', [], ['rating' => 0]];
        yield 'empty chat message' => ['chat_messages', [], [
            'message_body' => " \t\n", 'file_path' => null, 'original_filename' => null, 'mime_type' => null, 'file_size' => null, 'link_url' => null,
        ]];
        yield 'notification payload array' => ['notifications', [], ['data' => '[]']];
        yield 'notification without recipient' => ['notifications', [], ['user_id' => null, 'admin_id' => null]];
        yield 'order complaint missing stage' => ['reports', ['report_type' => 'order_complaint'], ['order_stage_version' => null]];
        yield 'clarification due at the wrong time' => ['report_clarifications', [], ['due_at' => '2001-01-01T00:00:00Z']];
        yield 'clarification response without evidence or text' => ['report_clarification_responses', [], [
            'response_text' => null, 'file_path' => null, 'original_filename' => null, 'mime_type' => null, 'file_size' => null, 'evidence_url' => null,
        ]];
        yield 'KTM approval without a candidate' => ['ktm_verification_requests', ['status' => 'approved'], ['ocr_nim_candidate' => null]];
        yield 'audit system actor with human IDs' => ['audit_logs', ['actor_type' => 'user'], ['actor_type' => 'system']];
    }

    #[DataProvider('invalidAttributes')]
    public function test_postgresql_rejects_invalid_values(string $table, array $where, array $changes): void
    {
        $attributes = $this->copyRow($this->row($table, $where), $changes);

        $this->assertSqlState('23514', static fn () => DB::table($table)->insert($attributes));
    }

    public static function partialUniqueRows(): iterable
    {
        yield 'one pending proof per payment' => ['payment_proofs', ['seller_review_status' => 'pending'], 'attempt_no'];
        yield 'one unfinished revision per order' => ['revisions', ['status' => 'requested'], 'revision_no'];
        yield 'one first delivery per order' => ['order_deliveries', ['revision_id' => null], null];
        yield 'one pending complaint per order' => ['reports', ['report_type' => 'order_complaint', 'status' => 'pending'], null];
        yield 'one unfinished KTM per user' => ['ktm_verification_requests', ['status' => 'pending_review'], null];
        yield 'one approved KTM per user' => ['ktm_verification_requests', ['status' => 'approved'], null];
    }

    #[DataProvider('partialUniqueRows')]
    public function test_postgresql_rejects_duplicate_active_records(string $table, array $where, ?string $sequence): void
    {
        $row = $this->row($table, $where);
        $attributes = $this->copyRow($row, $sequence ? [$sequence => $row->{$sequence} + 1] : []);

        $this->assertSqlState('23505', static fn () => DB::table($table)->insert($attributes));
    }

    public function test_both_preactivation_chat_indexes_allow_archived_history_only(): void
    {
        foreach (['gig_id', 'price_offer_id'] as $source) {
            $room = DB::table('chat_rooms')->whereNull('order_id')->whereNotNull($source)->where('status', 'active')->first();
            self::assertNotNull($room);

            $this->assertSqlState('23505', fn () => DB::table('chat_rooms')->insert($this->copyRow($room)));
            self::assertTrue(DB::table('chat_rooms')->insert($this->copyRow($room, [
                'status' => 'archived',
                'archived_at' => CarbonImmutable::parse($room->created_at)->addDay(),
            ])));
        }
    }

    public function test_non_order_report_indexes_scope_pending_duplicates_to_reporter_and_target(): void
    {
        $report = $this->row('reports', ['status' => 'pending']);

        foreach ([
            'gig_report' => ['gigs', 'gig_id'],
            'request_report' => ['service_requests', 'service_request_id'],
            'review_report' => ['reviews', 'review_id'],
            'chat_report' => ['chat_rooms', 'chat_room_id'],
        ] as $type => [$target, $column]) {
            $attributes = $this->copyRow($report, [
                'report_type' => $type,
                'category' => $type === 'chat_report' ? 'harassment_sara' : 'misleading_spam',
                'gig_id' => null,
                'service_request_id' => null,
                'review_id' => null,
                'chat_room_id' => null,
                'order_id' => null,
                'order_status_before_dispute' => null,
                'order_stage_version' => null,
                $column => $this->row($target)->id,
            ]);
            DB::table('reports')->insertOrIgnore($attributes);
            $this->assertSqlState('23505', static fn () => DB::table('reports')->insert($attributes));
        }
    }

    public function test_payment_foreign_keys_bind_the_amount_and_verified_proof_to_the_same_order(): void
    {
        $order = $this->row('orders', ['transaction_type' => 'direct_gig', 'status' => 'awaiting_payment']);
        $payment = $this->row('payments', ['order_id' => $order->id]);
        $copyId = DB::table('orders')->insertGetId($this->copyRow($order, ['creation_key' => (string) Str::uuid()]));
        $attributes = $this->copyRow($payment, ['order_id' => $copyId, 'amount' => ((int) $order->total_price) + 1]);
        $this->assertSqlState('23503', static fn () => DB::table('payments')->insert($attributes));

        $foreignProof = DB::table('payment_proofs')->where('payment_id', '<>', $payment->id)->first();
        self::assertNotNull($foreignProof);
        $admin = $this->row('admins');
        $this->assertSqlState('23503', static fn () => DB::table('payments')->where('id', $payment->id)->update([
            'status' => 'verified',
            'verified_proof_id' => $foreignProof->id,
            'verified_by_admin_id' => $admin->id,
            'verified_at' => CarbonImmutable::parse($payment->created_at)->addHour(),
        ]));
    }

    public function test_revision_delivery_must_link_the_same_order(): void
    {
        $revision = $this->row('revisions', ['status' => 'requested']);
        $delivery = DB::table('order_deliveries')->where('order_id', '<>', $revision->order_id)->first();
        self::assertNotNull($delivery);

        $attributes = $this->copyRow($delivery, ['revision_id' => $revision->id]);
        $this->assertSqlState('23503', static fn () => DB::table('order_deliveries')->insert($attributes));
    }

    public static function immutableContent(): iterable
    {
        yield 'order price' => ['orders', [], ['total_price' => 43210]];
        yield 'order buyer brief' => ['orders', ['transaction_type' => 'direct_gig'], ['buyer_brief' => 'Ini perubahan brief pesanan setelah checkout.']];
        yield 'payment proof upload' => ['payment_proofs', [], ['file_path' => 'demo/other-proof.png']];
        yield 'final Seller proof decision' => ['payment_proofs', ['seller_review_status' => 'rejected'], ['seller_review_status' => 'verified', 'rejection_reason' => null]];
        yield 'revision note' => ['revisions', [], ['revision_note' => 'Ini catatan yang mencoba mengubah revisi lama.']];
        yield 'delivery content' => ['order_deliveries', [], ['delivery_note' => 'Hasil yang sudah dikirim tidak dapat diedit.']];
        yield 'review content' => ['reviews', [], ['comment' => 'Ulasan lama tidak dapat disunting langsung.']];
        yield 'chat content' => ['chat_messages', [], ['message_body' => 'Pesan lama tidak dapat disunting.']];
        yield 'clarification question' => ['report_clarifications', [], ['question' => 'Ini pertanyaan pengganti yang tidak diizinkan.']];
        yield 'clarification response' => ['report_clarification_responses', [], ['response_text' => 'Jawaban susulan harus menjadi rekaman baru.']];
        yield 'KTM photo' => ['ktm_verification_requests', [], ['file_path' => 'demo/altered-ktm.png']];
        yield 'audit log' => ['audit_logs', [], ['reason' => 'Riwayat audit tidak dapat ditulis ulang.']];
    }

    #[DataProvider('immutableContent')]
    public function test_historical_content_cannot_be_updated(string $table, array $where, array $changes): void
    {
        $row = $this->row($table, $where);

        $this->assertSqlState('23514', static fn () => DB::table($table)->where('id', $row->id)->update($changes));
    }

    public function test_append_only_records_cannot_be_deleted(): void
    {
        foreach (['chat_messages', 'order_deliveries', 'report_clarification_responses', 'audit_logs'] as $table) {
            $row = $this->row($table);
            $this->assertSqlState('23514', static fn () => DB::table($table)->where('id', $row->id)->delete());
        }
    }

    public function test_guards_allow_read_receipts_and_review_moderation(): void
    {
        $message = $this->row('chat_messages', ['read_at' => null]);
        $updatedMessage = DB::table('chat_messages')->where('id', $message->id)->update([
            'read_at' => CarbonImmutable::parse($message->sent_at)->addMinute(),
        ]);
        self::assertSame(1, $updatedMessage);

        $review = $this->row('reviews', ['is_hidden' => false]);
        $updatedReview = DB::table('reviews')->where('id', $review->id)->update([
            'is_hidden' => true,
            'hidden_by_admin_id' => $this->row('admins')->id,
            'hidden_at' => CarbonImmutable::parse($review->review_date)->addMinute(),
            'hide_reason' => 'Konten ulasan mengandung informasi privat.',
        ]);
        self::assertSame(1, $updatedReview);
    }

    public function test_name_is_locked_during_ktm_review_and_after_verification(): void
    {
        foreach (['buyer', 'pending'] as $account) {
            $student = $this->row('users', ['email' => "{$account}.demo@apps.ipb.ac.id"]);
            $this->assertSqlState('23514', static fn () => DB::table('users')->where('id', $student->id)->update([
                'name' => 'Nama pengganti yang belum diperiksa',
            ]));
        }

        $student = $this->row('users', ['email' => 'unverified.demo@apps.ipb.ac.id']);
        self::assertSame(1, DB::table('users')->where('id', $student->id)->update(['name' => 'Nama Demo Diperbaiki']));
    }

    public function test_direct_deadline_can_only_be_set_from_first_verification(): void
    {
        $order = $this->row('orders', ['transaction_type' => 'direct_gig', 'status' => 'awaiting_validation']);
        $payment = $this->row('payments', ['order_id' => $order->id]);
        $proof = $this->row('payment_proofs', ['payment_id' => $payment->id, 'seller_review_status' => 'pending']);
        $verifiedAt = CarbonImmutable::parse($proof->submitted_at)->addHour();
        $deadline = $verifiedAt->addHours(24 * $order->delivery_days_snapshot);

        $update = fn (CarbonImmutable $at) => DB::table('orders')->where('id', $order->id)->update([
            'delivery_deadline_at' => $at,
            'status' => 'in_progress',
            'stage_version' => $order->stage_version + 1,
        ]);

        $this->assertSqlState('23514', static fn () => $update($deadline));
        DB::table('payment_proofs')->where('id', $proof->id)->update([
            'seller_review_status' => 'verified',
            'reviewed_by_seller_id' => $order->seller_id,
            'reviewed_at' => $verifiedAt,
        ]);
        DB::table('payments')->where('id', $payment->id)->update([
            'status' => 'verified',
            'verified_proof_id' => $proof->id,
            'verified_by_user_id' => $order->seller_id,
            'verified_at' => $verifiedAt,
        ]);

        $this->assertSqlState('23514', static fn () => $update($deadline->addSecond()));
        self::assertSame(1, $update($deadline));
        $this->assertSqlState('23514', static fn () => $update($deadline->addDay()));
    }
}
