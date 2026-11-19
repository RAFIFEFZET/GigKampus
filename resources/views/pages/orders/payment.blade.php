@extends('layouts.app')
@section('title', 'Pembayaran Pesanan')
@section('body-class', 'bg-campus-background')

@section('content')
    @if ($isBuyer || $isSeller)
        <div class="shell"><x-domain.order-tabs :tab="$isSeller ? 'masuk' : 'pembelian'"/></div>
    @endif
    <section class="orders-shell stack">
        <x-domain.checkout-steps :step="2" :back="route('orders.show', $order)" back-label="Kembali ke Detail Pesanan"/>
        <x-ui.section-header title="Pembayaran Pesanan" :subtitle="$order->reference().' · '.$order->title_snapshot">
            <x-domain.status-badge :status="$order->status"/>
        </x-ui.section-header>
        <x-domain.simulation-banner/>
        @if ($payment)
            <div class="payment-columns">
                <div class="stack">
                    <x-domain.payment-destination-card :$order :method="$payment->payment_method" :show-qr="$payment->payment_method === \App\Enums\PaymentMethod::Qris"/>
                    <x-domain.refund-card :$order :$isBuyer :$isSeller/>
                </div>
                <div class="stack">
                    <x-domain.payment-actions :$order :$payment :$isBuyer :$isSeller/>
                    <x-domain.payment-proof-history :$payment/>
                </div>
            </div>
        @endif
    </section>
@endsection
