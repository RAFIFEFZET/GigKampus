<?php

namespace App\Services;

use App\Enums\KtmVerificationStatus;
use App\Jobs\ProcessKtmOcr;
use App\Models\Admin;
use App\Models\KtmVerificationRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class KtmVerification
{
    public function submit(User $student, UploadedFile $photo): void
    {
        DB::transaction(function () use ($student, $photo): void {
            $student = User::query()->lockForUpdate()->findOrFail($student->id);
            abort_if($student->is_suspended, 403);

            if ($student->is_verified || $student->ktmVerificationRequests()
                ->whereIn('status', [KtmVerificationStatus::Processing, KtmVerificationStatus::PendingReview])
                ->exists()) {
                throw ValidationException::withMessages(['photo' => 'Akun ini sudah terverifikasi atau masih memiliki pengajuan yang belum selesai.']);
            }

            $disk = Storage::disk('private');
            $path = $disk->putFile('ktm/'.$student->id, $photo);
            if ($path === false) {
                throw ValidationException::withMessages(['photo' => 'Foto KTM belum dapat disimpan. Coba unggah lagi.']);
            }

            DB::afterRollBack(fn () => $disk->delete($path));

            $verification = KtmVerificationRequest::query()->forceCreate([
                'user_id' => $student->id,
                'name_snapshot' => $student->name,
                'file_path' => $path,
                'original_filename' => mb_substr($photo->getClientOriginalName(), 0, 255),
                'mime_type' => $photo->getMimeType(),
                'file_size' => $photo->getSize(),
                'submitted_at' => now(),
            ]);

            Audit::record($student, 'ktm.submitted', $verification, after: [
                'status' => KtmVerificationStatus::Processing->value,
            ]);

            ProcessKtmOcr::dispatch($verification->id)->afterCommit();
        });
    }

    public function review(Admin $admin, KtmVerificationRequest $verification, bool $approve, ?string $reason): void
    {
        try {
            DB::transaction(function () use ($admin, $verification, $approve, $reason): void {
                $student = User::query()->lockForUpdate()->findOrFail($verification->user_id);
                $verification = KtmVerificationRequest::query()->lockForUpdate()->findOrFail($verification->id);

                if ($verification->status !== KtmVerificationStatus::PendingReview || $student->is_verified) {
                    throw ValidationException::withMessages(['review' => 'Pengajuan ini sudah tidak menunggu pemeriksaan.']);
                }

                if ($approve) {
                    $verification->approve($admin, $reason);
                    $student->forceFill([
                        'nim' => $verification->ocr_nim_candidate,
                        'is_verified' => true,
                    ])->saveOrFail();
                } else {
                    $verification->reject($admin, $reason);
                }

                $verification->saveOrFail();
                Audit::record($admin, 'ktm.'.$verification->status->value, $verification,
                    ['status' => KtmVerificationStatus::PendingReview->value],
                    ['status' => $verification->status->value],
                    $verification->review_reason,
                );
                Inbox::ktm(
                    $verification,
                    $approve ? 'Verifikasi KTM disetujui' : 'Verifikasi KTM ditolak',
                    $approve ? 'Identitas mahasiswa berhasil diverifikasi.' : $verification->review_reason,
                );
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->errorInfo[2] ?? '', '"users_nim_key"')) {
                throw $exception;
            }

            throw ValidationException::withMessages(['review' => 'NIM hasil OCR sudah digunakan oleh akun terverifikasi lain.']);
        }
    }
}
