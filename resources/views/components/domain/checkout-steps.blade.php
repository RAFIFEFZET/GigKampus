@props(['step', 'back', 'backLabel'])

<nav class="checkout-steps" aria-label="Tahap pemesanan">
    <x-ui.button variant="outline" :href="$back">← {{ $backLabel }}</x-ui.button>
    @foreach ([1 => 'Catatan & Metode Bayar', 2 => 'Pembayaran & Bukti', 3 => 'Pekerjaan'] as $index => $label)
        <span @if ($step === $index) aria-current="step" @endif>{{ $index }}. {{ $label }}</span>
    @endforeach
</nav>
