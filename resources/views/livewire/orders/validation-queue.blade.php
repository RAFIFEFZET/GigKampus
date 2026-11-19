<div class="stack">
    @forelse ($orders as $order)
        <x-domain.order-card :$order :student="$seller" :timeline="false" :validation="true" wire:key="validation-order-{{ $order->id }}"/>
    @empty
        <x-ui.empty-state title="Belum ada bukti yang perlu divalidasi"/>
    @endforelse
    <x-ui.pagination :items="$orders"/>
</div>
