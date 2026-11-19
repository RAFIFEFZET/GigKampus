<?php

namespace App\Http\Controllers\Identity;

use App\Services\StudentProfile;
use App\Support\StudentPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

final class ProfileController
{
    public function show(Request $request, StudentProfile $profiles): View
    {
        $user = $request->user();

        return view('pages.profile.index', [
            'student' => $user,
            'nameLocked' => $profiles->isNameLocked($user),
        ]);
    }

    public function update(Request $request, StudentProfile $profiles): RedirectResponse
    {
        if ($request->has('phone_number')) {
            $request->merge(['phone_number' => StudentPhone::normalize($request->input('phone_number'))]);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'email' => ['prohibited'],
            'phone_number' => ['sometimes', 'required', 'string', StudentPhone::RULE],
            'bio' => ['nullable', 'string', 'max:500'],
            'avatar' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'remove_avatar' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'Nama lengkap wajib diisi.',
            'name.min' => 'Nama lengkap minimal 2 karakter.',
            'email.prohibited' => 'Email kampus tidak dapat diubah.',
            'phone_number.required' => 'Nomor WhatsApp wajib diisi.',
            'phone_number.regex' => 'Masukkan nomor WhatsApp Indonesia dengan awalan 08 atau +62.',
            'bio.max' => 'Bio maksimal 500 karakter.',
            'avatar.image' => 'Unggah foto JPG, JPEG, atau PNG.',
            'avatar.mimes' => 'Unggah foto JPG, JPEG, atau PNG.',
            'avatar.max' => 'Ukuran foto maksimal 5 MB.',
        ]);

        $profiles->update($request->user(), $data);

        return redirect()->route('profile')->with('status', 'Profil berhasil diperbarui.');
    }

    public function password(Request $request, UpdatesUserPasswords $passwords): RedirectResponse
    {
        $passwords->update($request->user(), $request->all());
        $request->session()->regenerate(true);

        return redirect()->route('profile')->with('status', 'Password berhasil diubah.');
    }
}
