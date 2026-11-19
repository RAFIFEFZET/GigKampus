<div class="stack mx-auto max-w-[42.5rem]" @if ($current?->status === \App\Enums\KtmVerificationStatus::Processing) wire:poll.3s @endif>
    <x-ui.card class="stack rounded-3xl sm:p-8">
        <header class="space-y-3">
            <x-domain.ktm-status-banner :verification="$current"/>
            <h1 class="leading-tight">Verifikasi KTM</h1>
            <p class="max-w-xl text-campus-muted">Verifikasi identitas mahasiswa untuk mulai memesan atau menawarkan jasa.</p>
        </header>

            @if ($current)
                <section class="space-y-4 rounded-campus-field border border-campus-border p-4" aria-labelledby="status-ktm">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <h2 id="status-ktm">Status Pengajuan</h2>
                        <x-domain.status-badge :status="$current->status"/>
                    </div>

                    @switch($current->status)
                        @case(\App\Enums\KtmVerificationStatus::Processing)
                            <p class="text-campus-muted" role="status">Foto KTM sedang dipindai. Status akan diperbarui otomatis.</p>
                            @break
                        @case(\App\Enums\KtmVerificationStatus::PendingReview)
                            <p class="text-campus-muted">Admin akan mencocokkan hasil OCR dengan foto KTM dan nama saat pengajuan.</p>
                            @break
                        @case(\App\Enums\KtmVerificationStatus::Approved)
                            <p class="text-campus-muted">Identitas mahasiswa berhasil diverifikasi.</p>
                            @break
                        @case(\App\Enums\KtmVerificationStatus::OcrFailed)
                            <x-ui.alert tone="danger">{{ $current->ocr_error }}</x-ui.alert>
                            @break
                        @case(\App\Enums\KtmVerificationStatus::Rejected)
                            <x-ui.alert tone="danger" title="Alasan penolakan">{{ $current->review_reason }}</x-ui.alert>
                            @break
                    @endswitch

                    @if ($current->ocr_nim_candidate)
                        <dl class="rounded-campus-field bg-campus-background px-4 py-3">
                            <dt class="text-sm text-campus-muted">{{ $student->is_verified ? 'NIM terverifikasi' : 'Kandidat NIM hasil OCR' }}</dt>
                            <dd class="mt-1 font-mono text-lg font-semibold">{{ $current->ocr_nim_candidate }}</dd>
                        </dl>
                    @endif
                </section>
            @elseif ($student->is_verified)
                <x-ui.alert tone="success" title="Akun terverifikasi">Identitas mahasiswa berhasil diverifikasi.</x-ui.alert>
            @endif

            @if ($student->is_suspended)
                <x-ui.alert tone="warning">Pengajuan baru tidak tersedia selama akun ditangguhkan.</x-ui.alert>
            @endif

        @if ($canUpload)
            <form method="POST" action="{{ route('ktm.store') }}" enctype="multipart/form-data" class="stack">
                @csrf
                <div class="space-y-4 rounded-campus-field border-2 border-dashed border-blue-200 bg-campus-sky px-4 py-5 text-center sm:px-8">
                    <img src="{{ asset('design/ktm-id.svg') }}" alt="" class="mx-auto h-12 w-16 object-contain">
                    <x-ui.file-upload name="photo" :label="$current ? 'Unggah Ulang Foto KTM' : 'Unggah Foto KTM'" help="JPG, JPEG, atau PNG. Maksimal 5 MB." required/>
                </div>
                <x-ui.button type="submit" class="w-full">Kirim untuk Verifikasi</x-ui.button>
            </form>
        @endif
    </x-ui.card>

    @if ($history->isNotEmpty())
        <x-ui.card class="rounded-3xl" aria-labelledby="riwayat-ktm">
                    <h2 id="riwayat-ktm">Riwayat Pengajuan</h2>
                    <ul class="mt-3 divide-y divide-campus-border">
                        @foreach ($history as $entry)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="ktm-{{ $entry->id }}">
                                <div class="min-w-0 space-y-1">
                                    <p class="font-medium">{{ $entry->submitted_at->timezone('Asia/Jakarta')->translatedFormat('j M Y, H:i') }} WIB</p>
                                    <p class="text-sm text-campus-muted">{{ $entry->original_filename }}</p>
                                    @if ($entry->id !== $current->id && ($entry->review_reason || $entry->ocr_error))
                                        <p class="text-sm text-campus-muted">{{ $entry->review_reason ?? $entry->ocr_error }}</p>
                                    @endif
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-domain.status-badge :status="$entry->status"/>
                                    <a class="rounded-full px-3 py-2 text-sm font-medium text-campus-link hover:bg-campus-sky" href="{{ route('files.show', ['jenis' => 'ktm', 'id' => $entry->id]) }}" target="_blank" rel="noopener">Lihat Foto</a>
                                </div>
                            </li>
                        @endforeach
                    </ul>
        </x-ui.card>
    @endif

        <aside class="grid min-w-0 gap-4 sm:grid-cols-2">
            <x-ui.card class="space-y-3 rounded-3xl">
                <img src="{{ asset('design/ktm-badge.svg') }}" alt="" class="size-10">
                <h2 class="text-lg">Panduan Foto KTM</h2>
                <ul class="space-y-2 pl-5 text-sm text-campus-muted marker:text-campus-blue list-disc">
                    <li>Gunakan satu foto KTM dengan pencahayaan cukup.</li>
                    <li>Pastikan nama dan NIM terlihat jelas serta tidak terpotong.</li>
                    <li>Gunakan KTM milik sendiri yang sesuai dengan nama akun.</li>
                </ul>
            </x-ui.card>
            <x-ui.card class="flex items-start gap-3 rounded-3xl">
                <img src="{{ asset('design/ktm-support.svg') }}" alt="" class="size-9 shrink-0">
                <div class="space-y-1">
                    <h2 class="text-lg">Privasi Foto</h2>
                    <p class="text-sm text-campus-muted">Foto KTM hanya dapat dilihat oleh Anda dan Admin GigKampus.</p>
                </div>
            </x-ui.card>
        </aside>
</div>
