<?php

namespace App\Http\Controllers\Orders;

use App\Enums\CancellationStatus;
use App\Models\Order;
use App\Services\Orders\OrderCancellationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OrderCancellationController
{
    private const REASON_RULES = ['required', 'string', 'between:5,1000', 'not_regex:/\x00/'];

    public function cancelUnpaid(Request $request, Order $order, OrderCancellationService $cancellations): RedirectResponse
    {
        $cancellations->cancelUnpaid($request->user(), $order);

        return redirect()->route('orders.show', $order)->with('status', 'Pesanan dibatalkan.');
    }

    public function request(Request $request, Order $order, OrderCancellationService $cancellations): RedirectResponse
    {
        $data = $request->validate(['reason' => self::REASON_RULES]);
        $cancellations->request($request->user(), $order, $data['reason']);

        return redirect()->route('orders.show', $order)->with('status', 'Permintaan pembatalan dikirim.');
    }

    public function approve(Request $request, Order $order, OrderCancellationService $cancellations): RedirectResponse
    {
        $data = $request->validate(['cancellation_version' => ['required', 'integer', 'min:1']]);
        $cancellations->respond($request->user(), $order, (int) $data['cancellation_version'], CancellationStatus::Approved);

        return redirect()->route('orders.show', $order)->with('status', 'Pesanan dibatalkan. Pengembalian dana penuh perlu diproses.');
    }

    public function reject(Request $request, Order $order, OrderCancellationService $cancellations): RedirectResponse
    {
        $data = $request->validate([
            'cancellation_version' => ['required', 'integer', 'min:1'],
            'reason' => self::REASON_RULES,
        ]);
        $cancellations->respond($request->user(), $order, (int) $data['cancellation_version'], CancellationStatus::Rejected, $data['reason']);

        return redirect()->route('orders.show', $order)->with('status', 'Permintaan pembatalan ditolak.');
    }

    public function withdraw(Request $request, Order $order, OrderCancellationService $cancellations): RedirectResponse
    {
        $data = $request->validate(['cancellation_version' => ['required', 'integer', 'min:1']]);
        $cancellations->respond($request->user(), $order, (int) $data['cancellation_version'], CancellationStatus::Withdrawn);

        return redirect()->route('orders.show', $order)->with('status', 'Permintaan pembatalan ditarik.');
    }
}
