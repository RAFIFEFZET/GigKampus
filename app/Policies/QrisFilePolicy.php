<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\User;

class QrisFilePolicy
{
    public function viewCurrent(User|Admin $actor, User $owner): bool
    {
        return $actor instanceof User && $actor->hasId($owner->id);
    }
}
