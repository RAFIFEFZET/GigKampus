@extends('layouts.app')
@section('title', 'QRIS Pesanan')
@section('body-class', 'bg-campus-background')

@section('content')
    <section class="orders-shell stack">
        <x-domain.checkout-steps :step="2" :back="route('orders.payment.show', $order)" back-label="Kembali ke Pembayaran"/>
        <div class="stack mx-auto w-full max-w-2xl">
            <x-ui.section-header title="QRIS Pesanan" :subtitle="$order->reference().' · '.$order->title_snapshot"/>
            <x-domain.simulation-banner/>
            <x-domain.payment-destination-card :$order :method="\App\Enums\PaymentMethod::Qris" :show-qr="true"/>
        </div>
    </section>
@endsection
