@extends('layouts.app')
@section('title', $type->label())

@section('content')
    <section class="shell page-section moderation-shell moderation-stack">
        <x-ui.section-header :title="$type->label()" subtitle="Admin akan meninjau uraian dan bukti yang Anda kirim."/>
        @if ($type === \App\Enums\ReportType::OrderComplaint)
            <x-ui.alert tone="warning">Komplain aktif menjeda tindakan transaksi sampai Admin memutus kasus.</x-ui.alert>
        @endif
        <x-ui.card class="moderation-panel">
            <x-domain.report-form :$type :$target/>
        </x-ui.card>
    </section>
@endsection
