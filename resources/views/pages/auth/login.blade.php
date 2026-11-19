@extends('layouts.auth')

@section('title', 'Masuk')

@section('content')
    <x-ui.section-header title="Selamat datang kembali." subtitle="Masuk dengan email dan kata sandi akun GigKampus Anda."/>

    @isset($throttleMessage)
        <x-ui.alert tone="danger">{{ $throttleMessage }}</x-ui.alert>
    @endisset

    <form action="{{ route('login.store') }}" method="post" class="stack" autocomplete="on">
        @csrf
        <x-ui.input name="email" label="Alamat Email" type="email" :value="$email ?? null" placeholder="nama@email.com"
            autocomplete="username" maxlength="254" required/>
        <x-ui.input name="password" label="Kata Sandi" type="password" placeholder="Masukkan kata sandi"
            autocomplete="current-password" maxlength="128" required/>

        <x-ui.button type="submit" class="w-full">Masuk ke GigKampus</x-ui.button>
    </form>

    <p class="text-center text-sm text-campus-muted">Belum punya akun?
        <a class="font-semibold text-campus-link hover:underline" href="{{ route('register') }}">Daftar sekarang</a>.
    </p>
@endsection
