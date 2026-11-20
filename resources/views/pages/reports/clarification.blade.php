@extends('layouts.app')
@section('title', 'Klarifikasi Laporan')

@section('content')
    <section class="shell page-section moderation-shell moderation-stack">
        <a class="text-campus-link" href="{{ route('reports.index') }}">← Laporan dan Klarifikasi</a>
        <x-ui.section-header title="Klarifikasi Laporan"/>
        <x-domain.clarification-thread :$clarification :can-respond="$clarification->report->status === \App\Enums\ReportStatus::Pending"/>
    </section>
@endsection
