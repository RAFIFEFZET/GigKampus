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
        'awaiting_payment' => ['Menunggu pembayaran', 'warning'],
        'awaiting_validation' => ['Menunggu validasi', 'warning'],
        'in_progress' => ['Diproses', 'info'],
        'delivered' => ['Hasil dikirim', 'info'],
        'revision' => ['Dalam revisi', 'warning'],
        'requested' => ['Diminta', 'warning'],
        'completed' => ['Selesai', 'success'],
        'cancelled' => ['Dibatalkan', 'neutral'],
        'disputed' => ['Dalam sengketa', 'danger'],
        'verified' => ['Tervalidasi', 'success'],
        'voided' => ['Ditutup otomatis', 'neutral'],
        default => [str($value)->replace('_', ' ')->ucfirst(), 'neutral'],
    };
@endphp

<x-ui.badge :$tone {{ $attributes }}>{{ $label }}</x-ui.badge>
