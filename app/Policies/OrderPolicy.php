<?php

namespace App\Policies;

use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Models\Admin;
use App\Models\Order;
use App\Models\User;

final class OrderPolicy
{
    public function view(User|Admin $account, Order $order): bool
    {
        return $account instanceof User
            ? $order->hasParticipant($account)
            : $order->reports()->where('report_type', ReportType::OrderComplaint)
                ->where('status', ReportStatus::Pending)
                ->whereBelongsTo($account, 'handledByAdmin')
                ->exists();
    }
}
