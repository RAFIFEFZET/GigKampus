<?php

namespace App\Models;

use App\Casts\WholeRupiah;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProofStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Concerns\HasPreciseTimestamps;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasPreciseTimestamps;

    protected $table = 'payments';

    protected $attributes = [
        'status' => PaymentStatus::Pending->value,
        'refund_status' => RefundStatus::None->value,
    ];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'amount' => WholeRupiah::class,
            'payment_method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'verified_proof_id' => 'integer',
            'verified_by_user_id' => 'integer',
            'verified_by_admin_id' => 'integer',
            'verified_at' => 'immutable_datetime',
            'refund_status' => RefundStatus::class,
            'refund_requested_at' => 'immutable_datetime',
            'refund_confirmed_by_user_id' => 'integer',
            'refund_confirmed_by_admin_id' => 'integer',
            'refund_confirmed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(PaymentProof::class, 'payment_id');
    }

    public function verifiedProof(): BelongsTo
    {
        return $this->belongsTo(PaymentProof::class, 'verified_proof_id');
    }

    public function verifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function verifiedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'verified_by_admin_id');
    }

    public function refundConfirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refund_confirmed_by_user_id');
    }

    public function refundConfirmedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'refund_confirmed_by_admin_id');
    }

    public function isForOrder(Order $order): bool
    {
        return $order->getKey() !== null
            && $order->getKey() === $this->order_id
            && $this->amount !== null
            && $this->amount === $order->total_price;
    }

    public function isVerified(): bool
    {
        return $this->status === PaymentStatus::Verified;
    }

    public function hasRejectedProofs(): bool
    {
        return $this->proofs()->where('seller_review_status', PaymentProofStatus::Rejected->value)->exists();
    }

    public function selectMethod(PaymentMethod $method, User $buyer, Order $order): void
    {
        $this->assertAwaitingBuyerPayment($buyer, $order);

        if (! $order->supportsPaymentMethod($method)) {
            throw new DomainException('The selected method is unavailable on the order snapshot.');
        }

        $this->payment_method = $method;
    }

    public function resetForRetry(User $buyer, Order $order): void
    {
        $this->assertAwaitingBuyerPayment($buyer, $order);
        $this->status = PaymentStatus::Pending;
    }

    public function reject(PaymentProof $proof, User $seller, Order $order): void
    {
        $this->assertSellerDecision($proof, $seller, $order, PaymentProofStatus::Rejected);
        $this->status = PaymentStatus::Rejected;
    }

    public function verifyBySeller(PaymentProof $proof, User $seller, Order $order, ?CarbonInterface $at = null): void
    {
        $this->assertSellerDecision($proof, $seller, $order, PaymentProofStatus::Verified);
        $this->recordVerification($proof, $seller, $at);
    }

    public function verifyByAdmin(PaymentProof $proof, Admin $admin, Order $order, Report $complaint, ?CarbonInterface $at = null): void
    {
        if (! $complaint->canResolveOrderDispute($admin, $order) || ! $complaint->order_status_before_dispute?->isPaymentStage()
            || ! $this->isForOrder($order) || $this->isVerified() || ! $this->ownsProof($proof)) {
            throw new DomainException('The assigned admin can verify a disputed payment only with its related proof.');
        }

        $this->recordVerification($proof, $admin, $at);
    }

    public function requestRefund(Order $order, ?CarbonInterface $at = null): void
    {
        if (! $this->isForOrder($order) || ! $this->isVerified() || $this->refund_status !== RefundStatus::None
            || ! in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Disputed], true)) {
            throw new DomainException('A new refund requires a verified payment and a cancellation or dispute decision.');
        }

        $this->refund_status = RefundStatus::Pending;
        $this->refund_requested_at = $at ?? now();
    }

    public function confirmRefundByBuyer(User $buyer, Order $order, ?CarbonInterface $at = null): void
    {
        if (! $order->hasBuyer($buyer) || $order->status !== OrderStatus::Cancelled) {
            throw new DomainException('Only the buyer can confirm a refund for a cancelled, undisputed order.');
        }

        $this->recordRefundConfirmation($buyer, $order, $at);
    }

    public function confirmRefundByAdmin(Admin $admin, Order $order, Report $complaint, ?CarbonInterface $at = null): void
    {
        if (! $complaint->canResolveOrderDispute($admin, $order) || $complaint->order_status_before_dispute !== OrderStatus::Cancelled) {
            throw new DomainException('Only the assigned admin can confirm a disputed refund.');
        }

        $this->recordRefundConfirmation($admin, $order, $at);
    }

    private function assertAwaitingBuyerPayment(User $buyer, Order $order): void
    {
        if (! $this->isForOrder($order) || $this->isVerified() || ! $order->hasBuyer($buyer)
            || $order->status !== OrderStatus::AwaitingPayment) {
            throw new DomainException('Only the buyer can change an unverified payment while awaiting payment.');
        }
    }

    private function ownsProof(PaymentProof $proof): bool
    {
        return $this->getKey() !== null && $proof->getKey() !== null && $proof->payment_id === $this->getKey();
    }

    private function assertSellerDecision(
        PaymentProof $proof,
        User $seller,
        Order $order,
        PaymentProofStatus $expected,
    ): void {
        if (! $this->isForOrder($order) || ! $order->hasSeller($seller) || $order->status !== OrderStatus::AwaitingValidation
            || $this->status !== PaymentStatus::Pending || ! $this->ownsProof($proof)
            || $proof->seller_review_status !== $expected || ! $seller->hasId($proof->reviewed_by_seller_id)) {
            throw new DomainException('The payment requires the related proof and seller decision for an order awaiting validation.');
        }
    }

    private function recordVerification(PaymentProof $proof, User|Admin $verifier, ?CarbonInterface $at): void
    {
        $this->status = PaymentStatus::Verified;
        $this->verified_proof_id = $proof->getKey();
        $this->verified_by_user_id = $verifier instanceof User ? $verifier->getKey() : null;
        $this->verified_by_admin_id = $verifier instanceof Admin ? $verifier->getKey() : null;
        $this->verified_at = $at ?? now();
    }

    private function recordRefundConfirmation(User|Admin $confirmer, Order $order, ?CarbonInterface $at): void
    {
        if (! $this->isForOrder($order) || ! $this->isVerified() || $this->refund_status !== RefundStatus::Pending) {
            throw new DomainException('Only a pending refund for this order can be confirmed.');
        }

        $this->refund_status = RefundStatus::Confirmed;
        $this->refund_confirmed_by_user_id = $confirmer instanceof User ? $confirmer->getKey() : null;
        $this->refund_confirmed_by_admin_id = $confirmer instanceof Admin ? $confirmer->getKey() : null;
        $this->refund_confirmed_at = $at ?? now();
    }
}
