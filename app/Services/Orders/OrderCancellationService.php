<?php

namespace App\Services\Orders;

use App\Enums\CancellationStatus;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use InvalidArgumentException;

final class OrderCancellationService
{
    public function cancelUnpaid(User $actor, Order $order): void
    {
        OrderTransaction::run($actor, $order, function (Order $order, Payment $payment) use ($actor): void {
            abort_unless($order->status === OrderStatus::AwaitingPayment && ! $payment->isVerified(), 409,
                'Pembatalan langsung hanya tersedia saat pesanan menunggu pembayaran.');

            $hasRejectedProofs = $payment->hasRejectedProofs();
            abort_if($order->hasSeller($actor) && $hasRejectedProofs, 403);

            $before = $this->state($order, $payment);
            $order->cancelUnpaid($actor, $hasRejectedProofs);
            $order->saveOrFail();
            OrderActivity::archiveChat($order);
            OrderActivity::record($order, $actor, 'order.cancelled', 'Pesanan dibatalkan',
                'Pesanan yang belum dibayar telah dibatalkan tanpa kewajiban pengembalian dana.', $before, $this->state($order, $payment));
        });
    }

    public function request(User $actor, Order $order, string $reason): void
    {
        OrderTransaction::run($actor, $order, function (Order $order, Payment $payment) use ($actor, $reason): void {
            $this->assertPaidWork($order, $payment);
            $before = $this->state($order, $payment);
            $order->requestCancellation($actor, $reason);
            $order->saveOrFail();
            OrderActivity::record($order, $actor, 'cancellation.requested', 'Permintaan pembatalan',
                'Tanggapi permintaan pembatalan pesanan dalam 24 jam.', $before, $this->state($order, $payment), $order->cancellation_reason);
        });
    }

    public function respond(User $actor, Order $order, int $version, CancellationStatus $decision, ?string $reason = null): void
    {
        OrderTransaction::run($actor, $order, function (Order $order, Payment $payment) use ($actor, $version, $decision, $reason): void {
            $this->assertPaidWork($order, $payment);
            abort_unless($order->cancellation_status === CancellationStatus::Pending && $order->cancellation_version === $version,
                409, 'Permintaan pembatalan telah berubah. Periksa status terbaru.');
            abort_unless($actor->hasId($order->cancellation_requested_by_id) === ($decision === CancellationStatus::Withdrawn), 403);

            $before = $this->state($order, $payment);
            $at = now();
            switch ($decision) {
                case CancellationStatus::Approved:
                    $order->approveCancellation($actor, $version, $at);
                    $payment->requestRefund($order, $at);
                    $payment->saveOrFail();
                    $title = 'Pembatalan disetujui';
                    $message = 'Pesanan dibatalkan dan pengembalian dana penuh menunggu penyelesaian.';
                    break;
                case CancellationStatus::Rejected:
                    $order->rejectCancellation($actor, $version, $reason ?? '', $at);
                    $title = 'Pembatalan ditolak';
                    $message = 'Periksa alasan penolakan permintaan pembatalan pada pesanan.';
                    break;
                case CancellationStatus::Withdrawn:
                    $order->withdrawCancellation($actor, $version, $at);
                    $title = 'Permintaan pembatalan ditarik';
                    $message = 'Permintaan pembatalan ditarik dan pesanan tetap berjalan.';
                    break;
                default:
                    throw new InvalidArgumentException('Invalid cancellation decision.');
            }

            $order->saveOrFail();
            OrderActivity::record($order, $actor, 'cancellation.'.$decision->value, $title, $message,
                $before, $this->state($order, $payment), $order->cancellation_response_reason);
        });
    }

    private function assertPaidWork(Order $order, Payment $payment): void
    {
        abort_unless($order->status->isPaidWorkStage() && $payment->isForOrder($order) && $payment->isVerified()
            && $payment->refund_status === RefundStatus::None, 409, 'Pembatalan bersama hanya tersedia pada tahap pengerjaan berbayar.');
    }

    private function state(Order $order, Payment $payment): array
    {
        return [
            'status' => $order->status->value,
            'stage_version' => $order->stage_version,
            'cancellation_status' => $order->cancellation_status->value,
            'cancellation_version' => $order->cancellation_version,
            'cancellation_requested_by_id' => $order->cancellation_requested_by_id,
            'cancellation_reason' => $order->cancellation_reason,
            'cancellation_requested_at' => $order->cancellation_requested_at?->toISOString(),
            'cancellation_closed_at' => $order->cancellation_closed_at?->toISOString(),
            'cancellation_response_reason' => $order->cancellation_response_reason,
            'refund_status' => $payment->refund_status->value,
            'refund_amount' => $payment->refund_status === RefundStatus::None ? null : $payment->amount,
            'refund_requested_at' => $payment->refund_requested_at?->toISOString(),
        ];
    }
}
