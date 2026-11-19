<?php

namespace App\Services\Orders;

use App\Events\ChatChanged;
use App\Models\Order;
use App\Models\User;
use App\Services\Audit;

final class OrderActivity
{
    public static function record(
        Order $order,
        User $actor,
        string $action,
        ?string $title,
        string $message,
        array $before = [],
        array $after = [],
        ?string $reason = null,
    ): void {
        Audit::record($actor, $action, $order, $before, $after, $reason);

        if ($title !== null) {
            $order->notifications()->forceCreate([
                'user_id' => $order->hasBuyer($actor) ? $order->seller_id : $order->buyer_id,
                'type' => $action,
                'title' => $title,
                'body' => $message,
            ]);
        }
    }

    public static function archiveChat(Order $order): void
    {
        $room = $order->chatRoom()->lockForUpdate()->first();
        if ($room !== null && ! $room->isArchived()) {
            $room->archive();
            $room->saveOrFail();
            event(new ChatChanged($room->id, $room->buyer_id, $room->seller_id));
        }
    }
}
