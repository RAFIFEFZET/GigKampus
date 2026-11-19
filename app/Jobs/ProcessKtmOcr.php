<?php

namespace App\Jobs;

use App\Enums\KtmVerificationStatus;
use App\Models\KtmVerificationRequest;
use App\Models\User;
use App\Services\Audit;
use App\Services\Inbox;
use App\Services\KtmOcrService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessKtmOcr implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 45;

    public int $backoff = 5;

    public function __construct(public int $verificationId)
    {
        $this->onQueue('ocr');
    }

    public function handle(KtmOcrService $ocr): void
    {
        $request = KtmVerificationRequest::query()->find($this->verificationId);
        if ($request?->status !== KtmVerificationStatus::Processing) {
            return;
        }

        $candidates = $ocr->candidates($request->file_path);
        match (count($candidates)) {
            1 => $this->finish(candidate: $candidates[0]),
            0 => $this->finish(error: 'NIM belum terbaca. Unggah ulang foto yang menampilkan NIM dengan jelas.'),
            default => $this->finish(error: 'Terdapat lebih dari satu kandidat NIM. Unggah ulang satu foto KTM yang jelas.'),
        };
    }

    public function failed(): void
    {
        $this->finish(error: 'Pemindaian KTM belum berhasil. Unggah ulang foto untuk mencoba lagi.');
    }

    private function finish(?string $candidate = null, ?string $error = null): void
    {
        DB::transaction(function () use ($candidate, $error): void {
            $studentId = KtmVerificationRequest::query()->whereKey($this->verificationId)->value('user_id');
            if ($studentId === null) {
                return;
            }

            User::query()->lock('for no key update')->findOrFail($studentId);
            $request = KtmVerificationRequest::query()->lockForUpdate()->find($this->verificationId);
            if ($request?->status !== KtmVerificationStatus::Processing) {
                return;
            }

            if ($candidate !== null) {
                $request->recordOcrCandidate($candidate);
            } else {
                $request->recordOcrFailure($error);
            }

            $request->saveOrFail();
            Audit::record(null, 'ktm.'.$request->status->value, $request,
                ['status' => KtmVerificationStatus::Processing->value],
                ['status' => $request->status->value],
            );
            if ($error !== null) {
                Inbox::ktm($request, 'Foto KTM perlu diunggah ulang', $error);
            }
        });
    }
}
