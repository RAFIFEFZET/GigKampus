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
        <x-ui.section-header level="2" title="Validasi Pembayaran" subtitle="Cocokkan bukti dengan mutasi dan tujuan pembayaran pesanan."/>
        <div class="order-info flex min-w-0 flex-wrap items-center gap-4">
            <img src="{{ route('files.show', ['jenis' => 'payment_proof', 'id' => $proof->id]) }}"
                alt="Pratinjau bukti pembayaran percobaan {{ $proof->attempt_no }}" class="size-28 rounded-lg bg-white object-contain p-1">
            <div class="min-w-0 space-y-1">
                <p class="font-semibold">{{ $proof->original_filename }}</p>
                <p class="text-sm text-campus-muted">Percobaan {{ $proof->attempt_no }}</p>
                <x-domain.status-badge :status="$proof->seller_review_status"/>
            </div>
        </div>
        <form method="post" action="{{ route('orders.payment.proofs.verify', [$order, $proof]) }}" class="stack"
            x-data="{ amount: '' }"
            x-on:submit="if ($event.submitter !== $refs.confirm) {
                $event.preventDefault();
                amount = $el.querySelector('[data-currency-display]').value;
                $refs.verificationDialog.showModal();
            }">
            @csrf
            <x-ui.currency-input name="amount" label="Nominal yang Diterima (Rp)" min="1" max="100000000" required/>
            <label class="flex items-start gap-2 text-sm">
                <input name="confirmed_recipient" value="1" type="checkbox" class="mt-1 accent-campus-blue" @checked(old('confirmed_recipient')) required>
                <span>Nominal dan nama penerima sesuai dengan tujuan pembayaran.</span>
            </label>
            @error('confirmed_recipient')<p class="field-error">{{ $message }}</p>@enderror
            <x-ui.button class="self-start" type="submit">Validasi Pembayaran</x-ui.button>
            <dialog x-ref="verificationDialog" class="dialog" aria-label="Konfirmasi Validasi Pembayaran">
                <div class="stack">
                    <h2>Konfirmasi Validasi Pembayaran</h2>
                    <dl class="order-info space-y-2 text-sm">
                        <div><dt class="text-campus-muted">Nominal diterima</dt><dd class="font-bold">Rp <span x-text="amount"></span></dd></div>
                        <div><dt class="text-campus-muted">Bukti percobaan {{ $proof->attempt_no }}</dt><dd class="font-semibold">{{ $proof->original_filename }}</dd></div>
                    </dl>
                    <p class="text-sm">Setelah divalidasi, pesanan masuk tahap pengerjaan.</p>
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button variant="outline" x-on:click="$refs.verificationDialog.close()" autofocus>Batal</x-ui.button>
                        <x-ui.button type="submit" x-ref="confirm">Ya, Validasi Pembayaran</x-ui.button>
                    </div>
                </div>
            </dialog>
        </form>
        <div class="border-t border-campus-border pt-4">
            <x-ui.modal title="Tolak Bukti Pembayaran" :open="$errors->has('reason')">
                <x-slot:trigger><x-ui.button variant="danger-outline" aria-haspopup="dialog">Tolak Bukti Pembayaran</x-ui.button></x-slot:trigger>
                <p class="text-sm">Pemesan akan melihat alasan ini dan dapat mengirim bukti pengganti.</p>
                <form method="post" action="{{ route('orders.payment.proofs.reject', [$order, $proof]) }}" class="stack">
                    @csrf
                    <x-ui.textarea name="reason" id="proof-rejection-reason" label="Alasan Penolakan Bukti" rows="3" minlength="5" maxlength="1000" required/>
                    <x-ui.button class="self-start" type="submit" variant="danger">Ya, Tolak Bukti</x-ui.button>
                </form>
            </x-ui.modal>
        </div>
    </x-ui.card>
@elseif ($order->status === \App\Enums\OrderStatus::AwaitingValidation)
    <x-ui.alert tone="info">Seller sedang memeriksa bukti pembayaran.</x-ui.alert>
@endif
