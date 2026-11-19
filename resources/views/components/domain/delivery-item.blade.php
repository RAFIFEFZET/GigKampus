@props(['delivery'])

<article class="order-info stack">
    <div class="flex min-w-0 flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
            <img src="{{ asset('design/order-delivery.svg') }}" alt="">
            <div class="min-w-0">
                <h3 class="font-semibold">{{ $delivery->revision ? 'Hasil Revisi '.$delivery->revision->revision_no : 'Hasil Pertama' }}</h3>
                <x-ui.wib-time :date="$delivery->submitted_at" class="text-xs text-campus-muted"/>
            </div>
        </div>
        @if ($delivery->file_path)
            <x-ui.button variant="outline" :href="route('files.show', ['jenis' => 'order_delivery', 'id' => $delivery->id])">Unduh Hasil</x-ui.button>
        @elseif (str_starts_with(strtolower($delivery->result_url ?? ''), 'https://'))
            <x-ui.button variant="outline" :href="$delivery->result_url" target="_blank" rel="noopener noreferrer">Buka Tautan Hasil ↗</x-ui.button>
        @endif
    </div>
    @if ($delivery->original_filename)<p class="text-sm">{{ $delivery->original_filename }}</p>@endif
    @if ($delivery->delivery_note)<p class="whitespace-pre-wrap text-sm">{{ $delivery->delivery_note }}</p>@endif
</article>
