@extends('layouts.app')
@section('title', $report->report_type->label())

@section('content')
    <section class="shell page-section moderation-shell moderation-stack">
        <a class="text-campus-link" href="{{ route('reports.index') }}">← Laporan dan Klarifikasi</a>
        <x-ui.section-header :title="$report->report_type->label().' #'.$report->id"/>
        <x-ui.card class="moderation-panel moderation-stack">
            <div class="moderation-actions">
                <x-domain.moderation-badge :status="$report->status"/>
                @if ($report->order)<a class="text-campus-link" href="{{ route('orders.show', $report->order) }}">{{ $report->order->reference() }}</a>@endif
            </div>
            @if ($isReporter)
                <h2>{{ $report->category->label() }}</h2>
                <p class="moderation-copy">{{ $report->description }}</p>
            @endif
            @if ($canSeeEvidence)<x-domain.report-evidence-link :entry="$report" kind="report_evidence"/>@endif
            @if ($report->decided_at)
                <h2>Putusan akhir</h2>
                <p class="moderation-copy">{{ $report->decision_note }}</p>
                <x-ui.wib-time :date="$report->decided_at"/>
            @endif
        </x-ui.card>
        @foreach ($clarifications as $clarification)
            <x-ui.button variant="outline" class="justify-self-start" :href="route('reports.clarifications.show', $clarification)">
                Buka klarifikasi #{{ $clarification->id }}
            </x-ui.button>
        @endforeach
    </section>
@endsection
