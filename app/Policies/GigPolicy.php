<?php

namespace App\Policies;

use App\Enums\GigStatus;
use App\Models\Gig;
use App\Models\User;

final class GigPolicy
{
    public function order(User $user, Gig $gig): bool
    {
        return $user->canBuyGig($gig) && $gig->category?->is_active === true;
    }

    public function create(User $user): bool
    {
        return $user->canPublishGig();
    }

    public function update(User $user, Gig $gig): bool
    {
        return $user->hasId($gig->seller_id) && $user->canCreateNewTransaction();
    }

    public function toggle(User $user, Gig $gig): bool
    {
        return $this->changeStatus($user, $gig, $gig->status === GigStatus::Active ? GigStatus::Inactive : GigStatus::Active);
    }

    public function changeStatus(User $user, Gig $gig, GigStatus $status): bool
    {
        return $this->update($user, $gig)
            && $gig->status !== GigStatus::Suspended
            && ($status === GigStatus::Inactive
                || ($user->canPublishGig() && $gig->category?->is_active === true));
    }
}
