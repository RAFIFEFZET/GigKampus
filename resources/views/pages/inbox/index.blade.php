@extends('layouts.app')
@section('title', 'Notifikasi')
@section('content')
    <section class="shell page-section stack">
        <x-ui.section-header title="Notifikasi"/>
        <livewire:inbox.notifications/>
    </section>
@endsection
