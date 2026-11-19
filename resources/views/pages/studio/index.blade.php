@extends('layouts.app')
@section('title', 'Studio Seller')
@section('body-class', 'bg-campus-background')
@section('content')
    <section class="shell page-section stack">
        <x-domain.studio-nav/>
        <x-ui.section-header title="Studio Seller" subtitle="Kelola jasa, penawaran, dan metode pembayaran dalam satu akun."/>
        <x-ui.empty-state title="Antrean & Validasi" description="Antrean pesanan dan validasi pembayaran tersedia pada fase berikutnya.">
            <x-ui.button :href="route('studio.gigs.index')">Buka Katalog Gig</x-ui.button>
        </x-ui.empty-state>
    </section>
@endsection
