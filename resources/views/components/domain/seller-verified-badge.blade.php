@props(['verified' => true])

@if ($verified)
    <span aria-label="Mahasiswa terverifikasi" title="Mahasiswa terverifikasi" {{ $attributes->class('verified-icon') }}>✓</span>
@endif
