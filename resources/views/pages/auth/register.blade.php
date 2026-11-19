@extends('layouts.auth')

@section('title', 'Daftar Akun Baru')

@section('content')
    <x-ui.section-header title="Daftar Akun Baru" subtitle="Gunakan email kampus IPB untuk membuat akun GigKampus."/>

    <form action="{{ route('register.store') }}" method="post" class="stack" autocomplete="on">
        @csrf
        <x-ui.input name="name" label="Nama lengkap" autocomplete="name" placeholder="Nama sesuai KTM"
            minlength="2" maxlength="100" required/>
        <x-ui.input name="email" label="Email kampus" type="email" autocomplete="username"
            placeholder="nama@apps.ipb.ac.id" maxlength="254" required/>
        <x-ui.input name="phone_number" label="Nomor WhatsApp" type="tel" autocomplete="tel"
            placeholder="08xxxxxxxxxx" required/>
        <div class="form-grid">
            <x-ui.input name="password" label="Password" type="password" autocomplete="new-password"
                placeholder="Minimal 8 karakter" minlength="8" maxlength="128" required/>
            <x-ui.input name="password_confirmation" label="Konfirmasi password" type="password"
                autocomplete="new-password" placeholder="Ulangi password" minlength="8" maxlength="128" required/>
        </div>

        <x-ui.button type="submit" class="w-full">Daftar</x-ui.button>
    </form>

    <p class="text-center text-sm text-campus-muted">Sudah punya akun?
        <a class="font-semibold text-campus-link hover:underline" href="{{ route('login') }}">Masuk</a>.
    </p>
@endsection
