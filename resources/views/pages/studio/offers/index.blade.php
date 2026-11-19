@extends('layouts.app')
@section('title', 'Riwayat Penawaran')
@section('body-class', 'bg-campus-background')

@section('content')
    <section class="shell page-section stack">
        <x-domain.studio-nav/>
        <x-ui.section-header title="Riwayat Penawaran" subtitle="Lihat status dan rincian penawaran yang kamu kirim."/>
        @forelse ($offers as $offer)
            <x-ui.card class="stack">
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge tone="info">{{ $offer->serviceRequest->category->name }}</x-ui.badge>
                    <x-domain.status-badge :status="$offer->status"/>
                </div>
                <h2 class="text-lg">{{ $offer->serviceRequest->title }}</h2>
                <p class="text-lg font-semibold">Rp{{ number_format($offer->offered_price, 0, ',', '.') }}</p>
                <p class="whitespace-pre-wrap text-sm">{{ $offer->proposal_note }}</p>
                @if ($offer->status_reason)<p class="text-sm text-campus-muted">{{ $offer->status_reason }}</p>@endif
                <x-ui.button class="self-start" variant="secondary" :href="route('requests.show', $offer->serviceRequest)">
                    @can('update', [$offer, $offer->serviceRequest])
                        Kelola Penawaran
                    @else
                        Lihat Penawaran
                    @endcan
                </x-ui.button>
                <x-domain.offer-chat-button :$offer :service-request="$offer->serviceRequest"/>
            </x-ui.card>
        @empty
            <x-ui.empty-state title="Belum ada penawaran">
                <x-ui.button :href="route('requests.index')">Buka Papan Permintaan</x-ui.button>
            </x-ui.empty-state>
        @endforelse
        <x-ui.pagination :items="$offers"/>
    </section>
@endsection
