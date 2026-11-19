@if ($submissions->isEmpty())
    <x-ui.empty-state title="Belum ada pengajuan pada daftar ini"/>
@else
    <ul class="divide-y divide-campus-border">
        @foreach ($submissions as $submission)
            <li class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                <div class="min-w-0 space-y-1">
                    <p class="font-semibold">{{ $submission->name_snapshot }}</p>
                    <p class="text-sm text-campus-muted">{{ $submission->submitted_at->timezone('Asia/Jakarta')->translatedFormat('j M Y, H:i') }} WIB</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <x-domain.status-badge :status="$submission->status"/>
                    <x-ui.button :href="route('admin.ktm.show', $submission)" variant="outline">Lihat Detail</x-ui.button>
                </div>
            </li>
        @endforeach
    </ul>
@endif
