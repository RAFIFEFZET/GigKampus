@props(['order', 'student', 'timeline' => true, 'validation' => false])

@php($other = $order->hasBuyer($student) ? $order->seller : $order->buyer)

<x-ui.card class="stack">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 space-y-2">
            <p class="text-xs font-medium text-campus-muted">{{ $order->reference() }}</p>
            <h2 class="text-xl"><a class="hover:text-campus-link" href="{{ route('orders.show', $order) }}">{{ $order->title_snapshot }}</a></h2>
            <div class="flex flex-wrap items-center gap-2 text-sm text-campus-muted">
                <x-ui.avatar :user="$other" class="size-[22px]"/>
                {{ $other->name }}
                <x-domain.seller-verified-badge :verified="$other->is_verified"/>
            </div>
        </div>
        <div class="space-y-2 sm:text-right">
            <p class="text-xl font-bold">Rp{{ number_format($order->total_price, 0, ',', '.') }}</p>
            <x-domain.status-badge :status="$order->status"/>
            @if ($order->payment?->refund_status === \App\Enums\RefundStatus::Pending)
                <x-ui.badge tone="warning">Refund menunggu</x-ui.badge>
            @endif
        </div>
    </div>
    @if ($timeline)<x-domain.order-timeline :$order/>@endif
    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-ui.wib-time :date="$order->order_date" :with-time="false" class="text-sm text-campus-muted"/>
        <x-ui.button :variant="$validation ? 'primary' : 'secondary'" :href="route($validation ? 'orders.payment.show' : 'orders.show', $order)">
            {{ $validation ? 'Periksa Bukti Bayar' : 'Detail Pesanan' }}
        </x-ui.button>
    </div>
</x-ui.card>
