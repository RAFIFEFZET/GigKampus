<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentProofStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\HasPreciseTimestamps;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentProof extends Model
{
    use HasPreciseTimestamps;

    protected $table = 'payment_proofs';

    public $timestamps = false;

    protected $attributes = [
        'seller_review_status' => PaymentProofStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'payment_id' => 'integer',
            'attempt_no' => 'integer',
            'file_size' => 'integer',
            'seller_review_status' => PaymentProofStatus::class,
            'reviewed_by_seller_id' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function reviewedBySeller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_seller_id');
    }

    public function verifiedPayment(): HasOne
    {
        return $this->hasOne(Payment::class, 'verified_proof_id');
    }

    public function canBeReviewedBy(User $seller, Order $order): bool
    {
        if (! $order->hasSeller($seller) || $order->status !== OrderStatus::AwaitingValidation
            || $this->seller_review_status !== PaymentProofStatus::Pending) {
            return false;
        }

        $payment = $this->payment;

        return $payment !== null && $payment->getKey() === $this->payment_id
            && $payment->isForOrder($order) && $payment->status === PaymentStatus::Pending;
    }

    public function verify(User $seller, Order $order, ?CarbonInterface $at = null): void
    {
        $this->recordReview($seller, $order, PaymentProofStatus::Verified, null, $at);
    }

    public function reject(User $seller, Order $order, string $reason, ?CarbonInterface $at = null): void
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
            throw new DomainException('A proof rejection reason must have 5–1000 characters.');
        }

        $this->recordReview($seller, $order, PaymentProofStatus::Rejected, $reason, $at);
    }

    private function recordReview(
        User $seller,
        Order $order,
        PaymentProofStatus $status,
        ?string $reason,
        ?CarbonInterface $at,
    ): void {
        if (! $this->canBeReviewedBy($seller, $order)) {
            throw new DomainException('Only the seller can review a pending proof for this order while awaiting validation.');
        }

        $this->seller_review_status = $status;
        $this->reviewed_by_seller_id = $seller->getKey();
        $this->rejection_reason = $reason;
        $this->reviewed_at = $at ?? now();
    }
}
