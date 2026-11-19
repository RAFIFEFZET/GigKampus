@extends('layouts.admin')

@section('title', 'Detail Pengajuan KTM')

@section('content')
    <div class="stack">
        <div>
            <a href="{{ route('admin.ktm.index') }}" class="text-sm font-semibold text-campus-link hover:underline">← Kembali ke Antrean KTM</a>
        </div>
        <x-ui.section-header title="Detail Pengajuan KTM" :subtitle="$verification->name_snapshot">
            <x-domain.status-badge :status="$verification->status"/>
        </x-ui.section-header>

        @if (session('status'))
            <x-ui.alert tone="success">{{ session('status') }}</x-ui.alert>
        @endif
        @error('review')
            <x-ui.alert tone="danger">{{ $message }}</x-ui.alert>
        @enderror

        <div class="split-detail">
            <x-ui.card class="stack">
                <h2>Foto KTM</h2>
                <a href="{{ route('files.show', ['jenis' => 'ktm', 'id' => $verification->id]) }}" target="_blank" rel="noopener" class="block rounded-campus-field">
                    <img src="{{ route('files.show', ['jenis' => 'ktm', 'id' => $verification->id]) }}" alt="Foto KTM yang diajukan, buka ukuran penuh" class="max-h-[34rem] w-full rounded-campus-field bg-campus-background object-contain">
                </a>
            </x-ui.card>

            <x-ui.card class="stack">
                <h2>Data Pengajuan</h2>
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-campus-muted">Nama saat pengajuan</dt>
                        <dd class="font-semibold">{{ $verification->name_snapshot }}</dd>
                    </div>
                    <div>
                        <dt class="text-campus-muted">Waktu pengajuan</dt>
                        <dd>{{ $verification->submitted_at->timezone('Asia/Jakarta')->translatedFormat('j M Y, H:i') }} WIB</dd>
                    </div>
                    <div class="rounded-campus-field bg-campus-background px-4 py-3">
                        <dt class="text-campus-muted">Kandidat NIM hasil OCR</dt>
                        <dd class="mt-1 font-mono text-lg font-semibold">{{ $verification->ocr_nim_candidate ?? '—' }}</dd>
                    </div>
                </dl>

                @if ($verification->status === \App\Enums\KtmVerificationStatus::PendingReview)
                    <form method="POST" action="{{ route('admin.ktm.approve', $verification) }}" class="stack">
                        @csrf
                        <x-ui.textarea name="reason" label="Alasan pemeriksaan" help="Wajib untuk penolakan. Gunakan 5–1.000 karakter." maxlength="1000"/>
                        <div class="flex flex-wrap gap-2">
                            <x-ui.button type="submit">Setujui KTM</x-ui.button>
                            <x-ui.button type="submit" variant="danger" :formaction="route('admin.ktm.reject', $verification)">Tolak KTM</x-ui.button>
                        </div>
                    </form>
                @elseif ($verification->review_reason || $verification->ocr_error)
                    <x-ui.alert tone="neutral" title="Catatan Pengajuan">{{ $verification->review_reason ?? $verification->ocr_error }}</x-ui.alert>
                @endif
            </x-ui.card>
        </div>
    </div>
@endsection
