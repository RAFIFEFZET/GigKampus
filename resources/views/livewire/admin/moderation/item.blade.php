@php
    [$title, $summary, $description, $status, $hidden] = match ($section) {
        'gig' => [$item->title, $item->seller->name, $item->description, $item->status, false],
        'request' => [$item->title, $item->buyer->name, $item->description, $item->status, $item->is_hidden],
        'review' => ['Ulasan pesanan #'.$item->order_id, $item->reviewer->name.' → '.$item->seller->name.' · '.$item->rating.'/5', $item->comment, null, $item->is_hidden],
        'account' => [$item->name, $item->is_verified ? 'Terverifikasi' : 'Belum terverifikasi', null, $item->is_suspended ? 'suspended' : 'active', false],
    };
    $actions = \App\Livewire\Admin\Moderation\Index::actions($item);
    $open = $targetId === $item->id;
@endphp

<article class="min-w-0 rounded-campus-field border border-campus-border p-4 stack" wire:key="moderation-{{ $section }}-{{ $item->id }}">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1 basis-44 [overflow-wrap:anywhere]">
            <h2 class="text-base">{{ $title }}</h2>
            <p class="mt-1 text-sm text-campus-muted">#{{ $item->id }} · {{ $summary }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($status !== null)
                <x-domain.status-badge :$status />
            @endif
            @if ($hidden)
                <x-ui.badge tone="warning">Disembunyikan</x-ui.badge>
            @endif
        </div>
    </div>

    @if ($description)
        <p class="moderation-copy text-sm [overflow-wrap:anywhere]">{{ $description }}</p>
    @endif
    @if ($latestReason)
        <p class="moderation-copy text-sm text-campus-muted [overflow-wrap:anywhere]"><strong>Alasan tindakan terakhir</strong> {{ $latestReason }}</p>
    @endif

    @if ($open)
        <form wire:submit="apply" class="stack border-t border-campus-border pt-3" aria-label="Tindakan untuk {{ $title }}">
            <x-ui.select name="action" id="moderation-action-{{ $item->id }}" label="Tindakan" :options="$actions" wire:model="action" />
            <x-ui.textarea name="reason" id="moderation-reason-{{ $item->id }}" label="Alasan moderasi" wire:model="reason" minlength="1" maxlength="1000" required />
            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit" wire:loading.attr="disabled">Simpan Tindakan</x-ui.button>
                <x-ui.button variant="outline" wire:click="closeAction">Batal</x-ui.button>
            </div>
        </form>
    @elseif ($section === 'review' && ! $hidden)
        @if ($case = $item->reports->first())
            <div><x-ui.button variant="outline" :href="route('admin.reports.show', $case)">Tinjau laporan untuk sembunyikan ulasan</x-ui.button></div>
        @else
            <p class="text-sm text-campus-muted">Belum ada kasus ulasan yang dapat Anda tangani.</p>
        @endif
    @elseif ($actions !== [])
        <div>
            <x-ui.button variant="outline" wire:click="openAction({{ $item->id }})" aria-label="Tindak {{ $title }}">Tindak</x-ui.button>
        </div>
    @endif
</article>
