@props(['status'])

@php([$label, $tone] = match ($status) {
    \App\Enums\ReportStatus::Pending => ['Menunggu putusan', 'warning'],
    \App\Enums\ReportStatus::Resolved => ['Diselesaikan', 'success'],
    \App\Enums\ReportStatus::Dismissed => ['Ditolak', 'neutral'],
})
<x-ui.badge :$tone {{ $attributes }}>{{ $label }}</x-ui.badge>
