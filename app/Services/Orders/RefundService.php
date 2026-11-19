<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;

final class RefundService
{
    public function confirm(User $buyer, Order $order): void
    {
        OrderTransaction::run($buyer, $order, function (Order $order, Payment $payment) use ($buyer): void {
            abort_unless($order->hasBuyer($buyer), 403);

            $before = ['refund_status' => $payment->refund_status->value, 'refund_amount' => $payment->amount];
            $payment->confirmRefundByBuyer($buyer, $order);
            $payment->saveOrFail();
            OrderActivity::archiveChat($order);
            OrderActivity::record($order, $buyer, 'refund.confirmed', 'Pengembalian dana dikonfirmasi',
                'Pembeli telah mengonfirmasi penerimaan pengembalian dana penuh.', $before, [
                    'refund_status' => $payment->refund_status->value,
                    'refund_amount' => $payment->amount,
                    'refund_confirmed_by_user_id' => $payment->refund_confirmed_by_user_id,
                    'refund_confirmed_at' => $payment->refund_confirmed_at->toISOString(),
                ],
            );
        });
    }
}
