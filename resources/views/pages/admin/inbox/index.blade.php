@extends('layouts.admin')
@section('title', 'Notifikasi')
@section('content')
    <x-ui.section-header title="Notifikasi"/>
    <livewire:inbox.admin-notifications/>
@endsection
