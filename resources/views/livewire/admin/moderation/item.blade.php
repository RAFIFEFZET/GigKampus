@php
    [$title, $description, $itemStatus, $hidden, $date, $metadata] = match ($section) {
        'gig' => [$item->title, $item->description, $item->status, false, $item->created_at, [
            'Pemilik' => $item->seller->name,
            'Kategori' => $item->category->name,
        ]],
        'request' => [$item->title, $item->description, $item->status, $item->is_hidden, $item->created_at, [
            'Pemilik' => $item->buyer->name,
            'Kategori' => $item->category->name,
        ]],
        'review' => ['Ulasan pesanan #'.$item->order_id, $item->comment, null, $item->is_hidden, $item->review_date, [
            'Penulis' => $item->reviewer->name,
            'Seller' => $item->seller->name,
            'Kategori' => $item->order->category_name_snapshot,
            'Rating' => $item->rating.'/5',
        ]],
        'account' => [$item->name, null, $item->is_suspended ? 'suspended' : 'active', false, $item->created_at, [
            'Verifikasi' => $item->is_verified ? 'Terverifikasi' : 'Belum terverifikasi',
        ]],
    };
    $statusValue = $itemStatus instanceof \BackedEnum ? $itemStatus->value : $itemStatus;
    $accent = $statusValue === 'suspended' ? 'danger' : ($hidden ? 'warning' : 'neutral');
    $actions = \App\Livewire\Admin\Moderation\Index::actions($item);
    $open = $targetId === $item->id;
    $actionStyles = [
        'suspend_gig' => ['block', 'danger'], 'suspend_user' => ['block', 'danger'],
        'unsuspend_gig' => ['check', 'success'], 'unsuspend_user' => ['check', 'success'],
        'close_request' => ['close', 'warning'], 'hide_request' => ['hide', 'warning'],
    ];
@endphp

<article class="admin-moderation-card" data-accent="{{ $accent }}" wire:key="moderation-{{ $section }}-{{ $item->id }}">
    <div class="admin-moderation-card-heading">
        <h2>{{ $title }}</h2>
        <div class="flex flex-wrap gap-2">
            <x-ui.badge tone="info">{{ \App\Livewire\Admin\Moderation\Index::SECTIONS[$section] }}</x-ui.badge>
            @if ($itemStatus !== null)
                <x-domain.status-badge :status="$itemStatus" />
            @endif
            @if ($hidden)
                <x-ui.badge tone="warning">Disembunyikan</x-ui.badge>
            @elseif ($section === 'review')
                <x-ui.badge tone="success">Tayang</x-ui.badge>
            @endif
        </div>
    </div>

    <dl class="admin-moderation-metadata">
        <div><dt>ID</dt><dd>#{{ $item->id }}</dd></div>
        @foreach ($metadata as $label => $value)
            <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
        @endforeach
        <div><dt>{{ $section === 'account' ? 'Terdaftar' : 'Dibuat' }}</dt><dd><x-ui.wib-time :$date :withTime="false" /></dd></div>
        <div><dt>Laporan terkait</dt><dd>{{ $item->reports_count }}</dd></div>
    </dl>

    @if ($description)
        <p class="moderation-copy text-sm">{{ $description }}</p>
    @endif
    @if ($latestAction)
        <div class="admin-moderation-reason">
            <x-domain.admin-action-icon name="info" />
            <div>
                <div class="admin-moderation-reason-heading">
                    <strong>Alasan tindakan terakhir</strong>
                    <x-ui.wib-time :date="$latestAction->occurred_at" :withTime="false" />
                </div>
                <p class="moderation-copy">{{ $latestAction->reason }}</p>
            </div>
        </div>
    @endif

    @if ($open)
        <form wire:submit="apply" class="stack border-t border-campus-border pt-3" aria-label="Tindakan untuk {{ $title }}">
            <h3>{{ $actions[$action] ?? 'Tindakan moderasi' }}</h3>
            @error('action')<x-ui.alert tone="warning">{{ $message }}</x-ui.alert>@enderror
            <x-ui.textarea name="reason" id="moderation-reason-{{ $item->id }}" label="Alasan moderasi" wire:model="reason" minlength="1" maxlength="1000" required />
            <div class="moderation-actions">
                <x-ui.button type="submit" wire:loading.attr="disabled">Simpan Tindakan</x-ui.button>
                <x-ui.button variant="outline" wire:click="closeAction">Batal</x-ui.button>
            </div>
        </form>
    @elseif ($section === 'review' && ! $hidden)
        @if ($case = $item->reports->first())
            <div class="moderation-actions">
                <x-ui.button variant="secondary" class="admin-moderation-action" :href="route('admin.reports.show', $case)">
                    <x-domain.admin-action-icon name="view"/> Tinjau Kasus
                </x-ui.button>
            </div>
        @else
            <p class="text-sm text-campus-muted">Belum ada kasus ulasan yang dapat Anda tangani.</p>
        @endif
    @elseif ($actions !== [])
        <div class="moderation-actions">
            @foreach ($actions as $key => $label)
                @php([$icon, $tone] = $actionStyles[$key])
                <x-ui.button variant="outline" class="admin-moderation-action admin-moderation-action-{{ $tone }}"
                    wire:click="openAction({{ $item->id }}, '{{ $key }}')" wire:loading.attr="disabled">
                    <x-domain.admin-action-icon :name="$icon"/> {{ $label }}
                </x-ui.button>
            @endforeach
        </div>
    @endif
</article>
