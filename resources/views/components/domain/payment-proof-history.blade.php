@props(['payment'])

<x-ui.card class="stack">
    <x-ui.section-header level="2" title="Riwayat Bukti Pembayaran"/>
    @forelse ($payment->proofs as $proof)
        <article class="min-w-0 space-y-2 rounded-xl border border-campus-border p-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <h3>Percobaan {{ $proof->attempt_no }}</h3>
                <x-domain.status-badge :status="$proof->seller_review_status"/>
            </div>
            <div class="flex flex-wrap gap-3 text-sm">
                <a href="{{ route('files.show', ['jenis' => 'payment_proof', 'id' => $proof->id]) }}"
                    class="font-medium text-campus-link" target="_blank" rel="noopener">{{ $proof->original_filename }} ↗</a>
                <x-ui.wib-time :date="$proof->submitted_at" class="text-campus-muted"/>
            </div>
            @if ($proof->rejection_reason)
                <x-ui.alert tone="danger" title="Alasan penolakan"><p class="whitespace-pre-wrap">{{ $proof->rejection_reason }}</p></x-ui.alert>
            @endif
            @if ($payment->verified_proof_id === $proof->id)
                <p class="text-sm font-semibold text-green-800">Bukti terverifikasi untuk pembayaran ini</p>
            @endif
        </article>
    @empty
        <x-ui.empty-state title="Belum ada bukti pembayaran"/>
    @endforelse
</x-ui.card>
