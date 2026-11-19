@extends('layouts.admin')

@section('title', 'Verifikasi KTM')

@section('content')
    <div class="stack">
        <x-ui.section-header title="Verifikasi KTM" subtitle="Tinjau pengajuan mahasiswa dan periksa kandidat NIM hasil OCR."/>

        <x-ui.card class="stack">
            <form method="GET" action="{{ route('admin.ktm.index') }}" class="flex flex-wrap items-end gap-3">
                <div class="min-w-48">
                    <x-ui.select name="status" label="Status pengajuan" :value="$filter" :options="[
                        'pending_review' => 'Menunggu review',
                        'processing' => 'Diproses',
                        'ocr_failed' => 'OCR gagal',
                        'approved' => 'Disetujui',
                        'rejected' => 'Ditolak',
                        'semua' => 'Semua pengajuan',
                    ]"/>
                </div>
                <x-ui.button type="submit" variant="outline">Terapkan Filter</x-ui.button>
            </form>

            @include('pages.admin.ktm.submissions')
            <x-ui.pagination :items="$submissions"/>
        </x-ui.card>
    </div>
@endsection
