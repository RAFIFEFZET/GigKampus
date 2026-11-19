<?php

namespace App\Models;

use App\Casts\WholeRupiah;
use App\Enums\PriceOfferStatus;
use App\Models\Concerns\HasPreciseTimestamps;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PriceOffer extends Model
{
    use HasPreciseTimestamps;

    public const CREATED_AT = 'offered_at';

    protected $table = 'price_offers';

    protected $attributes = [
        'status' => PriceOfferStatus::Pending->value,
        'version' => 1,
    ];

    protected $fillable = ['offered_price', 'max_revisions', 'proposal_note'];

    protected function casts(): array
    {
        return [
            'service_request_id' => 'integer',
            'seller_id' => 'integer',
            'offered_price' => WholeRupiah::class,
            'max_revisions' => 'integer',
            'status' => PriceOfferStatus::class,
            'version' => 'integer',
            'offered_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class, 'price_offer_id');
    }

    public function chatRooms(): HasMany
    {
        return $this->hasMany(ChatRoom::class, 'price_offer_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $offer): void {
            if ($offer->isDirty([...$offer->getFillable(), 'status', 'status_reason'])) {
                $offer->version = $offer->getOriginal('version') + 1;
            }
        });
    }

    public function canBeEdited(ServiceRequest $request, ?CarbonInterface $at = null): bool
    {
        return $this->status === PriceOfferStatus::Pending
            && $request->getKey() !== null
            && $request->getKey() === $this->service_request_id
            && $request->acceptsOffers($at);
    }

    public function accept(
        ServiceRequest $request,
        User $buyer,
        User $seller,
        int $expectedVersion,
        ?CarbonInterface $at = null,
    ): void {
        if (! $buyer->hasId($request->buyer_id) || ! $buyer->canCreateNewTransaction()
            || ! $seller->hasId($this->seller_id) || ! $seller->canSubmitPriceOffer($request, $at)
            || ! $this->canBeEdited($request, $at) || $this->version !== $expectedVersion
            || $this->offered_price > $request->budget_max) {
            throw new DomainException('This offer cannot be accepted for the request.');
        }

        $this->status = PriceOfferStatus::Accepted;
    }

    public function reject(string $reason): void
    {
        $this->assertPending();
        $this->finishWithReason(PriceOfferStatus::Rejected, $reason);
    }

    public function withdraw(User $seller, ServiceRequest $request, string $reason, ?CarbonInterface $at = null): void
    {
        if (! $seller->hasId($this->seller_id) || ! $this->canBeEdited($request, $at)) {
            throw new DomainException('Only the seller can withdraw their pending offer for an eligible request.');
        }

        $this->finishWithReason(PriceOfferStatus::Withdrawn, $reason);
    }

    private function assertPending(): void
    {
        if ($this->status !== PriceOfferStatus::Pending) {
            throw new DomainException('Only a pending offer can be rejected.');
        }
    }

    private function finishWithReason(PriceOfferStatus $status, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new DomainException('An offer decision reason must have 1–255 characters.');
        }

        $this->status = $status;
        $this->status_reason = $reason;
    }
}
