@extends('layouts.app')

@section('title', 'Profil Saya')
@section('body-class', 'bg-campus-background')

@section('content')
    <div class="shell page-section stack">
        <x-ui.section-header title="Profil Saya" subtitle="Kelola informasi profil dan keamanan akun Anda."/>

        <div class="grid min-w-0 gap-6 lg:grid-cols-[17rem_minmax(0,1fr)] lg:items-start">
            <x-ui.card class="grid justify-items-center gap-3 text-center">
                <x-ui.avatar :user="$student" class="size-[72px] text-2xl"/>
                <h2 class="text-lg">{{ $student->name }}</h2>
                @if ($student->is_verified)
                    <x-ui.badge tone="warning">
                        <img src="{{ asset('design/profile-verified.svg') }}" alt="" width="16" height="16">
                        KTM Terverifikasi
                    </x-ui.badge>
                @else
                    <x-ui.button variant="secondary" :href="route('ktm.index')">Lihat Status KTM</x-ui.button>
                @endif
                <a href="{{ route('studio.payment') }}" class="text-sm font-semibold text-campus-link">Rekening &amp; QRIS Seller</a>
            </x-ui.card>

            <div class="stack">
            <x-ui.card class="stack">
                <x-ui.section-header title="Informasi profil" :level="2"/>

                <form action="{{ route('profile.update') }}" method="post" enctype="multipart/form-data" class="stack">
                    @csrf
                    @method('PATCH')

                    <div class="flex flex-wrap items-center gap-4">
                        <div class="min-w-0 flex-1">
                            <x-ui.file-upload name="avatar" label="Foto profil" hint="JPG, JPEG, atau PNG, maksimal 5 MB."/>
                            @if ($student->avatar_path)
                                <label class="mt-2 inline-flex items-center gap-2 text-sm">
                                    <input name="remove_avatar" type="checkbox" value="1" @checked(old('remove_avatar'))>
                                    Hapus foto saat ini
                                </label>
                            @endif
                        </div>
                    </div>

                    <div class="form-grid">
                        @if ($nameLocked)
                            <div>
                                <x-ui.input name="locked_name" id="name" label="Nama lengkap" :value="$student->name" disabled
                                    help="Nama terkunci selama verifikasi KTM atau setelah disetujui."/>
                                @error('name')<p class="field-error">{{ $message }}</p>@enderror
                            </div>
                        @else
                            <x-ui.input name="name" label="Nama lengkap" :value="$student->name"
                                autocomplete="name" minlength="2" maxlength="100" required/>
                        @endif

                        <div>
                            <x-ui.input name="campus_email" id="email" label="Email kampus" type="email" :value="$student->email"
                                help="Email kampus tidak dapat diubah." disabled/>
                            @error('email')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <x-ui.input name="phone_number" label="Nomor WhatsApp" type="tel" :value="$student->phone_number" autocomplete="tel" required/>
                    <x-ui.textarea name="bio" label="Bio" :value="$student->bio" maxlength="500" help="Opsional, maksimal 500 karakter."/>

                    <div>
                        <x-ui.button type="submit">Simpan profil</x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            <x-ui.card id="password" class="stack">
                <x-ui.section-header title="Ubah password" :level="2"/>

                <form action="{{ route('profile.password') }}" method="post" class="stack">
                    @csrf
                    @method('PUT')

                    <x-ui.input name="current_password" label="Password saat ini" type="password"
                        autocomplete="current-password" maxlength="128" required/>
                    <x-ui.input name="password" label="Password baru" type="password"
                        autocomplete="new-password" minlength="8" maxlength="128" required/>
                    <x-ui.input name="password_confirmation" label="Konfirmasi password baru" type="password"
                        autocomplete="new-password" minlength="8" maxlength="128" required/>

                    <div>
                        <x-ui.button type="submit">Ubah password</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
            </div>
        </div>
    </div>
@endsection
