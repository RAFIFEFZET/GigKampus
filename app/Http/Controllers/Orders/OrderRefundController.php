<?php

namespace App\Http\Controllers\Orders;

use App\Models\Order;
use App\Services\Orders\RefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OrderRefundController
{
    public function confirm(Request $request, Order $order, RefundService $refunds): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']], [
            'confirmed.accepted' => 'Konfirmasi bahwa dana pengembalian penuh sudah diterima.',
        ]);
        $refunds->confirm($request->user(), $order);

        return redirect()->route('orders.show', $order)->with('status', 'Penerimaan pengembalian dana dikonfirmasi.');
    }
}
