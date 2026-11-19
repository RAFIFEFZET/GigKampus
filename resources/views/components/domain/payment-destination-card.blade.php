@props(['order', 'method', 'showQr' => false])

<x-ui.card class="stack">
    <div class="flex items-center gap-3">
        <x-ui.avatar :user="$order->seller"/>
        <div><p class="font-semibold">{{ $order->seller->name }}</p><p class="text-xs text-campus-muted">Seller</p></div>
    </div>
    <div class="order-info">
        <p class="text-sm text-campus-muted">Total pembayaran</p>
        <p class="mt-1 text-3xl font-bold text-campus-link">Rp{{ number_format($order->total_price, 0, ',', '.') }}</p>
    </div>

    @if ($method === \App\Enums\PaymentMethod::BankTransfer)
        <dl class="stack">
            <div><dt class="muted text-sm">Bank tujuan</dt><dd class="font-semibold">{{ $order->bank_name_snapshot }}</dd></div>
            <div><dt class="muted text-sm">Nomor rekening</dt><dd class="break-all font-semibold">{{ $order->bank_account_number_snapshot }}</dd></div>
            <div><dt class="muted text-sm">Nama penerima</dt><dd class="font-semibold">{{ $order->bank_account_holder_name_snapshot }}</dd></div>
        </dl>
    @elseif ($method === \App\Enums\PaymentMethod::Qris)
        <div>
            <p class="text-sm text-campus-muted">Nama merchant QRIS</p>
            <p class="font-semibold">{{ $order->qris_holder_name_snapshot }}</p>
        </div>
        @if ($showQr)
            <div class="grid justify-items-center gap-3 rounded-xl border border-campus-border bg-white p-4 text-center">
                <h2>QRIS</h2>
                <img src="{{ route('files.show', ['jenis' => 'order_qris_snapshot', 'id' => $order->id]) }}"
                    alt="QRIS snapshot atas nama {{ $order->qris_holder_name_snapshot }}" class="aspect-square w-full max-w-56 object-contain">
                <x-ui.button variant="outline" :href="route('files.show', ['jenis' => 'order_qris_snapshot', 'id' => $order->id, 'unduh' => 1])">Unduh QRIS</x-ui.button>
            </div>
        @else
            <x-ui.button variant="secondary" :href="route('orders.payment.qris', $order)">Lihat QRIS</x-ui.button>
        @endif
    @endif
</x-ui.card>
