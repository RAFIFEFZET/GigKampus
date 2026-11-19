<?php

namespace App\Policies;

use App\Enums\RefundStatus;
use App\Models\Admin;
use App\Models\ChatRoom;
use App\Models\User;

final class ChatRoomPolicy
{
    public function viewAny(User|Admin $account): bool
    {
        return $account instanceof User && $account->is_verified;
    }

    public function view(User|Admin $account, ChatRoom $room): bool
    {
        return $account instanceof User && $room->hasParticipant($account)
            && ($room->order_id !== null || $account->canCreateNewTransaction());
    }

    public function send(User|Admin $account, ChatRoom $room): bool
    {
        return $this->view($account, $room) && ! $room->isArchived()
            && ($room->order_id !== null
                || ($room->buyer->canCreateNewTransaction() && $room->seller->canCreateNewTransaction()));
    }

    public function sendRefundProof(User|Admin $account, ChatRoom $room): bool
    {
        return $account instanceof User && $this->send($account, $room)
            && $account->hasId($room->seller_id)
            && $room->order?->payment?->refund_status === RefundStatus::Pending;
    }

    public function markRead(User|Admin $account, ChatRoom $room): bool
    {
        return $this->view($account, $room) && ! $room->isArchived();
    }
}
