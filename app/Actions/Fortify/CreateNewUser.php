<?php

namespace App\Actions\Fortify;

use App\Models\Admin;
use App\Models\User;
use App\Rules\PasswordText;
use App\Services\Audit;
use App\Support\StudentPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use SensitiveParameter;

final class CreateNewUser implements CreatesNewUsers
{
    public function create(#[SensitiveParameter] array $input): User
    {
        if (is_string($input['email'] ?? null)) {
            $input['email'] = Str::lower(Str::trim($input['email']));
        }

        if (is_string($input['name'] ?? null)) {
            $input['name'] = Str::trim($input['name']);
        }

        $input['phone_number'] = StudentPhone::normalize($input['phone_number'] ?? null);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['bail', 'required', 'string', 'max:254', 'email:rfc', 'regex:/\A[^@\s]+@apps\.ipb\.ac\.id\z/u'],
            'phone_number' => ['required', 'string', StudentPhone::RULE],
            'password' => [new PasswordText(confirmed: true)],
        ], [
            'name.required' => 'Nama lengkap wajib diisi.',
            'name.min' => 'Nama lengkap minimal 2 karakter.',
            'email.required' => 'Email kampus wajib diisi.',
            'email.email' => 'Masukkan alamat email yang valid.',
            'email.regex' => 'Gunakan email kampus @apps.ipb.ac.id.',
            'phone_number.required' => 'Nomor WhatsApp wajib diisi.',
            'phone_number.regex' => 'Masukkan nomor WhatsApp Indonesia dengan awalan 08 atau +62.',
        ])->validate();

        return DB::transaction(function () use ($data): User {
            DB::selectOne('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$data['email']]);

            if (User::query()->where('email', $data['email'])->exists() || Admin::query()->where('email', $data['email'])->exists()) {
                throw ValidationException::withMessages(['email' => 'Email ini sudah terdaftar.']);
            }

            $user = User::query()->forceCreate([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone_number' => $data['phone_number'],
                'password_hash' => Hash::make($data['password']),
            ]);

            Audit::record($user, 'account.registered', $user, after: ['is_verified' => false]);

            return $user;
        });
    }
}
