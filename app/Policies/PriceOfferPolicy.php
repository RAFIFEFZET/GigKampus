<?php

namespace App\Policies;

use App\Models\PriceOffer;
use App\Models\ServiceRequest;
use App\Models\User;

final class PriceOfferPolicy
{
    public function manage(User $user, PriceOffer $offer): bool
    {
        return $user->hasId($offer->seller_id);
    }

    public function create(User $user, ServiceRequest $request): bool
    {
        return $user->canSubmitPriceOffer($request) && $request->category?->is_active === true;
    }

    public function update(User $user, PriceOffer $offer, ServiceRequest $request): bool
    {
        return $this->manage($user, $offer)
            && $user->canSubmitPriceOffer($request)
            && $offer->canBeEdited($request);
    }

    public function withdraw(User $user, PriceOffer $offer, ServiceRequest $request): bool
    {
        return $this->update($user, $offer, $request);
    }
}
