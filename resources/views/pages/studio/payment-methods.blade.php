@extends('layouts.app')

@section('title', 'Rekening & QRIS')
@section('body-class', 'bg-campus-background')

@section('content')
    <div class="shell page-section stack">
        <x-ui.section-header title="Studio Seller"/>
        <x-domain.studio-nav/>

        <x-ui.section-header title="Rekening & QRIS" :level="2"
            subtitle="Siapkan minimal satu metode pembayaran untuk menerbitkan jasa atau mengirim penawaran."/>
        <x-ui.alert tone="warning">Simulasi demo. Jangan melakukan transfer uang sungguhan.</x-ui.alert>

        @isset($paymentError)
            <x-ui.alert tone="danger">{{ $paymentError }}</x-ui.alert>
        @endisset

        <div class="grid min-w-0 gap-6 lg:grid-cols-2 lg:items-start">
            <x-ui.card class="stack">
                <x-ui.section-header title="Transfer bank" :level="2"/>

                <form action="{{ route('studio.payment.update') }}" method="post" class="stack">
                    @csrf
                    @method('PATCH')

                    <x-ui.select name="bank_id" label="Bank" :options="['' => 'Pilih bank'] + $banks" :value="$seller->bank_id" required/>
                    <x-ui.input name="bank_account_number" label="Nomor rekening" :value="$seller->bank_account_number"
                        placeholder="Masukkan nomor rekening" inputmode="numeric" pattern="[0-9]{1,50}" maxlength="50" required/>
                    <x-ui.input name="bank_account_holder_name" label="Nama pemilik rekening" :value="$seller->bank_account_holder_name"
                        maxlength="100" required/>

                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="submit">Simpan rekening</x-ui.button>
                        @if ($seller->hasBankPaymentMethod())
                            <x-ui.button type="submit" variant="danger" form="remove-bank">Hapus rekening</x-ui.button>
                        @endif
                    </div>
                </form>

                @if ($seller->hasBankPaymentMethod())
                    <form id="remove-bank" method="post" action="{{ route('studio.payment.destroy', 'bank') }}">
                        @csrf
                        @method('DELETE')
                    </form>
                @endif
            </x-ui.card>

            <x-ui.card class="stack">
                <x-ui.section-header title="QRIS" :level="2"/>

                <form action="{{ route('studio.payment.update') }}" method="post" enctype="multipart/form-data" class="stack">
                    @csrf
                    @method('PATCH')

                    @if ($seller->hasQrisPaymentMethod())
                        <div class="flex flex-wrap items-center gap-4 rounded-campus-field bg-campus-sky p-4">
                            <img src="{{ route('files.show', ['jenis' => 'qris', 'id' => $seller->id]) }}"
                                alt="Pratinjau QRIS tersimpan" class="size-28 rounded-lg bg-white object-contain p-2">
                            <a href="{{ route('files.show', ['jenis' => 'qris', 'id' => $seller->id]) }}"
                                class="text-sm font-semibold text-campus-link hover:underline">Lihat QRIS tersimpan</a>
                        </div>
                    @endif
                    <x-ui.file-upload name="qris_image" label="Gambar QRIS" hint="JPG, JPEG, atau PNG, maksimal 5 MB."
                        :required="! $seller->hasQrisPaymentMethod()"/>
                    <x-ui.input name="qris_holder_name" label="Nama merchant QRIS" :value="$seller->qris_holder_name"
                        maxlength="100" required/>

                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="submit">Simpan QRIS</x-ui.button>
                        @if ($seller->hasQrisPaymentMethod())
                            <x-ui.button type="submit" variant="danger" form="remove-qris">Hapus QRIS</x-ui.button>
                        @endif
                    </div>
                </form>

                @if ($seller->hasQrisPaymentMethod())
                    <form id="remove-qris" method="post" action="{{ route('studio.payment.destroy', 'qris') }}">
                        @csrf
                        @method('DELETE')
                    </form>
                @endif
            </x-ui.card>
        </div>
    </div>
@endsection
