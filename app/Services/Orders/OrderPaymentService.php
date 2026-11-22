<?php

namespace App\Services\Orders;

use App\Enums\PaymentMethod;
use App\Enums\PaymentProofStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class OrderPaymentService
{
    public function selectMethod(User $buyer, Order $order, PaymentMethod $method): void
    {
        OrderTransaction::run($buyer, $order, function (Order $order, Payment $payment) use ($buyer, $method): void {
            abort_unless($order->hasBuyer($buyer), 403);

            $before = $payment->payment_method;
            $payment->selectMethod($method, $buyer, $order);
            if ($payment->isDirty('payment_method')) {
                $payment->saveOrFail();
                OrderActivity::record($order, $buyer, 'payment.method_selected', null, '',
                    ['payment_method' => $before->value],
                    ['payment_method' => $method->value],
                );
            }
        });
    }

    public function submitProof(User $buyer, Order $order, UploadedFile $file): void
    {
        $path = null;

        try {
            OrderTransaction::run($buyer, $order, function (Order $order, Payment $payment) use ($buyer, $file, &$path): void {
                abort_unless($order->hasBuyer($buyer), 403);
                abort_unless($order->supportsPaymentMethod($payment->payment_method), 409, 'Pilih metode pembayaran yang tersedia.');

                $before = $this->state($order, $payment);
                $payment->resetForRetry($buyer, $order);
                $order->submitPaymentProof($buyer);
                abort_if($payment->proofs()->where('seller_review_status', PaymentProofStatus::Pending->value)->exists(),
                    409, 'Masih ada bukti pembayaran yang menunggu pemeriksaan.');

                $path = $file->store("orders/{$order->id}/payment-proofs", 'private');
                abort_unless(is_string($path), 500, 'Bukti pembayaran gagal disimpan.');

                $filename = preg_replace('/[\x00-\x1F\x7F]/', '', $file->getClientOriginalName());
                $proof = $payment->proofs()->forceCreate([
                    'attempt_no' => $payment->proofs()->max('attempt_no') + 1,
                    'file_path' => $path,
                    'original_filename' => mb_substr(trim($filename), 0, 255),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'submitted_at' => now(),
                ]);
                $payment->saveOrFail();
                $order->saveOrFail();
                OrderActivity::record($order, $buyer, 'payment.proof_submitted', 'Bukti pembayaran baru',
                    'Periksa bukti pembayaran untuk pesanan ini.', $before, $this->state($order, $payment, $proof));
            });
        } catch (Throwable $exception) {
            if (is_string($path)) {
                Storage::disk('private')->delete($path);
            }

            throw $exception;
        }
    }

    public function verifyProof(User $seller, Order $order, PaymentProof $proof, int $amount): void
    {
        OrderTransaction::run($seller, $order, function (Order $order, Payment $payment) use ($seller, $proof, $amount): void {
            $proof = $this->lockPendingProof($order, $payment, $seller, $proof);
            if ($amount !== $payment->amount) {
                throw ValidationException::withMessages(['amount' => 'Nominal yang diperiksa harus sama persis dengan tagihan pesanan.']);
            }

            $before = $this->state($order, $payment, $proof);
            $at = now();
            $proof->verify($seller, $order, $at);
            $payment->verifyBySeller($proof, $seller, $order, $at);
            $order->startWork($seller, $payment);
            $proof->saveOrFail();
            $payment->saveOrFail();
            $order->saveOrFail();
            OrderActivity::record($order, $seller, 'payment.verified', 'Pembayaran divalidasi',
                'Pembayaran telah diperiksa. Pesanan mulai dikerjakan.', $before, $this->state($order, $payment, $proof));
        });
    }

    public function rejectProof(User $seller, Order $order, PaymentProof $proof, string $reason): void
    {
        OrderTransaction::run($seller, $order, function (Order $order, Payment $payment) use ($seller, $proof, $reason): void {
            $proof = $this->lockPendingProof($order, $payment, $seller, $proof);
            $before = $this->state($order, $payment, $proof);
            $proof->reject($seller, $order, $reason);
            $payment->reject($proof, $seller, $order);
            $order->rejectPaymentProof($seller);
            $proof->saveOrFail();
            $payment->saveOrFail();
            $order->saveOrFail();
            OrderActivity::record($order, $seller, 'payment.rejected', 'Bukti pembayaran ditolak',
                'Periksa alasan penolakan dan unggah bukti pengganti.', $before, $this->state($order, $payment, $proof), $proof->rejection_reason);
        });
    }

    private function lockPendingProof(Order $order, Payment $payment, User $seller, PaymentProof $proof): PaymentProof
    {
        abort_unless($order->hasSeller($seller), 403);

        $proof = $payment->proofs()->lockForUpdate()->findOrFail($proof->getKey());
        $proof->setRelation('payment', $payment);
        abort_unless($proof->canBeReviewedBy($seller, $order), 409, 'Bukti ini sudah diputuskan atau tidak lagi menunggu pemeriksaan.');

        return $proof;
    }

    private function state(Order $order, Payment $payment, ?PaymentProof $proof = null): array
    {
        return [
            'status' => $order->status->value,
            'stage_version' => $order->stage_version,
            'delivery_deadline_at' => $order->delivery_deadline_at?->toIso8601String(),
            'payment_status' => $payment->status->value,
            'payment_method' => $payment->payment_method->value,
            'amount' => $payment->amount,
            'proof_id' => $proof?->id,
            'attempt_no' => $proof?->attempt_no,
            'seller_review_status' => $proof?->seller_review_status->value,
        ];
    }
}
