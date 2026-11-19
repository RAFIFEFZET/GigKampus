<?php

namespace App\Models;

use App\Casts\WholeRupiah;
use App\Enums\CancellationStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\RefundStatus;
use App\Enums\RevisionStatus;
use App\Enums\TransactionType;
use App\Models\Concerns\HasPreciseTimestamps;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasPreciseTimestamps;

    public const CREATED_AT = 'order_date';

    protected $table = 'orders';

    protected $attributes = [
        'status' => OrderStatus::AwaitingPayment->value,
        'stage_version' => 1,
        'cancellation_status' => CancellationStatus::None->value,
        'cancellation_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'creation_key' => 'string',
            'buyer_id' => 'integer',
            'seller_id' => 'integer',
            'transaction_type' => TransactionType::class,
            'gig_id' => 'integer',
            'price_offer_id' => 'integer',
            'total_price' => WholeRupiah::class,
            'max_revisions_snapshot' => 'integer',
            'delivery_days_snapshot' => 'integer',
            'delivery_deadline_at' => 'immutable_datetime',
            'status' => OrderStatus::class,
            'stage_version' => 'integer',
            'bank_account_number_snapshot' => 'string',
            'cancellation_status' => CancellationStatus::class,
            'cancellation_requested_by_id' => 'integer',
            'cancellation_requested_at' => 'immutable_datetime',
            'cancellation_version' => 'integer',
            'cancellation_closed_at' => 'immutable_datetime',
            'order_date' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function gig(): BelongsTo
    {
        return $this->belongsTo(Gig::class, 'gig_id');
    }

    public function priceOffer(): BelongsTo
    {
        return $this->belongsTo(PriceOffer::class, 'price_offer_id');
    }

    public function cancellationRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancellation_requested_by_id');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class, 'order_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class, 'order_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(OrderDelivery::class, 'order_id');
    }

    public function firstDelivery(): HasOne
    {
        return $this->hasOne(OrderDelivery::class, 'order_id')->whereNull('revision_id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class, 'order_id');
    }

    public function chatRoom(): HasOne
    {
        return $this->hasOne(ChatRoom::class, 'order_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'order_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'order_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'order_id');
    }

    public function hasBuyer(User $user): bool
    {
        return $user->hasId($this->buyer_id);
    }

    public function hasSeller(User $user): bool
    {
        return $user->hasId($this->seller_id);
    }

    public function hasParticipant(User $user): bool
    {
        return $this->hasBuyer($user) || $this->hasSeller($user);
    }

    public function supportsPaymentMethod(PaymentMethod $method): bool
    {
        return match ($method) {
            PaymentMethod::BankTransfer => filled($this->bank_name_snapshot)
                && filled($this->bank_account_number_snapshot)
                && filled($this->bank_account_holder_name_snapshot),
            PaymentMethod::Qris => filled($this->qris_image_path_snapshot) && filled($this->qris_holder_name_snapshot),
        };
    }

    public function isFinal(Payment $payment): bool
    {
        return $this->status === OrderStatus::Completed
            || ($this->status === OrderStatus::Cancelled && $payment->isForOrder($this)
                && $payment->refund_status !== RefundStatus::Pending);
    }

    public function remainingRevisions(): int
    {
        $used = $this->exists ? $this->revisions()->count() : 0;

        return max(0, $this->max_revisions_snapshot - $used);
    }

    public function isCancellationResponseOverdue(?CarbonInterface $at = null): bool
    {
        return $this->cancellation_status === CancellationStatus::Pending
            && $this->cancellation_requested_at->addHours(24)->lessThanOrEqualTo($at ?? now());
    }

    public function submitPaymentProof(User $buyer): void
    {
        if (! $this->hasBuyer($buyer) || $this->status !== OrderStatus::AwaitingPayment) {
            throw new DomainException('Only the buyer can submit proof for an order awaiting payment.');
        }

        $this->advanceTo(OrderStatus::AwaitingValidation);
    }

    public function rejectPaymentProof(User $seller): void
    {
        if (! $this->hasSeller($seller) || $this->status !== OrderStatus::AwaitingValidation) {
            throw new DomainException('Only the seller can reject proof for an order awaiting validation.');
        }

        $this->advanceTo(OrderStatus::AwaitingPayment);
    }

    public function startWork(User $seller, Payment $payment): void
    {
        if (! $this->hasSeller($seller) || $this->status !== OrderStatus::AwaitingValidation
            || ! $payment->isForOrder($this) || ! $payment->isVerified()) {
            throw new DomainException('Only the seller can start work after payment is verified.');
        }

        $this->setDirectDeliveryDeadline($payment->verified_at);
        $this->advanceTo(OrderStatus::InProgress);
    }

    public function deliver(User $seller): void
    {
        if (! $this->hasSeller($seller) || ! in_array($this->status, [OrderStatus::InProgress, OrderStatus::Revision], true)) {
            throw new DomainException('Only the seller can deliver work in progress or a revision.');
        }

        $this->advanceTo(OrderStatus::Delivered);
    }

    public function requestRevision(User $buyer): void
    {
        if (! $this->hasBuyer($buyer) || $this->status !== OrderStatus::Delivered || $this->remainingRevisions() === 0
            || ($this->exists && $this->revisions()
                ->whereIn('status', [RevisionStatus::Requested->value, RevisionStatus::InProgress->value])->exists())) {
            throw new DomainException('A revision requires the buyer, a delivery, remaining quota, and no open revision.');
        }

        $this->advanceTo(OrderStatus::Revision);
    }

    public function complete(User $buyer, ?CarbonInterface $at = null): void
    {
        if (! $this->hasBuyer($buyer) || $this->status !== OrderStatus::Delivered) {
            throw new DomainException('Only the buyer can complete a delivered order.');
        }

        $this->advanceTo(OrderStatus::Completed);
        $this->voidPendingCancellation('The order was completed.', $at);
    }

    public function cancelUnpaid(User $actor, bool $hasRejectedProofs): void
    {
        if ($this->status !== OrderStatus::AwaitingPayment
            || ! ($this->hasBuyer($actor) || ($this->hasSeller($actor) && ! $hasRejectedProofs))) {
            throw new DomainException('This actor cannot cancel the order at its current payment stage.');
        }

        $this->advanceTo(OrderStatus::Cancelled);
    }

    public function requestCancellation(User $actor, string $reason, ?CarbonInterface $at = null): void
    {
        if (! $this->status->isPaidWorkStage() || ! $this->hasParticipant($actor)
            || $this->cancellation_status === CancellationStatus::Pending) {
            throw new DomainException('Cancellation requests require a participant and a paid work stage with no pending request.');
        }

        $this->cancellation_reason = $this->validatedCancellationReason($reason);
        $this->cancellation_requested_by_id = $actor->getKey();
        $this->cancellation_requested_at = $at ?? now();
        $this->cancellation_version++;
        $this->cancellation_response_reason = null;
        $this->cancellation_closed_at = null;
        $this->cancellation_status = CancellationStatus::Pending;
    }

    public function approveCancellation(User $actor, int $version, ?CarbonInterface $at = null): void
    {
        $this->assertCancellationAction($actor, $version, byRequester: false);
        $this->closeCancellation(CancellationStatus::Approved, null, $at);
        $this->advanceTo(OrderStatus::Cancelled);
    }

    public function rejectCancellation(User $actor, int $version, string $reason, ?CarbonInterface $at = null): void
    {
        $this->assertCancellationAction($actor, $version, byRequester: false);
        $this->closeCancellation(CancellationStatus::Rejected, $this->validatedCancellationReason($reason), $at);
    }

    public function withdrawCancellation(User $actor, int $version, ?CarbonInterface $at = null): void
    {
        $this->assertCancellationAction($actor, $version, byRequester: true);
        $this->closeCancellation(CancellationStatus::Withdrawn, null, $at);
    }

    public function openDispute(User $reporter, Payment $payment, bool $hasRejectedProofs = false): void
    {
        $eligible = match ($this->status) {
            OrderStatus::AwaitingPayment => $hasRejectedProofs,
            OrderStatus::AwaitingValidation => true,
            OrderStatus::InProgress, OrderStatus::Delivered, OrderStatus::Revision => $payment->isVerified(),
            OrderStatus::Cancelled => $payment->refund_status === RefundStatus::Pending,
            default => false,
        };

        if (! $reporter->is_verified || ! $this->hasParticipant($reporter) || ! $payment->isForOrder($this) || ! $eligible) {
            throw new DomainException('This participant cannot dispute the order at its current stage.');
        }

        $this->status = OrderStatus::Disputed;
    }

    /**
     * Apply the outcome using the pending complaint locked by the caller.
     */
    public function resolveDispute(
        Report $complaint,
        Admin $admin,
        OrderStatus $outcome,
        ?CarbonInterface $at = null,
        ?Payment $payment = null,
    ): void {
        $beforeDispute = $complaint->order_status_before_dispute;
        if (! $complaint->canResolveOrderDispute($admin, $this) || ! $beforeDispute?->canBeDisputed()) {
            throw new DomainException('Only the assigned admin can resolve the pending complaint for this order and stage.');
        }

        if ($outcome === $beforeDispute) {
            $this->status = $outcome;

            return;
        }

        $allowed = match ($outcome) {
            OrderStatus::InProgress => $beforeDispute->isPaymentStage(),
            OrderStatus::Cancelled => $beforeDispute !== OrderStatus::Cancelled,
            OrderStatus::Completed => $beforeDispute === OrderStatus::Delivered,
            default => false,
        };

        if (! $allowed || ! $payment?->isForOrder($this) || ! $payment->isVerified()
            || ($outcome === OrderStatus::Cancelled && $payment->refund_status !== RefundStatus::Pending)) {
            throw new DomainException('The decision outcome or payment state is invalid for the disputed stage.');
        }

        $this->setDirectDeliveryDeadline($payment->verified_at);
        $this->advanceTo($outcome);

        if ($outcome === OrderStatus::Cancelled || $outcome === OrderStatus::Completed) {
            $this->voidPendingCancellation('The dispute decision closed the order.', $at);
        }
    }

    public function setDirectDeliveryDeadline(CarbonInterface $verifiedAt): void
    {
        if ($this->transaction_type === TransactionType::DirectGig && $this->delivery_deadline_at === null) {
            if ($this->delivery_days_snapshot < 1 || $this->delivery_days_snapshot > 365) {
                throw new DomainException('A direct order must have a valid duration before setting its deadline.');
            }

            $this->delivery_deadline_at = CarbonImmutable::instance($verifiedAt)->utc()
                ->addHours(24 * $this->delivery_days_snapshot);
        }
    }

    private function advanceTo(OrderStatus $status): void
    {
        $this->status = $status;
        $this->stage_version++;
    }

    private function assertCancellationAction(User $actor, int $version, bool $byRequester): void
    {
        if (! $this->status->isPaidWorkStage() || ! $this->hasParticipant($actor)
            || $this->cancellation_status !== CancellationStatus::Pending || $this->cancellation_version !== $version
            || $actor->hasId($this->cancellation_requested_by_id) !== $byRequester) {
            throw new DomainException('The actor, request version, or order stage does not allow this cancellation action.');
        }
    }

    private function validatedCancellationReason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
            throw new DomainException('A cancellation reason must have 5–1000 characters.');
        }

        return $reason;
    }

    private function voidPendingCancellation(string $reason, ?CarbonInterface $at): void
    {
        if ($this->cancellation_status === CancellationStatus::Pending) {
            $this->closeCancellation(CancellationStatus::Voided, $reason, $at);
        }
    }

    private function closeCancellation(CancellationStatus $status, ?string $reason, ?CarbonInterface $at): void
    {
        $this->cancellation_status = $status;
        $this->cancellation_response_reason = $reason;
        $this->cancellation_closed_at = $at ?? now();
    }
}
