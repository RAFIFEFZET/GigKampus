<div class="stack">
    <x-ui.section-header title="Moderasi" subtitle="Tinjau konten dan akun mahasiswa." />

    <x-ui.card class="stack moderation-panel">
        <nav class="admin-moderation-tabs" aria-label="Jenis moderasi">
            @foreach (\App\Livewire\Admin\Moderation\Index::SECTIONS as $key => $label)
                <button type="button" class="admin-moderation-pill" aria-pressed="{{ $section === $key ? 'true' : 'false' }}"
                    wire:click="$set('section', '{{ $key }}')">
                    {{ $label }} <span class="admin-moderation-count">{{ $counts[$key] }}</span>
                </button>
            @endforeach
        </nav>
        <div class="admin-moderation-filters">
            <div class="admin-moderation-status" wire:key="moderation-status-{{ $section }}" role="group" aria-label="Filter status">
                @foreach ($statusOptions as $key => $label)
                    <button type="button" class="admin-moderation-pill" aria-pressed="{{ $activeStatus === $key ? 'true' : 'false' }}"
                        wire:click="$set('status', '{{ $key }}')">{{ $label }}</button>
                @endforeach
            </div>
            <x-ui.input name="search" type="search" label="Cari konten atau akun" placeholder="Kata kunci atau ID" maxlength="150"
                wire:model.live.debounce.300ms="search" />
        </div>

        @if ($saved)
            <x-ui.alert tone="success">Tindakan moderasi tersimpan.</x-ui.alert>
        @endif
        @if ($targetId === null)
            @error('action')<x-ui.alert tone="warning">{{ $message }}</x-ui.alert>@enderror
        @endif

        <div class="stack">
            @forelse ($items as $item)
                @include('livewire.admin.moderation.item', ['latestAction' => $reasons->get($item->id)])
            @empty
                <x-ui.empty-state title="Tidak ada hasil yang cocok" />
            @endforelse
        </div>

        <x-ui.pagination :items="$items" />
    </x-ui.card>
</div>
