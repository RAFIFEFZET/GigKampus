@extends('layouts.app')
@section('title', 'Status berubah')

@section('content')
    <section class="shell page-section stack">
        <x-ui.section-header title="Periksa status terbaru" :subtitle="$exception->getMessage() ?: 'Tindakan ini belum dapat diproses.'"/>
        @if (request()->route('order') instanceof \App\Models\Order)
            <x-ui.button class="self-start" :href="route('orders.show', request()->route('order'))">Kembali ke Detail Pesanan</x-ui.button>
        @else
            <x-ui.button class="self-start" :href="route('home')">Kembali ke Beranda</x-ui.button>
        @endif
    </section>
@endsection
