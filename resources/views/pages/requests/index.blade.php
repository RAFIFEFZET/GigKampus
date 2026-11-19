@extends('layouts.app')
@section('title', 'Papan Permintaan Jasa')

@section('content')
    <section class="shell page-section stack">
        <div class="market-hero">
            <div class="market-copy">
                <span class="hero-eyebrow inline-block">Kebutuhan Spesifik</span>
                <x-ui.section-header title="Papan Permintaan Jasa" subtitle="Posting brief kebutuhanmu dan temukan penawaran dari rekan mahasiswa."/>
                @unless ($canPublishRequest)
                    <x-ui.button variant="yellow" :href="auth('web')->check() ? route('ktm.index') : route('login')">+ Buat Permintaan</x-ui.button>
                @else
                    <x-ui.button variant="yellow" :href="route('requests.create')" class="lg:hidden">+ Buat Permintaan</x-ui.button>
                @endunless
            </div>
            <img class="market-art" src="{{ asset('design/requests-student.png') }}" alt="">
        </div>
        <div @class(['request-grid' => $canPublishRequest])>
            <div class="stack">
                <x-ui.section-header level="2" title="Permintaan Terbaru"/>
                <form action="{{ route('requests.index') }}" aria-label="Filter permintaan" class="filter-bar request-filter">
                    <x-ui.input name="q" label="Cari permintaan" type="search" :value="$filters['q'] ?? ''" placeholder="Judul atau brief" maxlength="120"/>
                    <x-ui.select name="category" label="Kategori" :value="$filters['category'] ?? ''" :options="['' => 'Semua kategori'] + $categories->pluck('name', 'id')->all()"/>
                    <x-ui.button type="submit">Cari</x-ui.button>
                </form>
                @forelse ($requests as $serviceRequest)
                    <x-domain.request-card :request="$serviceRequest"/>
                @empty
                    <x-ui.empty-state title="Belum ada permintaan yang sesuai">
                        <x-ui.button :href="route('requests.index')" variant="secondary">Reset filter</x-ui.button>
                    </x-ui.empty-state>
                @endforelse
                <x-ui.pagination :items="$requests"/>
            </div>
            @if ($canPublishRequest)
                <x-ui.card class="hidden stack lg:flex">
                    <x-ui.section-header level="2" title="Buat Permintaan Jasa Baru"/>
                    <form method="post" action="{{ route('requests.store') }}" class="stack">
                        @csrf
                        <x-domain.request-fields :$categories/>
                        <x-ui.button type="submit">Publikasikan</x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        </div>
    </section>
@endsection
