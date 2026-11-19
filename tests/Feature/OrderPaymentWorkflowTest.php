<?php

namespace Tests\Feature;

use App\Enums\CancellationStatus;
use App\Enums\ChatRoomStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProofStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderPaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\PostgresTestCase;

class OrderPaymentWorkflowTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_buyer_uploads_a_new_private_attempt_after_a_rejection_and_seller_verifies_it_once(): void
    {
        Storage::fake('private');
        $this->travelTo(CarbonImmutable::parse('2026-11-19 07:00:00', 'UTC'));
        $order = $this->order(1);
        $url = "/pesanan/{$order->id}/pembayaran";
        $this->actingAs($order->buyer)->patch("$url/metode", ['payment_method' => 'bank_transfer'])->assertRedirect();
        self::assertSame(PaymentMethod::BankTransfer, $order->payment()->sole()->payment_method);
        self::assertSame(1, $order->fresh()->stage_version);

        $this->post("$url/bukti", ['proof' => $this->proofUpload('transfer.png')])->assertRedirect();
        $first = $order->payment->proofs()->sole();
        Storage::disk('private')->assertExists($first->file_path);
        self::assertSame(1, $first->attempt_no);
        self::assertSame(2, $order->fresh()->stage_version);
        $this->post("$url/bukti", ['proof' => $this->proofUpload('ganda.png')])->assertConflict();
        $this->patch("$url/metode", ['payment_method' => 'qris'])->assertConflict();
        self::assertCount(1, Storage::disk('private')->allFiles());

        $this->actingAs($order->seller)->post("$url/bukti/{$first->id}/tolak", [
            'reason' => 'Nominal pada bukti kurang.',
        ])->assertRedirect();
        self::assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);
        self::assertSame(PaymentStatus::Rejected, $order->payment()->sole()->status);
        self::assertSame(3, $order->fresh()->stage_version);

        $this->actingAs($order->buyer)->post("$url/bukti", ['proof' => $this->proofUpload('perbaikan.png')])->assertRedirect();
        $replacement = $order->payment->proofs()->where('attempt_no', 2)->sole();
        self::assertSame(OrderStatus::AwaitingValidation, $order->fresh()->status);
        self::assertSame(PaymentStatus::Pending, $order->payment()->sole()->status);
        self::assertSame(PaymentProofStatus::Rejected, $first->fresh()->seller_review_status);
        self::assertSame('Nominal pada bukti kurang.', $first->fresh()->rejection_reason);
        Storage::disk('private')->assertExists([$first->file_path, $replacement->file_path]);

        $verifyUrl = "$url/bukti/{$replacement->id}/validasi";
        $this->actingAs($order->seller)->postJson($verifyUrl, [
            'amount' => $order->total_price + 1, 'confirmed_recipient' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        self::assertSame(PaymentProofStatus::Pending, $replacement->fresh()->seller_review_status);
        $this->post($verifyUrl, [
            'amount' => $order->total_price, 'confirmed_recipient' => true,
        ])->assertRedirect();
        $payment = $order->payment()->sole();
        self::assertSame(PaymentStatus::Verified, $payment->status);
        self::assertSame($replacement->id, $payment->verified_proof_id);
        self::assertSame($order->seller_id, $payment->verified_by_user_id);
        $order->refresh();
        self::assertSame(OrderStatus::InProgress, $order->status);
        self::assertSame(5, $order->stage_version);
        self::assertTrue($order->delivery_deadline_at->equalTo(now()->addHours(24 * $order->delivery_days_snapshot)));

        $this->travel(2)->hours();
        $this->post($verifyUrl, ['amount' => $order->total_price, 'confirmed_recipient' => true])->assertConflict();
        self::assertSame(5, $order->fresh()->stage_version);
        self::assertTrue($order->delivery_deadline_at->equalTo($order->fresh()->delivery_deadline_at));
        $this->assertDatabaseHas('notifications', [
            'order_id' => $order->id, 'user_id' => $order->buyer_id,
            'type' => 'payment.verified',
        ]);
    }

    public function test_payment_mutations_require_the_proper_participant_a_valid_image_and_a_related_proof(): void
    {
        Storage::fake('private');
        $order = $this->order(1);
        $url = "/pesanan/{$order->id}/pembayaran";
        $outsider = User::where('email', 'seller2.demo@apps.ipb.ac.id')->sole();
        $this->actingAs($outsider)->patch("$url/metode", ['payment_method' => 'bank_transfer'])->assertForbidden();
        $this->actingAs($order->seller)->post("$url/bukti", ['proof' => $this->proofUpload()])->assertForbidden();

        $this->actingAs($order->buyer)->postJson("$url/bukti", [
            'proof' => new UploadedFile(__FILE__, 'bukti.png', 'image/png', test: true),
        ])->assertUnprocessable()->assertJsonValidationErrors('proof');
        $this->postJson("$url/bukti", [
            'proof' => UploadedFile::fake()->create('bukti.png', 5121, 'image/png'),
        ])->assertUnprocessable()->assertJsonValidationErrors('proof');
        self::assertEmpty(Storage::disk('private')->allFiles());

        $pending = $this->order(2);
        $proof = $pending->payment->proofs()->sole();
        $otherProof = $this->order(3)->payment->proofs()->sole();
        $url = "/pesanan/{$pending->id}/pembayaran/bukti";
        $this->actingAs($pending->seller)->post("$url/{$otherProof->id}/tolak", [
            'reason' => 'Bukti bukan milik pesanan ini.',
        ])->assertNotFound();
        $this->postJson("$url/{$proof->id}/validasi", ['amount' => $pending->total_price])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmed_recipient');
        $this->actingAs($pending->buyer)->post("$url/{$proof->id}/validasi", [
            'amount' => $pending->total_price, 'confirmed_recipient' => true,
        ])->assertForbidden();
        self::assertSame(PaymentProofStatus::Pending, $proof->fresh()->seller_review_status);
    }

    public function test_snapshot_methods_remain_available_to_suspended_participants_in_existing_orders(): void
    {
        $order = $this->order(1);
        $holder = $order->bank_account_holder_name_snapshot;
        $order->seller->forceFill(['bank_account_holder_name' => 'Nama Rekening Baru'])->save();
        $order->buyer->forceFill(['is_suspended' => true])->save();

        $this->actingAs($order->buyer)->patch("/pesanan/{$order->id}/pembayaran/metode", [
            'payment_method' => 'bank_transfer',
        ])->assertRedirect();
        self::assertSame(PaymentMethod::BankTransfer, $order->payment->payment_method);
        self::assertSame($holder, $order->fresh()->bank_account_holder_name_snapshot);
    }

    public function test_seller_cannot_cancel_during_validation_or_after_a_rejection_but_buyer_can_cancel_after_rejection(): void
    {
        $order = $this->order(2);
        $this->actingAs($order->seller)->post("/pesanan/{$order->id}/pembatalan/langsung")->assertConflict();
        $this->actingAs($order->buyer)->post("/pesanan/{$order->id}/pembatalan/langsung")->assertConflict();
        self::assertSame(OrderStatus::AwaitingValidation, $order->fresh()->status);

        $rejected = $this->order(3);
        $url = "/pesanan/{$rejected->id}/pembatalan/langsung";
        $this->actingAs($rejected->seller)->post($url)->assertForbidden();
        $this->actingAs($rejected->buyer)->post($url)->assertRedirect();
        self::assertSame(OrderStatus::Cancelled, $rejected->fresh()->status);
        self::assertSame(4, $rejected->fresh()->stage_version);
        self::assertSame(RefundStatus::None, $rejected->payment->refund_status);
        self::assertSame(ChatRoomStatus::Archived, $rejected->chatRoom->status);
    }

    public function test_paid_cancellation_can_be_approved_after_twenty_four_hours_and_only_buyer_confirms_full_refund(): void
    {
        $order = $this->order(4);
        $stage = $order->stage_version;
        $version = $order->cancellation_version;
        $this->travelTo($order->cancellation_requested_at->addHours(25));
        $requester = $order->cancellationRequestedBy;
        $opponent = $order->hasBuyer($requester) ? $order->seller : $order->buyer;
        $approval = "/pesanan/{$order->id}/pembatalan/setujui";
        $refund = "/pesanan/{$order->id}/refund/konfirmasi";
        $this->actingAs($requester)->post($approval, ['cancellation_version' => $version])->assertForbidden();
        $this->actingAs($opponent)->post($approval, ['cancellation_version' => $version + 1])->assertConflict();
        self::assertSame(CancellationStatus::Pending, $order->fresh()->cancellation_status);
        $this->post($approval, ['cancellation_version' => $version])->assertRedirect();

        $order->refresh();
        self::assertSame(CancellationStatus::Approved, $order->cancellation_status);
        self::assertSame(OrderStatus::Cancelled, $order->status);
        self::assertSame($stage + 1, $order->stage_version);
        self::assertSame(RefundStatus::Pending, $order->payment->refund_status);
        self::assertSame($order->total_price, $order->payment->amount);
        self::assertSame(ChatRoomStatus::Active, $order->chatRoom->status);
        $this->post($approval, ['cancellation_version' => $version])->assertConflict();

        $this->actingAs($order->seller)->post($refund, ['confirmed' => true])->assertForbidden();
        $this->actingAs($order->buyer)->postJson($refund)->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->post($refund, ['confirmed' => true])->assertRedirect();
        $payment = $order->payment()->sole();
        self::assertSame(RefundStatus::Confirmed, $payment->refund_status);
        self::assertSame($order->buyer_id, $payment->refund_confirmed_by_user_id);
        self::assertSame(ChatRoomStatus::Archived, $order->chatRoom()->sole()->status);
        self::assertSame($stage + 1, $order->fresh()->stage_version);
        $this->post($refund, ['confirmed' => true])->assertConflict();
    }

    public function test_cancellation_version_preserves_history_and_prevents_responses_to_a_replaced_request(): void
    {
        $order = $this->order(5);
        $stage = $order->stage_version;
        $originalVersion = $order->cancellation_version;
        $originalReason = $order->cancellation_reason;
        $url = "/pesanan/{$order->id}/pembatalan";

        $this->actingAs($order->buyer)->post("$url/ajukan", ['reason' => 'Pengajuan pembatalan yang baru.'])->assertRedirect();
        $newVersion = $originalVersion + 1;
        self::assertSame($newVersion, $order->fresh()->cancellation_version);
        $audit = $order->auditLogs()->where('action', 'cancellation.requested')->latest('id')->firstOrFail();
        self::assertSame($originalReason, $audit->before_data['cancellation_reason']);
        $this->post("$url/ajukan", ['reason' => 'Tidak boleh mengganti permintaan terbuka.'])->assertConflict();
        $this->actingAs($order->seller)->post("$url/setujui", ['cancellation_version' => $originalVersion])->assertConflict();
        $this->post("$url/tarik", ['cancellation_version' => $newVersion])->assertForbidden();
        $this->post("$url/tolak", [
            'cancellation_version' => $newVersion, 'reason' => 'Hasil pekerjaan sudah diselesaikan.',
        ])->assertRedirect();
        self::assertSame(CancellationStatus::Rejected, $order->fresh()->cancellation_status);
        self::assertSame('Hasil pekerjaan sudah diselesaikan.', $order->fresh()->cancellation_response_reason);

        $this->actingAs($order->buyer)->post("$url/ajukan", ['reason' => 'Permintaan lanjutan dengan alasan yang sah.'])->assertRedirect();
        $this->post("$url/tarik", ['cancellation_version' => $newVersion + 1])->assertRedirect();
        self::assertSame(CancellationStatus::Withdrawn, $order->fresh()->cancellation_status);
        self::assertSame($stage, $order->fresh()->stage_version);
        self::assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_disputed_order_cannot_change_payment_cancellation_or_refund(): void
    {
        Storage::fake('private');
        $order = $this->order(10);
        $url = "/pesanan/{$order->id}";
        $this->actingAs($order->buyer)->patch("$url/pembayaran/metode", ['payment_method' => 'qris'])->assertConflict();
        $this->post("$url/pembayaran/bukti", ['proof' => $this->proofUpload()])->assertConflict();
        $this->post("$url/pembatalan/langsung")->assertConflict();
        $this->post("$url/pembatalan/ajukan", ['reason' => 'Tidak dapat dilanjutkan.'])->assertConflict();
        $this->post("$url/refund/konfirmasi", ['confirmed' => true])->assertConflict();
        self::assertSame(OrderStatus::Disputed, $order->fresh()->status);
        self::assertEmpty(Storage::disk('private')->allFiles());
    }

    public function test_cancellation_and_proof_decisions_reject_text_that_cannot_be_stored(): void
    {
        $order = $this->order(5);
        $path = "/pesanan/{$order->id}/pembatalan";
        $reason = "Alasan rusak \0 di tengah teks.";
        $this->actingAs($order->buyer)->postJson("$path/ajukan", ['reason' => $reason])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->post("$path/ajukan", ['reason' => 'Perubahan jadwal pelaksanaan.'])->assertRedirect();
        $this->actingAs($order->seller)->postJson("$path/tolak", [
            'reason' => $reason, 'cancellation_version' => $order->refresh()->cancellation_version,
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
        self::assertSame(CancellationStatus::Pending, $order->refresh()->cancellation_status);

        $awaiting = $this->order(2);
        $proof = $awaiting->payment->proofs()->sole();
        $this->actingAs($awaiting->seller)->postJson("/pesanan/{$awaiting->id}/pembayaran/bukti/{$proof->id}/tolak", ['reason' => $reason])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        self::assertSame(OrderStatus::AwaitingValidation, $awaiting->refresh()->status);
    }

    public function test_completed_order_voids_an_earlier_request_and_rejects_stale_approval(): void
    {
        $order = $this->order(5);
        $url = "/pesanan/{$order->id}";

        $this->actingAs($order->seller)->post("$url/pembatalan/ajukan", [
            'reason' => 'Perubahan jadwal kegiatan.',
        ])->assertRedirect();
        $order->refresh();
        $version = $order->cancellation_version;

        $this->actingAs($order->buyer)->post("$url/selesai", [
            'stage_version' => $order->stage_version,
        ])->assertRedirect();
        $this->post("$url/pembatalan/setujui", ['cancellation_version' => $version])->assertConflict();
        self::assertSame(CancellationStatus::Voided, $order->fresh()->cancellation_status);
        self::assertSame(RefundStatus::None, $order->payment->refund_status);
    }

    public function test_concurrent_seller_review_waits_on_the_same_order_before_changing_the_proof(): void
    {
        $order = $this->order(2);
        $payment = $order->payment;
        $proof = $payment->proofs()->sole();
        $seller = $order->seller;
        $payments = app(OrderPaymentService::class);

        // Keep the winner's changes uncommitted in the test transaction.
        $payments->verifyProof($seller, $order, $proof, $payment->amount);
        $primary = DB::getDefaultConnection();
        $peer = 'payment_review_peer';
        config(["database.connections.{$peer}" => DB::connection()->getConfig()]);
        DB::setDefaultConnection($peer);

        try {
            DB::transaction(function () use ($payments, $seller, $order, $proof): void {
                DB::statement("SET LOCAL lock_timeout = '250ms'");
                $payments->rejectProof($seller, $order, $proof, 'Bukti transfer tidak sesuai.');
            });
            self::fail('The second review passed while the first still held the order lock.');
        } catch (QueryException $exception) {
            self::assertSame('55P03', $exception->errorInfo[0]);
            self::assertStringContainsString('"orders"', $exception->getSql());
            self::assertStringContainsString('for update', $exception->getSql());
        } finally {
            DB::setDefaultConnection($primary);
            DB::purge($peer);
        }

        self::assertSame(PaymentProofStatus::Verified, $proof->fresh()->seller_review_status);
        self::assertSame(3, $order->fresh()->stage_version);
    }

    private function order(int $fixture): Order
    {
        return Order::query()->where('creation_key', sprintf('00000000-0000-4000-8000-%012d', $fixture))->sole();
    }

    private function proofUpload(string $filename = 'bukti.png'): UploadedFile
    {
        return new UploadedFile(resource_path('demo-fixtures/proof-bank.png'), $filename, test: true);
    }
}
