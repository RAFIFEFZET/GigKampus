@extends('layouts.admin')

@section('title', 'Dashboard Admin')

@section('content')
    <div class="stack">
        <x-ui.section-header title="Dashboard Admin" subtitle="Pantau antrean verifikasi KTM, laporan, dan kategori GigKampus."/>

        <div class="grid min-w-0 gap-4 sm:grid-cols-3">
            <x-ui.card class="space-y-2 bg-campus-sky">
                <h2 class="text-base">KTM Menunggu Review</h2>
                <p class="text-3xl font-bold text-campus-link">{{ $pendingKtmCount }}</p>
                <a href="{{ route('admin.ktm.index') }}" class="text-sm font-semibold text-campus-link hover:underline">Buka Antrean</a>
            </x-ui.card>
            <x-ui.card class="space-y-2">
                <h2 class="text-base">Kategori Aktif</h2>
                <p class="text-3xl font-bold">{{ $activeCategoryCount }}</p>
                <a href="{{ route('admin.categories.index') }}" class="text-sm font-semibold text-campus-link hover:underline">Kelola Kategori</a>
            </x-ui.card>
            <x-ui.card class="space-y-2 bg-campus-cream">
                <h2 class="text-base">Laporan Menunggu</h2>
                <p class="text-3xl font-bold">{{ $pendingReportCount }}</p>
            </x-ui.card>
        </div>

        <x-ui.card class="stack">
            <h2>Pengajuan Menunggu Pemeriksaan</h2>
            <x-ui.alert tone="info">Cocokkan nama dan kandidat NIM dengan foto KTM sebelum memberi keputusan.</x-ui.alert>
            @include('pages.admin.ktm.submissions', ['submissions' => $recentSubmissions])
        </x-ui.card>
    </div>
@endsection
