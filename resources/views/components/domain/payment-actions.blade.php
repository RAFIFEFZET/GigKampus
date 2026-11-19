@props(['order', 'payment', 'isBuyer' => false, 'isSeller' => false])

@php
    $methods = array_values(array_filter(\App\Enums\PaymentMethod::cases(), $order->supportsPaymentMethod(...)));
    $proof = $payment->proofs->firstWhere('seller_review_status', \App\Enums\PaymentProofStatus::Pending);
@endphp

@if ($isBuyer && $order->status === \App\Enums\OrderStatus::AwaitingPayment)
    <x-ui.card class="stack">
        <x-ui.section-header level="2" title="Unggah Bukti Pembayaran"/>
        @if (count($methods) > 1)
            <form method="post" action="{{ route('orders.payment.method', $order) }}" class="stack">
                @csrf
                @method('PATCH')
                <x-ui.select name="payment_method" label="Metode Pembayaran" :value="$payment->payment_method->value"
                    :options="array_combine(array_column($methods, 'value'), array_map(fn ($method) =>
                        $method === \App\Enums\PaymentMethod::Qris ? 'QRIS Seller' : 'Transfer Rekening Bank', $methods))" required/>
                <x-ui.button class="self-start" type="submit" variant="outline">Simpan Metode Bayar</x-ui.button>
            </form>
        @endif
        <form method="post" action="{{ route('orders.payment.proofs.store', $order) }}" enctype="multipart/form-data" class="stack">
            @csrf
            <div class="order-upload">
                <img src="{{ asset('design/order-receipt.svg') }}" alt="">
                <x-ui.file-upload name="proof" label="Bukti Pembayaran" help="JPG, JPEG, atau PNG. Maksimal 5 MB." required/>
            </div>
            <x-ui.button type="submit">Kirim Bukti Pembayaran</x-ui.button>
        </form>
    </x-ui.card>
@elseif ($isSeller && $order->status === \App\Enums\OrderStatus::AwaitingValidation && $proof)
    <x-ui.card class="stack">
        <x-ui.section-header level="2" title="Validasi Bukti Pembayaran"/>
        <div class="order-info flex min-w-0 flex-wrap items-center gap-4">
            <img src="{{ route('files.show', ['jenis' => 'payment_proof', 'id' => $proof->id]) }}"
                alt="Pratinjau bukti pembayaran percobaan {{ $proof->attempt_no }}" class="size-28 rounded-lg bg-white object-contain p-1">
            <div class="min-w-0 space-y-1">
                <p class="font-semibold">{{ $proof->original_filename }}</p>
                <p class="text-sm text-campus-muted">Percobaan {{ $proof->attempt_no }}</p>
                <x-domain.status-badge :status="$proof->seller_review_status"/>
            </div>
        </div>
        <form method="post" action="{{ route('orders.payment.proofs.verify', [$order, $proof]) }}" class="stack">
            @csrf
            <x-ui.input name="amount" label="Nominal yang Diterima (Rp)" type="number" min="1" max="100000000" required/>
            <label class="flex items-start gap-2 text-sm">
                <input name="confirmed_recipient" value="1" type="checkbox" class="mt-1 accent-campus-blue" required>
                <span>Nominal dan nama penerima sesuai dengan tujuan pembayaran.</span>
            </label>
            @error('confirmed_recipient')<p class="field-error">{{ $message }}</p>@enderror
            <x-ui.button type="submit">Validasi Bukti Bayar</x-ui.button>
        </form>
        <form method="post" action="{{ route('orders.payment.proofs.reject', [$order, $proof]) }}" class="stack border-t border-campus-border pt-4">
            @csrf
            <x-ui.textarea name="reason" id="proof-rejection-reason" label="Alasan Penolakan Bukti" rows="3" minlength="5" maxlength="1000" required/>
            <x-ui.button class="self-start" type="submit" variant="danger">Tolak Bukti Bayar</x-ui.button>
        </form>
    </x-ui.card>
@elseif ($order->status === \App\Enums\OrderStatus::AwaitingValidation)
    <x-ui.alert tone="info">Seller sedang memeriksa bukti pembayaran.</x-ui.alert>
@endif
