@extends('layouts.app')
@section('title', $gig ? 'Edit Gig' : 'Publikasikan Gig Baru')
@section('body-class', 'bg-campus-background')

@section('content')
    <section class="shell page-section stack">
        <x-domain.studio-nav/>
        <a class="text-sm text-campus-link" href="{{ route('studio.gigs.index') }}">← Katalog Gig</a>
        <x-ui.card class="mx-auto w-full max-w-3xl stack">
            <x-ui.section-header :title="$gig ? 'Edit Gig' : 'Publikasikan Gig Baru'" subtitle="Cantumkan satu harga tetap, waktu pengerjaan, dan kuota revisi."/>
            @if ($gig?->status === \App\Enums\GigStatus::Suspended)
                <x-ui.alert tone="warning">Gig disuspend. Kamu dapat menyunting isinya. Aktivasi kembali memerlukan tindakan Admin.</x-ui.alert>
            @endif
            <form method="post" action="{{ $gig ? route('studio.gigs.update', $gig) : route('studio.gigs.store') }}" enctype="multipart/form-data" class="stack">
                @csrf
                @if ($gig) @method('PATCH') @endif
                <x-ui.input name="title" label="Judul Jasa" :value="$gig?->title" minlength="5" maxlength="150" required/>
                <x-ui.select name="category_id" label="Kategori" :value="$gig?->category_id" :options="['' => 'Pilih kategori aktif'] + $categories->pluck('name', 'id')->all()" required/>
                <x-ui.textarea name="description" label="Deskripsi Jasa" :value="$gig?->description" minlength="20" maxlength="5000" required/>
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.currency-input name="base_price" label="Harga Tetap (Rp)" :value="$gig?->base_price" min="1" max="100000000" required/>
                    <x-ui.input name="delivery_days" label="Pengerjaan (hari)" type="number" :value="$gig?->delivery_days" min="1" max="365" required/>
                    <x-ui.input name="max_revisions" label="Kuota Revisi" type="number" :value="$gig?->max_revisions ?? 0" min="0" max="20" required/>
                </div>
                <x-ui.file-upload name="portfolio" label="Portofolio (opsional)" accept=".jpg,.jpeg,.png,.pdf" help="JPG atau PNG maks. 5 MB. PDF maks. 20 MB."/>
                @if ($gig?->portfolio_path)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="remove_portfolio" value="1" @checked(old('remove_portfolio'))>
                        Hapus portofolio yang ada
                    </label>
                @endif
                <x-ui.button type="submit" class="self-start">{{ $gig ? 'Simpan Gig' : 'Publikasikan Gig' }}</x-ui.button>
            </form>
        </x-ui.card>
    </section>
@endsection
