<?php

namespace App\Http\Controllers\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\RefundStatus;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class OrderController
{
    public function index(Request $request): View
    {
        $student = $request->user();
        abort_unless($student->is_verified, 403);
        $tab = $request->query('tab');
        $tab = in_array($tab, ['masuk', 'riwayat'], true) ? $tab : 'pembelian';
        $status = $request->query('status');
        $status = is_string($status) ? OrderStatus::tryFrom($status) : null;

        $orders = match ($tab) {
            'masuk' => $student->sellerOrders(),
            'riwayat' => Order::query()
                ->where(fn (Builder $query) => $query->where('buyer_id', $student->id)->orWhere('seller_id', $student->id))
                ->where(fn (Builder $query) => $query->where('status', OrderStatus::Completed)
                    ->orWhere(fn (Builder $cancelled) => $cancelled->where('status', OrderStatus::Cancelled)
                        ->whereHas('payment', fn (Builder $payment) => $payment->where('refund_status', '!=', RefundStatus::Pending)))),
            default => $student->buyerOrders(),
        };

        $orders = $orders->when($status, fn ($query) => $query->where('status', $status))
            ->with(['buyer', 'seller', 'payment'])
            ->latest('order_date')->orderByDesc('id')->paginate(10)->withQueryString();

        return view('pages.orders.index', compact('orders', 'tab', 'status', 'student'));
    }

    public function show(Request $request, Order $order): View
    {
        return view('pages.orders.show', $this->detail($request, $order));
    }

    public function payment(Request $request, Order $order): View
    {
        return view('pages.orders.payment', $this->detail($request, $order));
    }

    public function qris(Request $request, Order $order): View
    {
        $data = $this->detail($request, $order);
        abort_unless($order->supportsPaymentMethod(PaymentMethod::Qris), 404);

        return view('pages.orders.qris', $data);
    }

    public function studio(Request $request): View
    {
        $seller = $request->user();
        $queued = $seller->sellerOrders()->where('status', OrderStatus::AwaitingValidation)->count();
        $active = $seller->sellerOrders()->whereIn('status', [
            OrderStatus::AwaitingPayment, OrderStatus::AwaitingValidation, OrderStatus::InProgress,
            OrderStatus::Delivered, OrderStatus::Revision,
        ])->count();
        $rating = $seller->receivedReviews()->visible()->avg('rating');

        return view('pages.studio.index', compact('queued', 'active', 'rating'));
    }

    private function detail(Request $request, Order $order): array
    {
        Gate::authorize('view', $order);
        $student = $request->user('web');
        $order->load([
            'buyer', 'seller', 'review.reviewer', 'firstDelivery', 'chatRoom',
            'payment.proofs' => fn ($proofs) => $proofs->orderByDesc('attempt_no'),
            'revisions' => fn ($revisions) => $revisions->orderByDesc('id'),
            'deliveries.revision',
        ]);

        return [
            'order' => $order,
            'payment' => $order->payment,
            'isBuyer' => $student && $order->hasBuyer($student),
            'isSeller' => $student && $order->hasSeller($student),
            'isLate' => $order->firstDelivery && $order->delivery_deadline_at
                && $order->firstDelivery->submitted_at->gt($order->delivery_deadline_at),
        ];
    }
}
