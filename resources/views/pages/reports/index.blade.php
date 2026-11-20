@extends('layouts.app')
@section('title', 'Laporan dan Klarifikasi')

@section('content')
    <section class="shell page-section moderation-shell moderation-stack">
        <x-ui.section-header title="Laporan dan Klarifikasi"/>
        <livewire:reports.history :order-id="request()->integer('order') ?: null"/>
    </section>
@endsection
