<?php

namespace App\Services;

use App\Enums\KtmVerificationStatus;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StudentProfile
{
    public function isNameLocked(User $user): bool
    {
        return $user->is_verified || $user->ktmVerificationRequests()->whereIn('status', [
            KtmVerificationStatus::Processing->value,
            KtmVerificationStatus::PendingReview->value,
            KtmVerificationStatus::Approved->value,
        ])->exists();
    }

    public function update(User $user, array $data): void
    {
        $newAvatar = null;

        try {
            DB::transaction(function () use ($user, $data, &$newAvatar): void {
                $account = User::query()->lockForUpdate()->findOrFail($user->getKey());

                if (isset($data['name']) && $data['name'] !== $account->name && $this->isNameLocked($account)) {
                    throw ValidationException::withMessages([
                        'name' => 'Nama terkunci selama verifikasi KTM berlangsung atau setelah disetujui.',
                    ]);
                }

                $oldAvatar = $account->avatar_path;
                $account->fill(Arr::only($data, ['name', 'phone_number', 'bio']));

                if (isset($data['avatar'])) {
                    $newAvatar = $data['avatar']->store("avatars/{$account->id}", 'public');
                    abort_unless(is_string($newAvatar), 500, 'Foto profil gagal disimpan.');
                    $account->avatar_path = $newAvatar;
                } elseif ($data['remove_avatar'] ?? false) {
                    $account->avatar_path = null;
                }

                $changed = array_keys($account->getDirty());
                if ($changed === []) {
                    return;
                }

                $account->save();
                Audit::record($account, 'account.profile_updated', $account, after: ['changed_fields' => $changed]);

                if ($oldAvatar !== $account->avatar_path && str_starts_with($oldAvatar ?? '', "avatars/{$account->id}/")) {
                    DB::afterCommit(fn () => Storage::disk('public')->delete($oldAvatar));
                }
            });
        } catch (Throwable $exception) {
            if (is_string($newAvatar)) {
                Storage::disk('public')->delete($newAvatar);
            }

            throw $exception;
        }
    }
}
