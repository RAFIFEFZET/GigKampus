<div @class(['chat-workspace', 'chat-open' => $room]) x-data="gigkampusConversation($wire)"
    x-init="sync(true)" x-on:gigkampus:chat.window="sync(false)" x-on:visibilitychange.document="sync(false)">
    <aside class="chat-sidebar" aria-label="Daftar percakapan">
        @if ($room)<h2 class="text-2xl">Pesan</h2>@else<h1>Chat</h1>@endif
        <label class="search-pill mt-4">
            <span class="sr-only">Cari percakapan</span>
            <img src="{{ asset('design/search.svg') }}" alt="" width="18" height="18">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari percakapan" maxlength="100">
        </label>
        <div class="flex flex-wrap gap-2 py-4" aria-label="Filter percakapan">
            <x-ui.button :variant="$unreadOnly ? 'ghost' : 'secondary'" wire:click="$set('unreadOnly', false)">Semua pesan</x-ui.button>
            <x-ui.button :variant="$unreadOnly ? 'secondary' : 'ghost'" wire:click="$set('unreadOnly', true)">Belum dibaca</x-ui.button>
        </div>
        <div class="space-y-2">
            @forelse ($rooms as $entry)
                <x-domain.chat-room-item :room="$entry" :$student :selected="$entry->id === $room?->id" wire:key="room-{{ $entry->id }}"/>
            @empty
                <x-ui.empty-state title="Belum ada percakapan"/>
            @endforelse
            <x-ui.pagination :items="$rooms"/>
        </div>
    </aside>

    @if ($room)
        @php($counterpart = $student->id === $room->buyer_id ? $room->seller : $room->buyer)
        <section class="chat-conversation" aria-label="Percakapan">
            <header class="chat-top">
                <a href="{{ route('chat.index') }}" class="chat-back">← Kembali</a>
                <h1 class="text-lg">{{ $room->order ? 'Chat Pesanan' : 'Chat Room' }}</h1>
                <x-ui.modal title="Laporkan Percakapan">
                    <x-slot:trigger><x-ui.button variant="ghost">Laporkan Percakapan</x-ui.button></x-slot:trigger>
                    <p>Formulir laporan dan peninjauan Admin tersedia pada Fase 4B.</p>
                </x-ui.modal>
            </header>
            <div class="chat-party">
                <x-ui.avatar :user="$counterpart" class="size-10"/>
                <div class="min-w-0">
                    <p class="font-semibold">{{ $counterpart->name }} <x-domain.seller-verified-badge :verified="$counterpart->is_verified"/></p>
                    <p class="text-xs text-campus-muted">{{ $room->isArchived() ? 'Percakapan diarsipkan' : ($room->order ? 'Percakapan pesanan' : 'Percakapan pra-pesan') }}</p>
                </div>
            </div>
            @if ($room->order)
                <div class="chat-order-strip">
                    <a class="font-semibold text-campus-link" href="{{ route('orders.show', $room->order) }}">{{ $room->order->reference() }}</a>
                    <p class="text-sm">{{ $room->order->title_snapshot }} · Rp{{ number_format($room->order->total_price, 0, ',', '.') }}</p>
                    <x-domain.status-badge :status="$room->order->status"/>
                </div>
            @endif
            <div class="chat-history" x-ref="history" data-chat-room="{{ $room->id }}"
                data-visible-through="{{ $messages->getCollection()->max('id') ?? 0 }}">
                @if ($messages->hasPages())
                    <div class="pb-3"><x-ui.pagination :items="$messages"/></div>
                @endif
                <ul class="space-y-3">
                    @forelse ($messages->getCollection()->reverse() as $message)
                        <x-domain.chat-message-bubble :$message :own="$message->sender_id === $student->id" wire:key="message-{{ $message->id }}"/>
                    @empty
                        <li><x-ui.empty-state title="Mulai percakapan"/></li>
                    @endforelse
                </ul>
            </div>
            @if ($canSend)
                <x-domain.chat-composer :$room :$canRefund/>
            @else
                <div class="p-4"><x-ui.alert tone="neutral">{{ $room->isArchived() ? 'Percakapan diarsipkan dan hanya dapat dibaca.' : 'Pengiriman pesan tidak tersedia untuk percakapan ini.' }}</x-ui.alert></div>
            @endif
        </section>
        <aside class="chat-context" aria-label="Rincian percakapan">
            <h2 class="text-lg">{{ $room->order ? 'Detail pesanan' : 'Pra-pesan' }}</h2>
            @if ($room->order)
                <x-ui.card class="stack">
                    <h3>{{ $room->order->title_snapshot }}</h3>
                    <x-domain.status-badge :status="$room->order->status" class="self-start"/>
                    <p class="text-xl font-bold">Rp{{ number_format($room->order->total_price, 0, ',', '.') }}</p>
                    <x-ui.button :href="route('orders.show', $room->order)">Lihat Pesanan</x-ui.button>
                </x-ui.card>
            @else
                <p class="text-sm">{{ $room->gig?->title ?? $room->priceOffer?->serviceRequest?->title }}</p>
            @endif
            <h3>File bersama</h3>
            @forelse ($files as $file)
                <a class="block rounded-xl bg-white p-3 text-sm font-medium text-campus-link"
                    href="{{ route('files.show', ['jenis' => 'chat_attachment', 'id' => $file->id]) }}">{{ $file->original_filename }}</a>
            @empty
                <p class="text-sm text-campus-muted">Belum ada lampiran</p>
            @endforelse
        </aside>
    @else
        <section class="chat-empty"><x-ui.empty-state title="Pilih percakapan" description="Pilih nama pada daftar untuk melihat pesan."/></section>
    @endif
</div>
