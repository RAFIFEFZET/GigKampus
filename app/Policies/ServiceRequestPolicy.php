<?php

namespace App\Policies;

use App\Enums\ServiceRequestStatus;
use App\Models\Admin;
use App\Models\ServiceRequest;
use App\Models\User;

final class ServiceRequestPolicy
{
    public function view(User|Admin|null $user, ServiceRequest $request): bool
    {
        if ($user instanceof Admin || $request->isPubliclyVisible() || $user?->hasId($request->buyer_id)) {
            return true;
        }

        return $user !== null
            && ! $request->is_hidden
            && $request->priceOffers()->whereBelongsTo($user, 'seller')->exists();
    }

    public function viewOffers(User|Admin $user, ServiceRequest $request): bool
    {
        return $user instanceof Admin || $user->hasId($request->buyer_id);
    }

    public function create(User $user): bool
    {
        return $user->canCreateNewTransaction();
    }

    public function update(User $user, ServiceRequest $request): bool
    {
        return $this->close($user, $request) && $request->canChangeTerms();
    }

    public function close(User $user, ServiceRequest $request): bool
    {
        return $user->hasId($request->buyer_id)
            && $user->canCreateNewTransaction()
            && ! $request->is_hidden
            && $request->status === ServiceRequestStatus::Open;
    }
}
