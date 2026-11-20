<?php

namespace App\Policies;

use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Enums\ReportStatus;
use App\Models\Admin;
use App\Models\ChatRoom;
use App\Models\Gig;
use App\Models\Order;
use App\Models\Report;
use App\Models\ReportClarification;
use App\Models\Review;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ReportPolicy
{
    public function submit(User $user, Gig|ServiceRequest|Review|ChatRoom|Order $target): bool
    {
        if (! $user->is_verified) {
            return false;
        }

        return match (true) {
            $target instanceof Order => $target->hasParticipant($user),
            $target instanceof ChatRoom => $target->hasParticipant($user)
                && ($target->order_id !== null || ! $user->is_suspended),
            $target instanceof Gig => ! $user->is_suspended && Gig::query()->publiclyVisible()->whereKey($target->id)->exists(),
            $target instanceof ServiceRequest => ! $user->is_suspended && app(ServiceRequestPolicy::class)->view($user, $target),
            $target instanceof Review => (! $user->is_suspended && ! $target->is_hidden && $target->seller->is_verified && ! $target->seller->is_suspended)
                || $target->order->hasParticipant($user),
        };
    }

    public function complaintUnavailable(User $user, Order $order): ?string
    {
        $payment = $order->payment;
        $eligible = $payment && match ($order->status) {
            OrderStatus::AwaitingPayment => $payment->hasRejectedProofs(),
            OrderStatus::AwaitingValidation => true,
            OrderStatus::InProgress, OrderStatus::Delivered, OrderStatus::Revision => $payment->isVerified(),
            OrderStatus::Cancelled => $payment->refund_status === RefundStatus::Pending,
            default => false,
        };

        if (! $eligible || ! $user->is_verified) {
            return 'Komplain tidak tersedia pada tahap pesanan ini.';
        }
        if ($order->reports()->where('status', ReportStatus::Pending)->exists()) {
            return 'Pesanan ini sudah memiliki komplain aktif.';
        }
        if ($order->reports()->where('status', ReportStatus::Dismissed)->where('reporter_id', $user->id)
            ->where('order_stage_version', $order->stage_version)->exists()) {
            return 'Komplain Anda sudah diputus pada tahap ini. Komplain baru tersedia setelah tahap transaksi berubah.';
        }

        return null;
    }

    public function handle(Admin $admin, Report $report): bool
    {
        return $report->isHandledBy($admin);
    }

    public function evidence(User|Admin $actor, Report $report): bool
    {
        return $actor instanceof Admin ? $this->handle($actor, $report) : $actor->hasId($report->reporter_id);
    }

    public function clarification(User|Admin $actor, ReportClarification $clarification): bool
    {
        return $actor instanceof Admin
            ? $actor->hasId($clarification->report->handled_by_admin_id)
            : $actor->hasId($clarification->recipient_user_id);
    }

    public function chatFiles(Admin $admin, ChatRoom $room): bool
    {
        return $this->activeCases($admin)->where(function (Builder $query) use ($room): void {
            $query->where('chat_room_id', $room->id);
            if ($room->order_id !== null) {
                $query->orWhere('order_id', $room->order_id);
            }
        })->exists();
    }

    private function activeCases(Admin $admin): Builder
    {
        return Report::query()->where('handled_by_admin_id', $admin->id)->where('status', ReportStatus::Pending);
    }
}
