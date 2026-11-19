@extends('layouts.app')
@section('title', 'Pemesanan & Pembayaran')
@section('body-class', 'bg-campus-background')

@section('content')
    @php
        $title = $gig?->title ?? $serviceRequest->title;
        $price = $gig?->base_price ?? $offer->offered_price;
        $back = $gig ? route('catalog.show', $gig) : route('requests.show', $serviceRequest);
        $selected = old('payment_method', $selectedMethod);
    @endphp
    <div class="shell"><x-domain.order-tabs/></div>
    <section class="orders-shell stack">
        <x-domain.checkout-steps :step="1" :$back :back-label="$gig ? 'Kembali ke Detail Jasa' : 'Kembali ke Permintaan'"/>
        <x-ui.section-header title="Pemesanan & Pembayaran" :subtitle="$gig ? 'Lengkapi brief, lalu pilih metode bayar yang tersedia.' : 'Tinjau penawaran Seller dan pilih metode bayar yang tersedia.'"/>
        <x-domain.simulation-banner/>
        @if ($conflict)
            <x-ui.alert tone="warning" title="Tinjau kembali pesanan">{{ $conflict }}</x-ui.alert>
        @endif
        @if ($errors->hasAny(['creation_key', 'gig_version', 'request_version', 'offer_version']))
            <x-ui.alert tone="danger">Sesi checkout perlu dimuat ulang. Periksa kembali rincian pesanan.</x-ui.alert>
        @endif

        <form method="post" action="{{ $gig ? route('checkout.gig.store', $gig) : route('checkout.offer.store', $offer) }}" class="order-columns">
            @csrf
            <input type="hidden" name="creation_key" value="{{ is_string(old('creation_key')) && ! $conflict ? old('creation_key') : $creationKey }}">
            @if ($gig)
                <input type="hidden" name="gig_version" value="{{ $gig->version }}">
            @else
                <input type="hidden" name="request_version" value="{{ $serviceRequest->version }}">
                <input type="hidden" name="offer_version" value="{{ $offer->version }}">
            @endif
            <div class="stack">
                <x-ui.card class="stack">
                    @if ($gig)
                        <x-ui.section-header level="2" title="Catatan Pengerjaan untuk Seller"/>
                        <x-ui.textarea name="buyer_brief" label="Brief Pengerjaan" :value="$brief" rows="6" minlength="20"
                            maxlength="5000" placeholder="Jelaskan kebutuhan, bahan, dan hasil yang diharapkan." :readonly="! $canSubmit" required/>
                    @else
                        <x-ui.section-header level="2" title="Ringkasan Penawaran"/>
                        <div><h3>Brief Permintaan</h3><p class="mt-1 whitespace-pre-wrap">{{ $serviceRequest->description }}</p></div>
                        <div><h3>Proposal Seller</h3><p class="mt-1 whitespace-pre-wrap">{{ $offer->proposal_note }}</p></div>
                        <p class="text-sm text-campus-muted">Tenggat <x-ui.wib-time :date="$serviceRequest->deadline_date" :with-time="false"/> · {{ $offer->max_revisions }} revisi</p>
                    @endif
                </x-ui.card>
                <x-ui.card>
                    <fieldset class="stack" @disabled(! $canSubmit)>
                        <legend class="mb-3 text-xl font-bold">Pilih Metode Pembayaran</legend>
                        @forelse ($methods as $method)
                            <label class="checkout-method">
                                <input type="radio" name="payment_method" value="{{ $method->value }}" @checked($selected === $method->value) required>
                                <img src="{{ asset($method === \App\Enums\PaymentMethod::Qris ? 'design/order-qris.svg' : 'design/order-bank.svg') }}" alt="">
                                <span class="font-semibold">{{ $method === \App\Enums\PaymentMethod::Qris ? 'QRIS Seller' : 'Transfer Rekening Bank Seller' }}</span>
                            </label>
                        @empty
                            <p class="text-sm text-campus-muted">Metode pembayaran tidak tersedia untuk pesanan ini.</p>
                        @endforelse
                        @error('payment_method')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </fieldset>
                </x-ui.card>
            </div>
            <x-ui.card class="stack">
                <x-ui.section-header level="2" title="Ringkasan Pesanan"/>
                <div class="flex items-center gap-3">
                    @if ($gig && in_array(strtolower(pathinfo($gig->portfolio_path ?? '', PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true))
                        <img src="{{ Storage::disk('public')->url($gig->portfolio_path) }}" alt="" class="size-20 shrink-0 rounded-xl object-cover">
                    @endif
                    <h3>{{ $title }}</h3>
                </div>
                <div class="flex items-center gap-2 text-sm">
                    <x-ui.avatar :user="$seller"/>
                    {{ $seller->name }}
                    <x-domain.seller-verified-badge :verified="$seller->is_verified"/>
                </div>
                @if ($gig)
                    <div class="space-y-2 rounded-xl bg-campus-background p-4 text-sm">
                        <h3>Lingkup Jasa</h3>
                        <p class="whitespace-pre-wrap">{{ $gig->description }}</p>
                        <p class="font-medium">{{ $gig->delivery_days }} hari pengerjaan · {{ $gig->max_revisions }} revisi</p>
                    </div>
                @endif
                <dl class="flex flex-wrap items-center justify-between gap-2 border-t border-campus-border pt-4">
                    <dt class="font-semibold">Total Bayar</dt>
                    <dd class="text-2xl font-bold text-campus-link">Rp{{ number_format($price, 0, ',', '.') }}</dd>
                </dl>
                <x-ui.button type="submit" :disabled="! $canSubmit || ! count($methods)">Buat Pesanan</x-ui.button>
            </x-ui.card>
        </form>
    </section>
@endsection
