<?php

namespace App\Models\Concerns;

use App\Models\ChatRoom;
use App\Models\Gig;
use App\Models\Order;
use App\Models\PriceOffer;
use App\Models\Review;
use App\Models\ServiceRequest;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasSellerCapabilities
{
    public function gigs(): HasMany
    {
        return $this->hasMany(Gig::class, 'seller_id');
    }

    public function priceOffers(): HasMany
    {
        return $this->hasMany(PriceOffer::class, 'seller_id');
    }

    public function sellerOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'seller_id');
    }

    public function sellerChatRooms(): HasMany
    {
        return $this->hasMany(ChatRoom::class, 'seller_id');
    }

    public function receivedReviews(): HasMany
    {
        return $this->hasMany(Review::class, 'seller_id');
    }

    public function hasBankPaymentMethod(): bool
    {
        return filled($this->bank_id)
            && filled($this->bank_account_number)
            && filled($this->bank_account_holder_name);
    }

    public function hasQrisPaymentMethod(): bool
    {
        return filled($this->qris_image_path) && filled($this->qris_holder_name);
    }

    public function hasCompletePaymentMethod(): bool
    {
        return $this->hasBankPaymentMethod() || $this->hasQrisPaymentMethod();
    }

    public function canPublishGig(): bool
    {
        return $this->canCreateNewTransaction() && $this->hasCompletePaymentMethod();
    }

    public function canSubmitPriceOffer(ServiceRequest $request, ?CarbonInterface $at = null): bool
    {
        return $this->canPublishGig()
            && ! $this->hasId($request->buyer_id)
            && $request->acceptsOffers($at);
    }
}
