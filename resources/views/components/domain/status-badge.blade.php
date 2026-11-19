@props(['status'])

@php
    $value = $status instanceof \BackedEnum ? $status->value : $status;
    [$label, $tone] = match ($value) {
        'processing' => ['Diproses', 'info'],
        'ocr_failed' => ['OCR gagal', 'danger'],
        'pending_review' => ['Menunggu review', 'warning'],
        'approved' => ['Disetujui', 'success'],
        'rejected' => ['Ditolak', 'danger'],
        'active' => ['Aktif', 'success'],
        'inactive' => ['Nonaktif', 'neutral'],
        'suspended' => ['Disuspend', 'danger'],
        'open' => ['Terbuka', 'success'],
        'closed' => ['Ditutup', 'neutral'],
        'ordered' => ['Dipesan', 'info'],
        'pending' => ['Menunggu', 'warning'],
        'accepted' => ['Diterima', 'success'],
        'withdrawn' => ['Ditarik', 'neutral'],
        default => [str($value)->replace('_', ' ')->ucfirst(), 'neutral'],
    };
@endphp

<x-ui.badge :$tone {{ $attributes }}>{{ $label }}</x-ui.badge>
