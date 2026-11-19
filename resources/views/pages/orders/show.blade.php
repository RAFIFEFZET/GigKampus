@extends('layouts.app')
@section('title', 'Detail Pesanan')
@section('body-class', 'bg-campus-background')

@section('content')
    @if ($isBuyer || $isSeller)
        <div class="shell"><x-domain.order-tabs :tab="$isSeller ? 'masuk' : 'pembelian'"/></div>
    @endif
    <section class="orders-shell stack">
        <x-ui.button class="self-start" variant="outline" :href="auth('admin')->check() ? route('admin.index') : route('orders.index', $isSeller ? ['tab' => 'masuk'] : [])">
            ← {{ auth('admin')->check() ? 'Dashboard Admin' : 'Pesanan Saya' }}
        </x-ui.button>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <x-ui.section-header :title="$order->title_snapshot" :subtitle="$order->reference().' · '.$order->category_name_snapshot"/>
            <x-domain.status-badge :status="$order->status"/>
        </div>
        <x-domain.simulation-banner/>
        @if ($order->status === \App\Enums\OrderStatus::Disputed)
            <x-ui.alert tone="warning" title="Pesanan dalam sengketa">Tindakan transaksi dijeda sampai proses sengketa selesai.</x-ui.alert>
        @endif
        <div class="order-columns">
            <div class="stack">
                <x-ui.card class="stack">
                    <x-ui.section-header level="2" title="Kesepakatan Pesanan"/>
                    <x-domain.order-timeline :$order/>
                    <dl class="form-grid">
                        <div><dt class="muted text-sm">Pembeli</dt><dd>{{ $order->buyer->name }}</dd></div>
                        <div><dt class="muted text-sm">Seller</dt><dd>{{ $order->seller->name }}</dd></div>
                        <div>
                            <dt class="muted text-sm">Tenggat Hasil Pertama</dt>
                            <dd>
                                @if ($order->delivery_deadline_at)
                                    <x-ui.wib-time :date="$order->delivery_deadline_at"/>
                                @elseif ($order->delivery_days_snapshot)
                                    {{ $order->delivery_days_snapshot }} hari setelah validasi bayar
                                @endif
                            </dd>
                        </div>
                        <div><dt class="muted text-sm">Kuota Revisi</dt><dd>{{ $order->revisions->count() }} dari {{ $order->max_revisions_snapshot }} terpakai</dd></div>
                    </dl>
                    <div><h3>Lingkup Pekerjaan</h3><p class="mt-1 whitespace-pre-wrap">{{ $order->scope_description_snapshot }}</p></div>
                    @if ($order->buyer_brief)
                        <div><h3>Brief Pembeli</h3><p class="mt-1 whitespace-pre-wrap">{{ $order->buyer_brief }}</p></div>
                    @endif
                    @if ($order->seller_proposal_snapshot)
                        <div><h3>Proposal Seller</h3><p class="mt-1 whitespace-pre-wrap">{{ $order->seller_proposal_snapshot }}</p></div>
                    @endif
                </x-ui.card>
                @if ($order->deliveries->isNotEmpty())
                    <x-ui.card class="stack">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2>Hasil Pekerjaan</h2>
                            @if ($order->delivery_deadline_at)<x-ui.badge :tone="$isLate ? 'warning' : 'success'">{{ $isLate ? 'Hasil pertama terlambat' : 'Hasil pertama tepat waktu' }}</x-ui.badge>@endif
                        </div>
                        @foreach ($order->deliveries->sortBy('submitted_at') as $delivery)
                            <x-domain.delivery-item :$delivery/>
                        @endforeach
                    </x-ui.card>
                @endif
                @if ($order->revisions->isNotEmpty())
                    <x-ui.card class="stack">
                        <h2>Riwayat Revisi</h2>
                        @foreach ($order->revisions as $revision)
                            <article class="space-y-2 border-t border-campus-border pt-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <h3>Revisi {{ $revision->revision_no }}</h3>
                                    <x-domain.status-badge :status="$revision->status"/>
                                </div>
                                <p class="whitespace-pre-wrap text-sm">{{ $revision->revision_note }}</p>
                                <x-ui.wib-time :date="$revision->requested_at" class="text-xs text-campus-muted"/>
                            </article>
                        @endforeach
                    </x-ui.card>
                @endif
                <x-domain.order-work-actions :$order :$isBuyer :$isSeller/>
                @if ($order->status === \App\Enums\OrderStatus::Completed)
                    @if ($order->review)
                        <x-ui.card class="stack">
                            <x-ui.section-header level="2" title="Ulasan Pesanan"/>
                            @if ($order->review->is_hidden)<x-ui.badge class="self-start">Ulasan disembunyikan di katalog</x-ui.badge>@endif
                            <x-domain.review-card :review="$order->review"/>
                        </x-ui.card>
                    @elseif ($isBuyer)
                        <x-domain.order-review-form :$order/>
                    @endif
                @endif
            </div>
            <div class="stack">
                @if (($isBuyer || $isSeller) && $order->chatRoom)
                    <x-ui.button variant="secondary" :href="route('orders.chat', $order)">Chat Pesanan</x-ui.button>
                @endif
                @if ($payment)
                    <x-domain.payment-destination-card :$order :method="$payment->payment_method"/>
                    <x-ui.button :href="route('orders.payment.show', $order)"
                        :variant="$order->status->isPaymentStage() ? 'primary' : 'secondary'">
                        {{ $order->status->isPaymentStage() ? 'Buka Pembayaran & Bukti' : 'Riwayat Pembayaran' }}
                    </x-ui.button>
                    <x-domain.refund-card :$order :$isBuyer :$isSeller/>
                    <x-domain.cancellation-banner :$order :$isBuyer :$isSeller/>
                @endif
            </div>
        </div>
    </section>
@endsection
