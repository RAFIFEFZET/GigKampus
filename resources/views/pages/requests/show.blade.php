@extends('layouts.app')
@section('title', $serviceRequest->title)

@section('content')
    <section class="shell page-section stack">
        <a class="text-sm text-campus-link" href="{{ route('requests.index') }}">← Papan Permintaan</a>
        @if ($conflict)
            <x-ui.alert tone="warning">{{ $conflict }}</x-ui.alert>
        @endif
        <div class="split-detail">
            <div class="stack">
                <x-ui.card class="stack">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-ui.badge tone="info">{{ $serviceRequest->category->name }}</x-ui.badge>
                        <x-domain.status-badge :status="$serviceRequest->status"/>
                    </div>
                    <x-ui.section-header :title="$serviceRequest->title"/>
                    <p class="whitespace-pre-wrap">{{ $serviceRequest->description }}</p>
                    <div class="flex flex-wrap items-center gap-2 text-sm text-campus-muted">
                        <x-ui.avatar :user="$serviceRequest->buyer"/>
                        {{ $serviceRequest->buyer->name }}
                        <x-domain.seller-verified-badge :verified="$serviceRequest->buyer->is_verified"/>
                    </div>
                    <dl class="form-grid">
                        <div><dt class="muted text-sm">Anggaran</dt><dd class="font-semibold">Rp{{ number_format($serviceRequest->budget_min, 0, ',', '.') }} – Rp{{ number_format($serviceRequest->budget_max, 0, ',', '.') }}</dd></div>
                        <div><dt class="muted text-sm">Tenggat (WIB)</dt><dd>{{ $serviceRequest->deadline_date->locale('id')->translatedFormat('j F Y') }}</dd></div>
                    </dl>
                    @if ($canEdit || $canClose)
                        <div class="flex flex-wrap items-center gap-3">
                            @if ($canEdit)
                                <x-ui.button variant="secondary" :href="route('requests.edit', $serviceRequest)">Edit Permintaan</x-ui.button>
                            @endif
                            @if ($canClose)
                                <x-ui.modal title="Tutup permintaan ini?">
                                    <x-slot:trigger><x-ui.button variant="danger">Tutup Permintaan</x-ui.button></x-slot:trigger>
                                    <p>Permintaan ditutup permanen dan seluruh penawaran yang masih menunggu akan ditolak.</p>
                                    <form method="post" action="{{ route('requests.close', $serviceRequest) }}">
                                        @csrf
                                        <x-ui.button variant="danger" type="submit">Ya, Tutup Permintaan</x-ui.button>
                                    </form>
                                </x-ui.modal>
                            @endif
                        </div>
                    @endif
                </x-ui.card>

                @if ($offers->isNotEmpty())
                    <h2>Penawaran Harga</h2>
                    @foreach ($offers as $offer)
                        <x-ui.card class="stack">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="font-semibold">{{ $offer->seller->name }}</p>
                                <x-domain.status-badge :status="$offer->status"/>
                            </div>
                            <p class="text-lg font-bold">Rp{{ number_format($offer->offered_price, 0, ',', '.') }}</p>
                            <p class="whitespace-pre-wrap">{{ $offer->proposal_note }}</p>
                            <p class="text-sm text-campus-muted">Kuota revisi {{ $offer->max_revisions }} kali</p>
                            @if ($offer->status_reason)<p class="text-sm text-campus-muted">{{ $offer->status_reason }}</p>@endif
                            @if (auth('web')->id() === $serviceRequest->buyer_id && $offer->status === \App\Enums\PriceOfferStatus::Pending && $serviceRequest->acceptsOffers())
                                <x-ui.button class="self-start" variant="secondary" :href="route('orders.index')">Terima Penawaran</x-ui.button>
                            @endif
                        </x-ui.card>
                    @endforeach
                @elseif (auth('web')->id() === $serviceRequest->buyer_id)
                    <x-ui.empty-state title="Belum ada penawaran"/>
                @endif
            </div>

            @if ($canOffer || ($ownOffer && auth('web')->user()?->can('update', [$ownOffer, $serviceRequest])))
                <x-ui.card class="stack">
                    <x-ui.section-header level="2" :title="$ownOffer ? 'Edit Penawaran Harga' : 'Ajukan Penawaran Harga'"/>
                    <x-domain.offer-form :$serviceRequest :offer="$ownOffer" :$retained/>
                    @if ($ownOffer)
                        <form method="post" action="{{ route('offers.withdraw', $ownOffer) }}">
                            @csrf
                            <input type="hidden" name="request_version" value="{{ $serviceRequest->version }}">
                            <input type="hidden" name="offer_version" value="{{ $ownOffer->version }}">
                            <x-ui.button type="submit" variant="danger">Tarik Penawaran</x-ui.button>
                        </form>
                    @endif
                </x-ui.card>
            @elseif ($conflict && $retained)
                <x-ui.card class="stack">
                    <x-ui.section-header level="2" title="Draf Penawaran"/>
                    <x-ui.input name="retained_price" label="Nominal Harga Tawar (Rp)" :value="$retained['offered_price'] ?? null" readonly/>
                    <x-ui.input name="retained_revisions" label="Kuota Revisi" :value="$retained['max_revisions'] ?? null" readonly/>
                    <x-ui.textarea name="retained_note" label="Catatan Penawaran" :value="$retained['proposal_note'] ?? null" readonly/>
                </x-ui.card>
            @endif
        </div>
    </section>
@endsection
