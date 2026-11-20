<div class="moderation-stack">
    <div class="max-w-xs">
        <x-ui.select name="filter" label="Tampilkan kasus" wire:model.live="filter"
            :options="['active' => 'Antrean aktif', 'mine' => 'Kasus aktif saya', 'history' => 'Riwayat putusan']"/>
    </div>
    <div class="moderation-grid">
        @forelse ($reports as $report)
            <x-ui.card class="moderation-panel moderation-stack" wire:key="admin-report-{{ $report->id }}">
                <div class="moderation-actions">
                    <x-domain.moderation-badge :status="$report->status"/>
                    <x-ui.wib-time :date="$report->decided_at ?? $report->created_at" class="text-sm text-campus-muted"/>
                </div>
                <div>
                    <h2 class="text-xl">{{ $report->report_type->label() }} #{{ $report->id }}</h2>
                    <p class="text-sm text-campus-muted">{{ $report->category->label() }}</p>
                </div>
                <div class="moderation-actions">
                    @if ($report->handled_by_admin_id === null || $admin->hasId($report->handled_by_admin_id))
                        <x-ui.button variant="secondary" :href="route('admin.reports.show', $report)">Buka kasus #{{ $report->id }}</x-ui.button>
                    @else
                        <span class="text-sm text-campus-muted">Ditangani Admin lain</span>
                    @endif
                </div>
            </x-ui.card>
        @empty
            <x-ui.empty-state title="Tidak ada laporan pada tampilan ini"/>
        @endforelse
    </div>
    {{ $reports->links() }}
</div>
