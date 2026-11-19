@props(['order', 'isBuyer' => false, 'isSeller' => false])

@php($payment = $order->payment)
@if ($payment && $payment->refund_status !== \App\Enums\RefundStatus::None)
    <x-ui.card class="stack">
        <x-ui.section-header level="2" title="Refund Penuh"/>
        <x-domain.simulation-banner/>
        <div class="order-refund">
            <p class="text-sm">{{ $payment->refund_status === \App\Enums\RefundStatus::Confirmed ? 'Refund diterima' : 'Refund menunggu konfirmasi' }}</p>
            <p class="text-2xl font-bold">Rp{{ number_format($payment->amount, 0, ',', '.') }}</p>
            <p class="text-sm">100% dari pembayaran terverifikasi</p>
        </div>
        @if ($payment->refund_confirmed_at)
            <p class="text-sm">Dikonfirmasi <x-ui.wib-time :date="$payment->refund_confirmed_at"/></p>
        @elseif ($order->status === \App\Enums\OrderStatus::Cancelled)
            @if ($isBuyer)
                <form class="stack" method="post" action="{{ route('orders.refund.confirm', $order) }}">
                    @csrf
                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" name="confirmed" value="1" class="mt-1 accent-campus-blue" required>
                        <span>Saya mengonfirmasi seluruh dana refund telah diterima.</span>
                    </label>
                    @error('confirmed')<p class="field-error">{{ $message }}</p>@enderror
                    <x-ui.button class="self-start" type="submit">Konfirmasi Dana Refund Diterima</x-ui.button>
                </form>
            @endif
            @if ($isSeller)
                <x-ui.button class="self-start" variant="secondary" :href="route('orders.chat', $order)">Buka Chat untuk Bukti Refund</x-ui.button>
            @endif
        @endif
    </x-ui.card>
@endif
