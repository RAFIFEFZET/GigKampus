<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class OrderTransaction
{
    public static function run(User $actor, Order $order, Closure $operation): mixed
    {
        try {
            return DB::transaction(function () use ($actor, $order, $operation): mixed {
                // Shared ordering across all transaction writers: order, payment, then its children.
                $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
                abort_unless($locked->hasParticipant($actor), 403);
                abort_if($locked->status === OrderStatus::Disputed, 409, 'Pesanan sedang dalam sengketa. Tindakan transaksi dijeda.');
                $payment = $locked->payment()->lockForUpdate()->firstOrFail();

                return $operation($locked, $payment);
            });
        } catch (DomainException $exception) {
            throw new ConflictHttpException('Tindakan ini tidak tersedia pada tahap pesanan saat ini. Periksa status terbaru.', $exception);
        }
    }
}
