@extends('layouts.app')

@section('content')
    <section class="home-hero">
        <div class="home-intro">
            <span class="hero-eyebrow">Marketplace Jasa Mahasiswa IPB</span>
            <h1>Tugas &amp; proyek kampus, beres.</h1>
            <p class="hidden sm:block">Temukan talenta mahasiswa terverifikasi dengan harga tetap atau posting kebutuhan khusus sesuai anggaranmu.</p>
            <p class="sm:hidden">Talenta terverifikasi. Harga bersahabat.</p>
            <div class="hero-actions">
                <x-ui.button :href="route('catalog.index')">Jelajahi Jasa</x-ui.button>
                <x-ui.button variant="secondary" :href="route('requests.create')">+ Buat Permintaan Jasa</x-ui.button>
            </div>
            <form class="w-full sm:hidden" action="{{ route('catalog.index') }}" role="search">
                <label class="search-pill">
                    <img src="{{ asset('design/search.svg') }}" alt="" width="18" height="18">
                    <span class="sr-only">Cari jasa</span>
                    <input type="search" name="q" placeholder="Cari jasa untuk proyekmu" maxlength="120">
                </label>
            </form>
        </div>
        <div class="home-art">
            <img class="hero-curve" src="{{ asset('design/hero-curve.svg') }}" alt="">
            <div class="home-art-inner">
                <img class="home-photo" src="{{ asset('design/home-students.png') }}" alt="Tiga mahasiswa membawa tablet, laptop, dan kamera" fetchpriority="high">
                @if ($campusRating !== null)
                    <span class="home-sticker home-sticker-rating">★ {{ number_format($campusRating, 1, ',', '.') }} Rating Kampus</span>
                @endif
                <span class="home-sticker home-sticker-verified">✓ Terverifikasi KTM</span>
                <a href="{{ route('catalog.index') }}" class="home-mobile-link">Jelajahi Jasa ↓</a>
            </div>
        </div>
    </section>

    <section class="home-categories bg-campus-background py-6 sm:py-9">
        <div class="shell stack">
            <x-ui.section-header level="2" title="Jelajah Kategori" subtitle="Mulai dari yang kamu butuhkan."/>
            <div class="category-list" aria-label="Kategori jasa">
                @foreach ($categories as $category)
                    <a class="category-tile hover:text-campus-link" href="{{ route('catalog.index', ['category' => $category->id]) }}">
                        <x-domain.category-icon :$category/>
                        {{ $category->name }}
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <section class="shell page-section stack">
        <div class="flex items-center justify-between gap-4">
            <x-ui.section-header level="2" title="Jasa Pilihan Minggu Ini"/>
            <a class="shrink-0 text-sm font-semibold text-campus-link" href="{{ route('catalog.index') }}">Lihat semua ↗</a>
        </div>
        @if ($gigs->isNotEmpty())
            <div class="featured-grid">
                @foreach ($gigs as $gig)<x-domain.gig-card :$gig/>@endforeach
            </div>
        @else
            <x-ui.empty-state title="Belum ada jasa untuk dijelajahi"/>
        @endif
    </section>

    <section class="bg-campus-sky">
        <div class="shell page-section stack">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <x-ui.section-header level="2" title="Papan Permintaan Jasa" subtitle="Temukan kebutuhan yang sesuai keahlianmu."/>
                <a class="text-sm font-semibold text-campus-link" href="{{ route('requests.index') }}">Lihat semua permintaan ↗</a>
            </div>
            <div class="grid gap-4 md:grid-cols-3">
                @forelse ($requests as $serviceRequest)
                    <x-domain.request-card :request="$serviceRequest"/>
                @empty
                    <x-ui.empty-state title="Belum ada permintaan terbuka" class="md:col-span-3">
                        <x-ui.button :href="route('requests.create')">Buat Permintaan Jasa</x-ui.button>
                    </x-ui.empty-state>
                @endforelse
            </div>
        </div>
    </section>
@endsection
