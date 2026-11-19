<?php

namespace App\Http\Controllers\Orders;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\PaymentProof;
use App\Services\Orders\OrderPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class OrderPaymentController
{
    public function selectMethod(Request $request, Order $order, OrderPaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
        ]);
        $payments->selectMethod($request->user(), $order, PaymentMethod::from($data['payment_method']));

        return redirect()->route('orders.payment.show', $order)->with('status', 'Metode pembayaran dipilih.');
    }

    public function storeProof(Request $request, Order $order, OrderPaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'proof' => ['bail', 'required', 'file', 'image', 'mimes:jpg,jpeg,png', 'extensions:jpg,jpeg,png', 'max:5120'],
        ], [
            'proof.required' => 'Unggah bukti pembayaran simulasi.',
            'proof.image' => 'Bukti pembayaran harus berformat JPG, JPEG, atau PNG.',
            'proof.mimes' => 'Bukti pembayaran harus berformat JPG, JPEG, atau PNG.',
            'proof.extensions' => 'Bukti pembayaran harus berformat JPG, JPEG, atau PNG.',
            'proof.max' => 'Ukuran bukti pembayaran maksimal 5 MB.',
        ]);
        $payments->submitProof($request->user(), $order, $data['proof']);

        return redirect()->route('orders.payment.show', $order)->with('status', 'Bukti dikirim dan menunggu pemeriksaan penjual.');
    }

    public function verifyProof(Request $request, Order $order, PaymentProof $paymentProof, OrderPaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'confirmed_recipient' => ['accepted'],
        ], [
            'confirmed_recipient.accepted' => 'Periksa bahwa penerima sesuai dengan tujuan pembayaran pesanan.',
        ]);
        $payments->verifyProof($request->user(), $order, $paymentProof, (int) $data['amount']);

        return redirect()->route('orders.payment.show', $order)->with('status', 'Pembayaran divalidasi. Pengerjaan dapat dimulai.');
    }

    public function rejectProof(Request $request, Order $order, PaymentProof $paymentProof, OrderPaymentService $payments): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'between:5,1000', 'not_regex:/\x00/']]);
        $payments->rejectProof($request->user(), $order, $paymentProof, $data['reason']);

        return redirect()->route('orders.payment.show', $order)->with('status', 'Bukti ditolak. Pembeli dapat mengunggah bukti pengganti.');
    }
}
