<div class="stack">
    @forelse ($entries as $entry)
        <x-ui.card wire:key="notification-{{ $entry->id }}">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <h2 class="text-base">{{ $entry->title }}</h2>
                <time datetime="{{ $entry->created_at->toIso8601String() }}" class="muted text-xs">
                    {{ $entry->created_at->timezone('Asia/Jakarta')->locale('id')->translatedFormat('j M Y, H:i') }} WIB
                </time>
            </div>
            <p class="mt-2 whitespace-pre-wrap">{{ $entry->body }}</p>
            @if (isset($entry->data->service_request_id))
                <a href="{{ route('requests.show', $entry->data->service_request_id) }}" class="mt-2 block text-sm font-semibold text-campus-link">Lihat permintaan</a>
            @elseif (str_starts_with($entry->type, 'ktm.'))
                <a href="{{ route('ktm.index') }}" class="mt-2 block text-sm font-semibold text-campus-link">Lihat status KTM</a>
            @endif
            @unless ($entry->read_at)
                <x-ui.button class="mt-3" variant="secondary" wire:click="markRead({{ $entry->id }})" wire:loading.attr="disabled">Tandai dibaca</x-ui.button>
            @endunless
        </x-ui.card>
    @empty
        <x-ui.empty-state title="Belum ada notifikasi"/>
    @endforelse
    <x-ui.pagination :items="$entries"/>
</div>
