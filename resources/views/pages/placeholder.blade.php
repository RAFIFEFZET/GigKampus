@extends('layouts.app')
@section('title', $title)
@section('content')
    <section class="shell page-section stack">
        <a class="text-sm text-campus-link" href="{{ route('home') }}">← Beranda</a>
        <x-ui.section-header :$title/>
        <x-ui.empty-state title="Segera tersedia" :$description/>
    </section>
@endsection
