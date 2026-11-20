@extends('layouts.admin')
@section('title', 'Antrean Laporan')

@section('content')
    <x-ui.section-header title="Antrean Laporan" subtitle="Tinjau laporan yang masuk, kasus yang Anda tangani, dan riwayat putusan."/>
    <livewire:admin.reports.queue/>
@endsection
