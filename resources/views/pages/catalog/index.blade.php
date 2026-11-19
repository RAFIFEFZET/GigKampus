@extends('layouts.app')
@section('title', 'Eksplorasi Jasa')

@section('content')
    @php
        $heading = $categories->firstWhere('id', $filters['category'])?->name ?? 'Eksplorasi Jasa';
        $applied = (int) filled($filters['price']) + (int) filled($filters['duration']);
    @endphp

    <section class="shell page-section stack">
        <div class="market-hero hidden sm:grid">
            <div class="market-copy">
                <span class="hero-eyebrow inline-block">Katalog Talenta IPB</span>
                <x-ui.section-header :title="$heading" :subtitle="$gigs->total().' layanan berharga tetap.'"/>
            </div>
            <img class="market-art" src="{{ asset('design/catalog-student.png') }}" alt="">
        </div>

        <div x-data="{ search: @js($filters['q']) }" class="sm:hidden">
            <form action="{{ route('catalog.index') }}" class="flex min-w-0 items-center gap-2" aria-label="Pencarian jasa seluler">
                <input type="hidden" name="category" value="{{ $filters['category'] }}">
                <input type="hidden" name="price" value="{{ $filters['price'] }}">
                <input type="hidden" name="duration" value="{{ $filters['duration'] }}">
                <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
                <label class="search-pill min-w-0 flex-1">
                    <span class="sr-only">Cari jasa</span>
                    <img src="{{ asset('design/search.svg') }}" alt="" width="18" height="18">
                    <input type="search" name="q" x-model="search" value="{{ $filters['q'] }}" placeholder="Cari jasa…" maxlength="120">
                </label>
                <x-ui.button variant="secondary" x-on:click="$refs.filters.showModal()">Filter{{ $applied ? ' ('.$applied.')' : '' }}</x-ui.button>
            </form>
            <dialog x-ref="filters" class="catalog-filter-sheet" aria-labelledby="filter-jasa-title">
                <div class="mb-5 flex items-center justify-between gap-3">
                    <h2 id="filter-jasa-title">Filter &amp; Urutkan Jasa</h2>
                    <x-ui.button variant="outline" x-on:click="$refs.filters.close()" aria-label="Tutup filter">×</x-ui.button>
                </div>
                <form action="{{ route('catalog.index') }}" class="filter-bar" aria-label="Filter jasa seluler">
                    <input type="hidden" name="q" x-bind:value="search" value="{{ $filters['q'] }}">
                    <input type="hidden" name="category" value="{{ $filters['category'] }}">
                    <x-domain.catalog-filter-fields :$filters prefix="mobile"/>
                    <x-ui.button type="submit">Terapkan Filter</x-ui.button>
                </form>
            </dialog>
        </div>

        <nav class="category-chips" aria-label="Kategori jasa">
            @foreach (['' => 'Semua'] + $categories->pluck('name', 'id')->all() as $id => $name)
                @php($selected = (string) $id === (string) $filters['category'])
                <x-ui.button
                    :href="route('catalog.index', array_filter([...$filters, 'category' => $id]))"
                    :variant="$selected ? 'primary' : 'outline'"
                    :aria-current="$selected ? 'page' : null"
                >{{ $name }}</x-ui.button>
            @endforeach
        </nav>
        <x-ui.section-header :title="$heading" :subtitle="$gigs->total().' layanan berharga tetap.'" class="sm:hidden"/>

        <form action="{{ route('catalog.index') }}" class="filter-bar catalog-filters" aria-label="Filter jasa desktop">
            <input type="hidden" name="category" value="{{ $filters['category'] }}">
            <x-ui.input name="q" id="desktop-q" label="Cari jasa" type="search" :value="$filters['q']" placeholder="Judul, deskripsi, Seller" maxlength="120"/>
            <x-domain.catalog-filter-fields :$filters prefix="desktop"/>
            <x-ui.button type="submit">Terapkan</x-ui.button>
        </form>

        @if ($gigs->count())
            <div class="featured-grid">
                @foreach ($gigs as $gig)<x-domain.gig-card :$gig/>@endforeach
            </div>
            <x-ui.pagination :items="$gigs"/>
        @else
            <x-ui.empty-state title="Tidak ada jasa yang sesuai" description="Coba kata kunci atau filter lain.">
                <x-ui.button :href="route('catalog.index')" variant="secondary">Reset filter</x-ui.button>
            </x-ui.empty-state>
        @endif
    </section>
@endsection
