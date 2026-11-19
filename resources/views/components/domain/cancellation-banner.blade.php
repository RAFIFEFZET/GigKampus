@props(['order', 'isBuyer' => false, 'isSeller' => false])

@php
    $pending = $order->cancellation_status === \App\Enums\CancellationStatus::Pending;
    $participant = $isBuyer || $isSeller;
    $isRequester = $order->cancellation_requested_by_id === ($isBuyer ? $order->buyer_id : $order->seller_id);
@endphp

@if ($order->cancellation_status !== \App\Enums\CancellationStatus::None)
    <x-ui.card class="stack">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2>Pembatalan Pesanan</h2>
            <x-domain.status-badge :status="$order->cancellation_status"/>
        </div>
        <p class="whitespace-pre-wrap">{{ $order->cancellation_reason }}</p>
        @if ($order->cancellation_response_reason)
            <p class="whitespace-pre-wrap text-sm text-campus-muted">{{ $order->cancellation_response_reason }}</p>
        @endif
        @if ($pending)
            <x-ui.alert tone="warning">
                Respons diharapkan sebelum <strong><x-ui.wib-time :date="$order->cancellation_requested_at->addHours(24)"/></strong>.
                @if ($order->isCancellationResponseOverdue()) Tenggat respons sudah lewat. @endif
                @if ($order->status->isPaidWorkStage()) Pengerjaan dan konfirmasi selesai tetap tersedia sesuai tahap. @endif
            </x-ui.alert>
        @endif

        @if ($participant && $pending && $order->status->isPaidWorkStage())
            @if ($isRequester)
                <form method="post" action="{{ route('orders.cancel.withdraw', $order) }}">
                    @csrf
                    <input type="hidden" name="cancellation_version" value="{{ $order->cancellation_version }}">
                    <x-ui.button type="submit" variant="outline">Tarik Pengajuan</x-ui.button>
                </form>
            @else
                <div class="flex flex-wrap gap-2">
                    <x-ui.modal title="Setujui pembatalan dan refund penuh?">
                        <x-slot:trigger><x-ui.button variant="danger">Setujui Pembatalan</x-ui.button></x-slot:trigger>
                        <p>Pesanan akan dibatalkan. Seller wajib mengembalikan seluruh pembayaran.</p>
                        <x-domain.simulation-banner/>
                        <form method="post" action="{{ route('orders.cancel.approve', $order) }}">
                            @csrf
                            <input type="hidden" name="cancellation_version" value="{{ $order->cancellation_version }}">
                            <x-ui.button type="submit" variant="danger">Setujui Pembatalan</x-ui.button>
                        </form>
                    </x-ui.modal>
                </div>
                <form class="stack" method="post" action="{{ route('orders.cancel.reject', $order) }}">
                    @csrf
                    <input type="hidden" name="cancellation_version" value="{{ $order->cancellation_version }}">
                    <x-ui.textarea name="reason" id="cancellation-reject-reason" label="Alasan Penolakan Pembatalan" minlength="5" maxlength="1000" rows="3" required/>
                    <x-ui.button class="self-start" type="submit" variant="outline">Tolak Pembatalan</x-ui.button>
                </form>
            @endif
        @endif
    </x-ui.card>
@endif

@if ($participant && ! $pending && $order->status->isPaidWorkStage())
    <x-ui.card class="stack">
        <h2>Ajukan Pembatalan</h2>
        <form class="stack" method="post" action="{{ route('orders.cancel.request', $order) }}">
            @csrf
            <x-ui.textarea name="reason" id="cancellation-request-reason" label="Alasan Pengajuan" minlength="5" maxlength="1000" rows="3" required/>
            <x-ui.button class="self-start" type="submit" variant="outline">Ajukan Pembatalan</x-ui.button>
        </form>
    </x-ui.card>
@endif

@if ($order->status === \App\Enums\OrderStatus::AwaitingPayment && ($isBuyer || ($isSeller && ! $order->payment->proofs->contains('seller_review_status', \App\Enums\PaymentProofStatus::Rejected))))
    <x-ui.modal title="Batalkan pesanan belum dibayar?">
        <x-slot:trigger><x-ui.button variant="danger">Batalkan Pesanan</x-ui.button></x-slot:trigger>
        <p>Pesanan ini akan ditutup dan percakapan diarsipkan.</p>
        <form method="post" action="{{ route('orders.cancel.unpaid', $order) }}">
            @csrf
            <x-ui.button type="submit" variant="danger">Ya, Batalkan Pesanan</x-ui.button>
        </form>
    </x-ui.modal>
@endif
