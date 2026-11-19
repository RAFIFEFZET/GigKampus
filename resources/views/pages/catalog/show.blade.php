@extends('layouts.app')
@section('title', $gig->title)

@section('content')
    <section class="shell page-section stack">
        <a class="text-sm font-medium text-campus-link" href="{{ route('catalog.index') }}">← Eksplorasi Jasa</a>
        <div class="split-detail">
            <div class="stack">
                <x-ui.section-header :title="$gig->title">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <x-ui.avatar :user="$gig->seller"/>
                        <span>{{ $gig->seller->name }}</span>
                        <x-domain.seller-verified-badge :verified="$gig->seller->is_verified"/>
                        <x-ui.badge tone="info">{{ $gig->category->name }}</x-ui.badge>
                    </div>
                </x-ui.section-header>
                <div class="gig-detail-art">
                    @if (in_array(strtolower(pathinfo($gig->portfolio_path ?? '', PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true))
                        <img src="{{ Storage::disk('public')->url($gig->portfolio_path) }}" alt="Portofolio {{ $gig->title }}">
                    @else
                        <x-domain.category-icon :category="$gig->category" class="gig-fallback"/>
                    @endif
                </div>
                @if (strtolower(pathinfo($gig->portfolio_path ?? '', PATHINFO_EXTENSION)) === 'pdf')
                    <x-ui.button :href="route('catalog.portfolio', $gig)" variant="secondary" class="self-start">Unduh Portofolio PDF</x-ui.button>
                @endif
                <div>
                    <h2>Tentang Jasa Ini</h2>
                    <p class="mt-2 whitespace-pre-wrap text-campus-muted">{{ $gig->description }}</p>
                </div>
                <x-ui.card class="stack">
                    <x-ui.section-header level="2" title="Tentang Seller" :subtitle="$gig->seller->bio"/>
                    @if ($gig->seller_rating !== null)
                        <p class="text-sm font-semibold">★ {{ number_format($gig->seller_rating, 1, ',', '.') }} rating Seller</p>
                    @else
                        <p class="text-sm text-campus-muted">Belum ada ulasan</p>
                    @endif
                    @foreach ($reviews as $review)
                        <div class="border-t border-campus-border pt-3">
                            <span class="text-sm font-semibold">★ {{ $review->rating }}</span>
                            <p class="whitespace-pre-wrap text-sm">{{ $review->comment }}</p>
                        </div>
                    @endforeach
                </x-ui.card>
            </div>
            <x-ui.card class="stack">
                <div>
                    <p class="text-sm text-campus-muted">Harga tetap per pesanan</p>
                    <p class="text-3xl font-bold">Rp{{ number_format($gig->base_price, 0, ',', '.') }}</p>
                </div>
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="muted">Pengerjaan</dt><dd class="font-semibold">{{ $gig->delivery_days }} hari</dd></div>
                    <div><dt class="muted">Kuota revisi</dt><dd class="font-semibold">{{ $gig->max_revisions }} kali</dd></div>
                </dl>
                @if ($canOrder)
                    <x-ui.button :href="route('orders.index')">Pesan Sekarang</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('chat.index')">Tanya Penjual</x-ui.button>
                @elseif (! auth('web')->check() && ! auth('admin')->check())
                    <x-ui.button :href="route('login')">Masuk untuk memesan</x-ui.button>
                @elseif (auth('web')->check() && ! auth('web')->user()->is_verified)
                    <x-ui.button :href="route('ktm.index')" variant="secondary">Verifikasi KTM</x-ui.button>
                @endif
                <x-ui.alert tone="warning">Simulasi demo. Jangan melakukan transfer uang sungguhan.</x-ui.alert>
            </x-ui.card>
        </div>
    </section>
@endsection
