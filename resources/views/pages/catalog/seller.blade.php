@extends('layouts.app')
@section('title', $seller->name)

@section('content')
    <section class="shell page-section stack">
        <a class="text-sm text-campus-link" href="{{ route('catalog.index') }}">← Eksplorasi Jasa</a>
        <x-ui.card class="stack">
            <div class="flex items-center gap-4">
                <x-ui.avatar :user="$seller" class="size-16 text-xl"/>
                <x-ui.section-header :title="$seller->name">
                    <x-ui.badge tone="info">✓ KTM Terverifikasi</x-ui.badge>
                </x-ui.section-header>
            </div>
            @if ($seller->bio)<p class="whitespace-pre-wrap text-campus-muted">{{ $seller->bio }}</p>@endif
        </x-ui.card>

        @if ($gigs->isNotEmpty())
            <h2>Jasa dari Seller</h2>
            <div class="featured-grid">
                @foreach ($gigs as $gig)<x-domain.gig-card :$gig/>@endforeach
            </div>
        @endif

        <x-ui.card class="stack">
            <x-ui.section-header level="2" title="Ulasan Pembeli"
                :subtitle="$rating === null ? 'Belum ada ulasan' : '★ '.number_format($rating, 1, ',', '.').' dari '.$reviews->total().' ulasan'"/>
            @foreach ($reviews as $review)<x-domain.review-card :$review/>@endforeach
            <x-ui.pagination :items="$reviews"/>
        </x-ui.card>
    </section>
@endsection
