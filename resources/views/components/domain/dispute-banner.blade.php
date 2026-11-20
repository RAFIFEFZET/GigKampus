@props(['order'])

@if ($order->status === \App\Enums\OrderStatus::Disputed)
    <x-ui.alert tone="warning" title="Pesanan dalam sengketa">
        <p>Tindakan transaksi dijeda sampai Admin memutus kasus. Chat pesanan tetap tersedia.</p>
        @auth('web')
            <a class="mt-2 inline-block font-semibold underline" href="{{ route('reports.index', ['order' => $order->id]) }}">Lihat laporan dan klarifikasi</a>
        @endauth
    </x-ui.alert>
@endif
