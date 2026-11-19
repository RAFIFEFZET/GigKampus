@extends('layouts.app')
@section('title', $serviceRequest ? 'Edit Permintaan' : 'Buat Permintaan Jasa')

@section('content')
    <section class="shell page-section stack">
        <a class="text-sm text-campus-link" href="{{ $serviceRequest ? route('requests.show', $serviceRequest) : route('requests.index') }}">← Papan Permintaan</a>
        <x-ui.card class="mx-auto w-full max-w-2xl stack">
            <x-ui.section-header :title="$serviceRequest ? 'Edit Permintaan' : 'Buat Permintaan Jasa Baru'"/>
            <form method="post" action="{{ $serviceRequest ? route('requests.update', $serviceRequest) : route('requests.store') }}" class="stack">
                @csrf
                @if ($serviceRequest) @method('PATCH') @endif
                <x-domain.request-fields :$serviceRequest :$categories/>
                <x-ui.button type="submit">{{ $serviceRequest ? 'Simpan Perubahan' : 'Publikasikan' }}</x-ui.button>
            </form>
        </x-ui.card>
    </section>
@endsection
