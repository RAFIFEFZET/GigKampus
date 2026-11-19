<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\KtmVerificationRequest;
use App\Models\User;

class KtmVerificationRequestPolicy
{
    public function view(User|Admin $actor, KtmVerificationRequest $verification): bool
    {
        return $actor instanceof Admin || $actor->hasId($verification->user_id);
    }

    public function create(User|Admin $actor): bool
    {
        return $actor instanceof User && ! $actor->is_suspended;
    }

    public function review(User|Admin $actor, KtmVerificationRequest $verification): bool
    {
        return $actor instanceof Admin;
    }
}
