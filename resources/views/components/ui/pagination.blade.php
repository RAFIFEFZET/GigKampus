@props(['items'])

@if ($items->hasPages())
    <nav class="pagination" aria-label="Halaman hasil">
        <p class="text-campus-muted">Menampilkan {{ $items->firstItem() ?? 0 }}–{{ $items->lastItem() ?? 0 }} dari {{ $items->total() }}</p>
        <div class="flex flex-wrap items-center gap-2">
            @if ($items->previousPageUrl())
                <x-ui.button :href="$items->previousPageUrl()" variant="outline" rel="prev">Sebelumnya</x-ui.button>
            @endif
            <x-ui.badge tone="info">{{ $items->currentPage() }} / {{ $items->lastPage() }}</x-ui.badge>
            @if ($items->nextPageUrl())
                <x-ui.button :href="$items->nextPageUrl()" variant="outline" rel="next">Berikutnya</x-ui.button>
            @endif
        </div>
    </nav>
@endif
