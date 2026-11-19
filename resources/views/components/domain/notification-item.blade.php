@props(['entry'])

@php($destination = $entry->destinationUrl())

<x-ui.card {{ $attributes }}>
    <div class="flex items-start gap-3">
        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-campus-background">
            <img src="{{ asset('design/bell.svg') }}" width="18" height="18" alt="">
        </span>
        <div class="min-w-0 grow">
            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <h2 class="text-base">{{ $entry->title }}</h2>
                <x-ui.wib-time class="muted text-xs" :date="$entry->created_at"/>
            </div>
            <p class="mt-2 whitespace-pre-wrap wrap-break-word">{{ $entry->body }}</p>
            @if ($destination || ! $entry->read_at)
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    @if ($destination)
                        <x-ui.button :href="$destination" variant="outline">Lihat detail</x-ui.button>
                    @endif
                    @unless ($entry->read_at)
                        <x-ui.button variant="secondary" wire:click="markRead({{ $entry->id }})" wire:loading.attr="disabled">
                            Tandai dibaca
                        </x-ui.button>
                        <x-ui.badge tone="info">Belum dibaca</x-ui.badge>
                    @endunless
                </div>
            @endif
        </div>
    </div>
</x-ui.card>
