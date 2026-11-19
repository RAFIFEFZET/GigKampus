@extends('layouts.app')
@section('title', 'Akses terbatas')
@section('content')
    <section class="shell page-section stack">
        <x-ui.section-header title="Akses terbatas"/>
        <x-ui.empty-state title="Halaman ini memerlukan izin" description="Gunakan akun yang mempunyai akses untuk melanjutkan.">
            <x-ui.button :href="route('home')">Kembali ke Beranda</x-ui.button>
            @if (auth('web')->check() && ! auth('web')->user()->is_verified)
                <x-ui.button variant="secondary" :href="route('ktm.index')">Verifikasi KTM</x-ui.button>
            @endif
        </x-ui.empty-state>
    </section>
@endsection
