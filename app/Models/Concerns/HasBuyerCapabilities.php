<?php

namespace App\Models\Concerns;

use App\Enums\GigStatus;
use App\Models\ChatRoom;
use App\Models\Gig;
use App\Models\Order;
use App\Models\Review;
use App\Models\ServiceRequest;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasBuyerCapabilities
{
    public function buyerOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'buyer_id');
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class, 'buyer_id');
    }

    public function buyerChatRooms(): HasMany
    {
        return $this->hasMany(ChatRoom::class, 'buyer_id');
    }

    public function writtenReviews(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewer_id');
    }

    public function canBuyGig(Gig $gig): bool
    {
        return $this->canCreateNewTransaction()
            && $gig->status === GigStatus::Active
            && ! $this->hasId($gig->seller_id)
            && $gig->seller?->canPublishGig() === true;
    }
}
