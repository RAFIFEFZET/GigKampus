@extends('layouts.app')
@section('title', 'Checkout berubah')
@section('body-class', 'bg-campus-background')

@section('content')
    <section class="orders-shell stack">
        <x-ui.button class="self-start" variant="outline" :href="route('catalog.index')">← Kembali ke Katalog</x-ui.button>
        <x-ui.section-header title="Checkout berubah" :subtitle="$message"/>
        <x-ui.card class="stack">
            <x-ui.textarea name="retained_brief" label="Draf Brief Pengerjaan" :value="$brief" rows="6" readonly/>
        </x-ui.card>
    </section>
@endsection
