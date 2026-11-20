<div class="stack">
    <x-ui.section-header title="Moderasi" subtitle="Tinjau Gig, Permintaan Jasa, ulasan, dan akun mahasiswa." />

    <x-ui.card class="stack moderation-panel">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1 basis-44">
                <x-ui.select name="section" label="Jenis moderasi" :options="\App\Livewire\Admin\Moderation\Index::SECTIONS" wire:model.live="section" />
            </div>
            <div class="min-w-0 flex-1 basis-44">
                <x-ui.input name="search" type="search" label="Cari konten atau akun" placeholder="Kata kunci atau ID" maxlength="150" wire:model.live.debounce.300ms="search" />
            </div>
        </div>

        @if ($saved)
            <x-ui.alert tone="success">Tindakan moderasi tersimpan.</x-ui.alert>
        @endif

        <div class="stack">
            @forelse ($items as $item)
                @include('livewire.admin.moderation.item', ['latestReason' => $reasons->get($item->id)?->reason])
            @empty
                <x-ui.empty-state title="Tidak ada hasil yang cocok" />
            @endforelse
        </div>

        <x-ui.pagination :items="$items" />
    </x-ui.card>
</div>
