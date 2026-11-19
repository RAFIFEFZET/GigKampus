<div class="stack" x-data x-on:gigkampus:inbox.window="$wire.$refresh()">
    @if ($hasUnread)
        <div class="flex justify-end">
            <x-ui.button variant="outline" wire:click="markAllRead" wire:loading.attr="disabled">
                Tandai semua dibaca
            </x-ui.button>
        </div>
    @endif

    @forelse ($entries as $entry)
        <x-domain.notification-item :$entry wire:key="notification-{{ $entry->id }}"/>
    @empty
        <x-ui.empty-state title="Belum ada notifikasi"/>
    @endforelse

    @if ($entries->hasPages())
        <nav class="pagination" aria-label="Halaman notifikasi">
            <p class="text-campus-muted">Menampilkan {{ $entries->firstItem() }}–{{ $entries->lastItem() }} dari {{ $entries->total() }}</p>
            <div class="flex flex-wrap items-center gap-2">
                @unless ($entries->onFirstPage())
                    <x-ui.button variant="outline" wire:click="previousPage" wire:loading.attr="disabled">Sebelumnya</x-ui.button>
                @endunless
                <x-ui.badge tone="info">{{ $entries->currentPage() }} / {{ $entries->lastPage() }}</x-ui.badge>
                @if ($entries->hasMorePages())
                    <x-ui.button variant="outline" wire:click="nextPage" wire:loading.attr="disabled">Berikutnya</x-ui.button>
                @endif
            </div>
        </nav>
    @endif
</div>
