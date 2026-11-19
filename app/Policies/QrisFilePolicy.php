<?php

namespace App\Policies;

use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Models\Admin;
use App\Models\Order;
use App\Models\User;

class QrisFilePolicy
{
    public function viewCurrent(User|Admin $actor, User $owner): bool
    {
        return $actor instanceof User && $actor->hasId($owner->id);
    }

    public function viewOrderSnapshot(User|Admin $actor, Order $order): bool
    {
        return $actor instanceof User
            ? $order->hasParticipant($actor)
            : $order->reports()
                ->where('report_type', ReportType::OrderComplaint)
                ->where('status', ReportStatus::Pending)
                ->whereBelongsTo($actor, 'handledByAdmin')
                ->exists();
    }
}
