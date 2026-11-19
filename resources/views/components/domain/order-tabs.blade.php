@props(['tab' => null])

<nav class="order-tabs" aria-label="Navigasi pesanan">
    @foreach (['pembelian' => 'Pembelian Saya', 'masuk' => 'Pesanan Masuk', 'riwayat' => 'Riwayat'] as $key => $label)
        <a href="{{ route('orders.index', $key === 'pembelian' ? [] : ['tab' => $key]) }}" @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
