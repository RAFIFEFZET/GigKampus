@extends('layouts.app')
@section('title', 'Katalog Gig')
@section('body-class', 'bg-campus-background')

@section('content')
    <section class="shell page-section stack">
        <x-domain.studio-nav/>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <x-ui.section-header title="Katalog Gig" subtitle="Kelola jasa yang tampil di Eksplorasi Jasa."/>
            @if ($canCreate)
                <x-ui.button :href="route('studio.gigs.create')">+ Publikasikan Gig Baru</x-ui.button>
            @endif
        </div>

        @if (! $canCreate && auth('web')->user()->canCreateNewTransaction())
            <x-ui.alert tone="warning">
                Lengkapi metode pembayaran untuk menerbitkan Gig.
                <a class="font-semibold text-campus-link underline" href="{{ route('studio.payment') }}">Buka Rekening &amp; QRIS</a>
            </x-ui.alert>
        @elseif (! auth('web')->user()->is_verified)
            <x-ui.alert tone="info">
                <a class="font-semibold underline" href="{{ route('ktm.index') }}">Verifikasi KTM</a> untuk menerbitkan Gig.
            </x-ui.alert>
        @endif

        @forelse ($gigs as $gig)
            <x-ui.card class="flex flex-wrap items-center justify-between gap-4">
                <div class="min-w-0 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-domain.status-badge :status="$gig->status"/>
                        <x-ui.badge tone="info">{{ $gig->category->name }}</x-ui.badge>
                    </div>
                    <h2 class="text-lg">{{ $gig->title }}</h2>
                    <p class="text-sm text-campus-muted">Rp{{ number_format($gig->base_price, 0, ',', '.') }} · {{ $gig->delivery_days }} hari · {{ $gig->max_revisions }} revisi</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @can('update', $gig)
                        <x-ui.button :href="route('studio.gigs.edit', $gig)" variant="secondary">Edit Gig</x-ui.button>
                    @endcan
                    @can('toggle', $gig)
                        <form method="post" action="{{ route('studio.gigs.toggle', $gig) }}">
                            @csrf
                            <input type="hidden" name="status" value="{{ $gig->status === \App\Enums\GigStatus::Active ? 'inactive' : 'active' }}">
                            <x-ui.button type="submit" :variant="$gig->status === \App\Enums\GigStatus::Active ? 'outline' : 'primary'">
                                {{ $gig->status === \App\Enums\GigStatus::Active ? 'Nonaktifkan' : 'Aktifkan' }}
                            </x-ui.button>
                        </form>
                    @endcan
                </div>
            </x-ui.card>
        @empty
            <x-ui.empty-state title="Belum ada Gig" description="Gig yang kamu buat akan muncul di sini."/>
        @endforelse
        <x-ui.pagination :items="$gigs"/>
    </section>
@endsection
