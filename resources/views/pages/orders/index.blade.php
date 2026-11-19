@extends('layouts.app')
@section('title', 'Pesanan Saya')
@section('body-class', 'bg-campus-background')

@section('content')
    <div class="shell"><x-domain.order-tabs :$tab/></div>
    <section class="orders-shell stack">
        <x-ui.section-header title="Pesanan Saya" subtitle="Pantau pembayaran, progres, dan hasil pekerjaan."/>
        <nav class="order-filters" aria-label="Filter status pesanan">
            <x-ui.button :href="route('orders.index', ['tab' => $tab])" :variant="$status === null ? 'primary' : 'outline'"
                :aria-current="$status === null ? 'page' : null">Semua</x-ui.button>
            @foreach (\App\Enums\OrderStatus::cases() as $filter)
                @continue($tab === 'riwayat' && ! in_array($filter, [\App\Enums\OrderStatus::Cancelled, \App\Enums\OrderStatus::Completed], true))
                <x-ui.button :href="route('orders.index', ['tab' => $tab, 'status' => $filter->value])"
                    :variant="$status === $filter ? 'primary' : 'outline'" :aria-current="$status === $filter ? 'page' : null">{{ $filter->label() }}</x-ui.button>
            @endforeach
        </nav>

        @forelse ($orders as $order)
            <x-domain.order-card :$order :$student/>
        @empty
            <x-ui.empty-state title="Belum ada pesanan" description="Pesanan dengan status yang kamu pilih akan muncul di sini.">
                <x-ui.button variant="secondary" :href="route('catalog.index')">Jelajahi Jasa</x-ui.button>
            </x-ui.empty-state>
        @endforelse
        <x-ui.pagination :items="$orders"/>
    </section>
@endsection
