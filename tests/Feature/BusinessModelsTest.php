<?php

namespace Tests\Feature;

use App\Contracts\BuyerInterface;
use App\Contracts\SellerInterface;
use App\Enums\CancellationStatus;
use App\Enums\GigStatus;
use App\Enums\KtmVerificationStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentProofStatus;
use App\Enums\PaymentStatus;
use App\Enums\PriceOfferStatus;
use App\Enums\RefundStatus;
use App\Enums\ReportCategory;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Enums\TransactionType;
use App\Models\Admin;
use App\Models\BaseAccount;
use App\Models\Gig;
use App\Models\KtmVerificationRequest;
use App\Models\Order;
use App\Models\PriceOffer;
use App\Models\Report;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Tests\PostgresTestCase;

class BusinessModelsTest extends PostgresTestCase
{
    public function test_admin_and_student_guards_validate_only_their_own_providers(): void
    {
        $plain = 'GigKampusDemo2026!';
        $student = User::where('email', 'buyer.demo@apps.ipb.ac.id')->firstOrFail();
        $admin = Admin::where('email', 'admin@gigkampus.test')->firstOrFail();
        $studentCredentials = ['email' => $student->email, 'password' => $plain];
        $adminCredentials = ['email' => $admin->email, 'password' => $plain];

        self::assertInstanceOf(BaseAccount::class, $student);
        self::assertInstanceOf(BaseAccount::class, $admin);
        self::assertSame('password_hash', $student->getAuthPasswordName());
        self::assertSame('password_hash', $admin->getAuthPasswordName());
        self::assertSame('argon2id', password_get_info($student->getAuthPassword())['algoName']);
        self::assertSame('argon2id', password_get_info($admin->getAuthPassword())['algoName']);
        self::assertArrayNotHasKey('password_hash', $student->toArray());
        self::assertArrayNotHasKey('password_hash', $admin->toArray());
        self::assertTrue(Auth::guard('web')->validate($studentCredentials));
        self::assertFalse(Auth::guard('admin')->validate($studentCredentials));
        self::assertTrue(Auth::guard('admin')->validate($adminCredentials));
        self::assertFalse(Auth::guard('web')->validate($adminCredentials));
    }

    public function test_buyer_and_seller_capabilities_share_one_account_without_granting_new_actions_when_suspended(): void
    {
        $order = Order::where('transaction_type', TransactionType::DirectGig)->firstOrFail();
        $buyer = $order->buyer;
        $seller = $order->seller;
        $gig = $order->gig;
        $unverified = User::where('email', 'unverified.demo@apps.ipb.ac.id')->firstOrFail();

        self::assertInstanceOf(BuyerInterface::class, $seller);
        self::assertInstanceOf(SellerInterface::class, $seller);
        self::assertSame(GigStatus::Active, $gig->status);
        self::assertTrue($buyer->canBuyGig($gig));
        self::assertFalse($seller->canBuyGig($gig));
        self::assertTrue($seller->canPublishGig());
        self::assertFalse($unverified->canCreateNewTransaction());
        self::assertFalse($unverified->canAccessExistingOrder($order));

        $seller->is_suspended = true;
        self::assertFalse($seller->canPublishGig());
        self::assertTrue($seller->canAccessExistingOrder($order));
    }

    public function test_money_cast_keeps_whole_rupiah_and_rejects_fractional_input(): void
    {
        $gig = Gig::firstOrFail();
        self::assertIsInt($gig->base_price);
        $gig->base_price = '102500';
        self::assertSame(102500, $gig->base_price);

        $this->expectException(InvalidArgumentException::class);
        $gig->base_price = '102500.50';
    }

    public function test_custom_order_preserves_the_last_microsecond_of_its_wib_deadline(): void
    {
        $order = Order::where('transaction_type', TransactionType::CustomRequest)->firstOrFail();

        self::assertSame('23:59:59.999999', $order->delivery_deadline_at->setTimezone('Asia/Jakarta')->format('H:i:s.u'));
        self::assertSame($order->total_price, $order->payment->amount);
        self::assertSame($order->price_offer_id, $order->priceOffer->id);
    }

    public function test_upload_rejection_and_reupload_each_advance_the_business_stage(): void
    {
        $order = Order::where('transaction_type', TransactionType::DirectGig)->where('status', OrderStatus::AwaitingPayment)->firstOrFail();
        $stage = $order->stage_version;

        $order->submitPaymentProof($order->buyer);
        self::assertSame(OrderStatus::AwaitingValidation, $order->status);
        self::assertSame($stage + 1, $order->stage_version);

        $order->rejectPaymentProof($order->seller);
        self::assertSame(OrderStatus::AwaitingPayment, $order->status);
        self::assertSame($stage + 2, $order->stage_version);

        $order->submitPaymentProof($order->buyer);
        self::assertSame($stage + 3, $order->stage_version);
    }

    public function test_a_dispute_and_dismissal_preserve_stage_but_a_revision_and_delivery_advance_it(): void
    {
        $order = Order::where('status', OrderStatus::Delivered)
            ->where('max_revisions_snapshot', '>=', 1)
            ->doesntHave('revisions')
            ->firstOrFail();
        $stage = $order->stage_version;
        self::assertSame(PaymentStatus::Verified, $order->payment->status);

        $order->openDispute($order->buyer, $order->payment);
        self::assertSame(OrderStatus::Disputed, $order->status);
        self::assertSame($stage, $order->stage_version);

        $admin = Admin::firstOrFail();
        $complaint = $this->claimComplaint($order, OrderStatus::Delivered, $admin);
        $order->resolveDispute($complaint, $admin, OrderStatus::Delivered);
        self::assertSame($stage, $order->stage_version);

        $order->requestRevision($order->buyer);
        self::assertSame(OrderStatus::Revision, $order->status);
        $order->deliver($order->seller);
        self::assertSame(OrderStatus::Delivered, $order->status);
        self::assertSame($stage + 2, $order->stage_version);
    }

    public function test_awaiting_validation_cannot_be_cancelled_as_unpaid(): void
    {
        $order = Order::where('status', OrderStatus::AwaitingValidation)->firstOrFail();

        $this->expectException(DomainException::class);
        $order->cancelUnpaid($order->buyer, false);
    }

    public function test_admin_verification_preserves_a_rejected_seller_decision_and_starts_the_direct_deadline(): void
    {
        $order = Order::where('status', OrderStatus::Disputed)->firstOrFail();
        $payment = $order->payment;
        $proof = $payment->proofs()->where('seller_review_status', PaymentProofStatus::Rejected)->firstOrFail();
        $complaint = $order->reports()->where('status', ReportStatus::Pending)->firstOrFail();
        $admin = Admin::firstOrFail();
        $at = $proof->submitted_at->addHour()->addMicroseconds(123456)->setTimezone('Asia/Jakarta');
        $stage = $order->stage_version;

        $otherAdmin = (new Admin)->forceFill(['id' => $admin->id + 1]);
        try {
            $payment->verifyByAdmin($proof, $otherAdmin, $order, $complaint, $at);
            self::fail('An unassigned admin verified the payment.');
        } catch (DomainException) {
            self::assertSame(PaymentStatus::Rejected, $payment->status);
        }

        $payment->verifyByAdmin($proof, $admin, $order, $complaint, $at);
        self::assertSame(PaymentProofStatus::Rejected, $proof->seller_review_status);
        self::assertSame($proof->id, $payment->verified_proof_id);
        self::assertSame($admin->id, $payment->verified_by_admin_id);
        self::assertNull($payment->verified_by_user_id);

        $order->resolveDispute($complaint, $admin, OrderStatus::InProgress, $at, $payment);
        self::assertSame($stage + 1, $order->stage_version);
        self::assertTrue($order->delivery_deadline_at->equalTo($at->utc()->addHours(24 * $order->delivery_days_snapshot)));
    }

    public function test_paid_cancellation_requires_the_current_request_version_before_refund(): void
    {
        $order = Order::where('cancellation_status', CancellationStatus::Pending)->firstOrFail();
        $stage = $order->stage_version;
        $payment = $order->payment;

        try {
            $order->approveCancellation($order->seller, $order->cancellation_version - 1);
            self::fail('The previous cancellation version was accepted.');
        } catch (DomainException) {
            self::assertSame(CancellationStatus::Pending, $order->cancellation_status);
            self::assertSame($stage, $order->stage_version);
        }

        $order->approveCancellation($order->seller, $order->cancellation_version);
        self::assertSame(OrderStatus::Cancelled, $order->status);
        self::assertSame($stage + 1, $order->stage_version);

        $payment->requestRefund($order);
        self::assertSame(RefundStatus::Pending, $payment->refund_status);
        self::assertFalse($order->isFinal($payment));
        $payment->confirmRefundByBuyer($order->buyer, $order);
        self::assertSame(RefundStatus::Confirmed, $payment->refund_status);
        self::assertTrue($order->isFinal($payment));
    }

    public function test_a_refund_dispute_cannot_reopen_work_and_refund_confirmation_preserves_stage(): void
    {
        $order = Order::where('status', OrderStatus::Cancelled)
            ->whereHas('payment', fn ($payment) => $payment->where('refund_status', RefundStatus::Pending))
            ->firstOrFail();
        $stage = $order->stage_version;
        $payment = $order->payment;
        $admin = Admin::firstOrFail();

        $order->openDispute($order->buyer, $payment);
        $complaint = $this->claimComplaint($order, OrderStatus::Cancelled, $admin);
        try {
            $order->resolveDispute($complaint, $admin, OrderStatus::Delivered, payment: $payment);
            self::fail('A refund dispute reopened the work.');
        } catch (DomainException) {
            self::assertSame(OrderStatus::Disputed, $order->status);
        }

        $payment->confirmRefundByAdmin($admin, $order, $complaint);
        $order->resolveDispute($complaint, $admin, OrderStatus::Cancelled, payment: $payment);
        self::assertSame(OrderStatus::Cancelled, $order->status);
        self::assertSame($stage, $order->stage_version);
        self::assertTrue($order->isFinal($payment));
    }

    public function test_offer_acceptance_requires_current_version_and_eligible_seller(): void
    {
        $offer = PriceOffer::where('status', PriceOfferStatus::Pending)->firstOrFail();
        $request = $offer->serviceRequest;
        $seller = $offer->seller;
        $buyer = $request->buyer;

        $seller->is_suspended = true;
        try {
            $offer->accept($request, $buyer, $seller, $offer->version);
            self::fail('A suspended seller could accept an offer.');
        } catch (DomainException) {
            self::assertSame(PriceOfferStatus::Pending, $offer->status);
        }

        $seller->is_suspended = false;
        try {
            $offer->accept($request, $buyer, $seller, $offer->version - 1);
            self::fail('The previous offer version was accepted.');
        } catch (DomainException) {
            self::assertSame(PriceOfferStatus::Pending, $offer->status);
        }

        $offer->accept($request, $buyer, $seller, $offer->version);
        self::assertSame(PriceOfferStatus::Accepted, $offer->status);
    }

    public function test_ktm_review_rejects_a_short_reason_and_accepts_no_approval_reason(): void
    {
        $request = KtmVerificationRequest::where('status', KtmVerificationStatus::PendingReview)->firstOrFail();
        $admin = Admin::firstOrFail();

        try {
            $request->approve($admin, 'OK');
            self::fail('A short KTM review reason was accepted.');
        } catch (DomainException) {
            self::assertSame(KtmVerificationStatus::PendingReview, $request->status);
        }

        $request->approve($admin, '  ');
        self::assertSame(KtmVerificationStatus::Approved, $request->status);
        self::assertNull($request->review_reason);
    }

    private function claimComplaint(Order $order, OrderStatus $beforeDispute, Admin $admin): Report
    {
        return Report::forceCreate([
            'reporter_id' => $order->buyer_id,
            'report_type' => ReportType::OrderComplaint,
            'order_id' => $order->id,
            'category' => ReportCategory::TransactionIssue,
            'description' => 'Uji pengaduan untuk memastikan tahap transaksi dipulihkan dengan benar.',
            'handled_by_admin_id' => $admin->id,
            'order_status_before_dispute' => $beforeDispute,
            'order_stage_version' => $order->stage_version,
        ]);
    }
}
