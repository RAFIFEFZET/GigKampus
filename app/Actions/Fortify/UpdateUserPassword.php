<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Rules\PasswordText;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use SensitiveParameter;

final class UpdateUserPassword implements UpdatesUserPasswords
{
    public function update(User $user, #[SensitiveParameter] array $input): void
    {
        $data = Validator::make($input, [
            'current_password' => [new PasswordText(min: 1, message: 'Masukkan kata sandi saat ini.')],
            'password' => [new PasswordText(confirmed: true)],
        ])->validate();

        DB::transaction(function () use ($user, $data): void {
            $account = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if (! Hash::check($data['current_password'], $account->getAuthPassword())) {
                throw ValidationException::withMessages(['current_password' => 'Password saat ini tidak sesuai.']);
            }

            $account->forceFill(['password_hash' => Hash::make($data['password'])])->save();
            Audit::record($account, 'account.password_changed', $account);
        });
    }
}
