<div class="moderation-stack">
    @if ($order)
        <a class="text-campus-link" href="{{ route('orders.show', $order) }}">← {{ $order->reference() }} · {{ $order->title_snapshot }}</a>
    @endif
    <div class="moderation-grid">
        <x-ui.card class="moderation-panel moderation-stack">
            <h2>Klarifikasi untuk Saya</h2>
            @forelse ($clarifications as $clarification)
                <article class="space-y-2 border-t border-campus-border pt-3" wire:key="clarification-{{ $clarification->id }}">
                    <a class="font-semibold text-campus-link" href="{{ route('reports.clarifications.show', $clarification) }}">Buka klarifikasi #{{ $clarification->id }}</a>
                    <div class="moderation-actions">
                        <x-domain.moderation-badge :status="$clarification->report->status"/>
                        @if ($clarification->report->status === \App\Enums\ReportStatus::Pending && $clarification->isOverdue())
                            <x-ui.badge tone="warning">Tenggat terlewat</x-ui.badge>
                        @endif
                    </div>
                    <p class="text-sm text-campus-muted">Tenggat <x-ui.wib-time :date="$clarification->due_at"/></p>
                </article>
            @empty
                <p class="text-campus-muted">Belum ada permintaan klarifikasi.</p>
            @endforelse
            {{ $clarifications->links() }}
        </x-ui.card>
        <x-ui.card class="moderation-panel moderation-stack">
            <h2>Riwayat Laporan</h2>
            @forelse ($reports as $report)
                <article class="space-y-2 border-t border-campus-border pt-3" wire:key="report-{{ $report->id }}">
                    <a class="font-semibold text-campus-link" href="{{ route('reports.show', $report) }}">{{ $report->report_type->label() }} #{{ $report->id }}</a>
                    <div class="moderation-actions">
                        <x-domain.moderation-badge :status="$report->status"/>
                        <x-ui.wib-time :date="$report->created_at" class="text-sm text-campus-muted"/>
                    </div>
                </article>
            @empty
                <p class="text-campus-muted">Belum ada laporan.</p>
            @endforelse
            {{ $reports->links() }}
        </x-ui.card>
    </div>
</div>
