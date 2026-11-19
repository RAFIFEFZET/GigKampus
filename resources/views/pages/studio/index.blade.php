@extends('layouts.app')
@section('title', 'Studio Seller')
@section('body-class', 'bg-campus-background')

@section('content')
    <div class="shell"><x-domain.studio-nav/></div>
    <section class="orders-shell stack">
        <x-ui.section-header title="Studio Seller" subtitle="Pantau pesanan masuk dan periksa bukti pembayaran."/>
        <dl class="grid gap-4 sm:grid-cols-3">
            <div class="order-metric"><dt class="muted text-sm">Pesanan aktif</dt><dd class="text-2xl font-bold">{{ $active }}</dd></div>
            <div class="order-metric"><dt class="muted text-sm">Perlu validasi bayar</dt><dd><x-ui.badge tone="warning">{{ $queued }} pesanan</x-ui.badge></dd></div>
            <div class="order-metric"><dt class="muted text-sm">Rating Seller</dt><dd class="font-bold">{{ $rating === null ? 'Belum ada ulasan' : '★ '.number_format($rating, 1, ',', '.') }}</dd></div>
        </dl>
        <div class="order-columns">
            <div class="stack">
                <x-ui.section-header level="2" title="Antrean & Validasi"/>
                <livewire:orders.validation-queue/>
            </div>
            <x-ui.card class="stack">
                <x-ui.section-header level="2" title="Katalog Gig"/>
                <x-ui.button variant="secondary" :href="route('studio.gigs.index')">Kelola Katalog Gig</x-ui.button>
                <x-ui.button variant="outline" :href="route('orders.index', ['tab' => 'masuk'])">Lihat Semua Pesanan Masuk</x-ui.button>
            </x-ui.card>
        </div>
    </section>
@endsection
