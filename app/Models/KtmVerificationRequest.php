<?php

namespace App\Models;

use App\Enums\KtmVerificationStatus;
use App\Models\Concerns\HasPreciseTimestamps;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KtmVerificationRequest extends Model
{
    use HasPreciseTimestamps;

    protected $table = 'ktm_verification_requests';

    public $timestamps = false;

    protected $attributes = [
        'status' => KtmVerificationStatus::Processing->value,
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'file_size' => 'integer',
            'status' => KtmVerificationStatus::class,
            'reviewed_by_admin_id' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'ocr_finished_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }

    public function recordOcrCandidate(string $candidate, ?CarbonInterface $at = null): void
    {
        $this->assertProcessing();

        if (preg_match('/\A[A-Z0-9]{1,20}\z/', $candidate) !== 1) {
            throw new DomainException('The OCR candidate must be 1–20 uppercase letters or digits.');
        }

        $this->status = KtmVerificationStatus::PendingReview;
        $this->ocr_nim_candidate = $candidate;
        $this->ocr_finished_at = $at ?? now();
    }

    public function recordOcrFailure(string $error, ?CarbonInterface $at = null): void
    {
        $this->assertProcessing();
        $error = trim($error);

        if ($error === '' || mb_strlen($error) > 1000) {
            throw new DomainException('An OCR failure reason must have 1–1000 characters.');
        }

        $this->status = KtmVerificationStatus::OcrFailed;
        $this->ocr_error = $error;
        $this->ocr_finished_at = $at ?? now();
    }

    public function approve(Admin $admin, ?string $reason = null, ?CarbonInterface $at = null): void
    {
        $this->recordReview($admin, KtmVerificationStatus::Approved, $reason, $at);
    }

    public function reject(Admin $admin, string $reason, ?CarbonInterface $at = null): void
    {
        $this->recordReview($admin, KtmVerificationStatus::Rejected, $reason, $at);
    }

    private function assertProcessing(): void
    {
        if ($this->status !== KtmVerificationStatus::Processing) {
            throw new DomainException('OCR can only finish a request that is processing.');
        }
    }

    private function recordReview(Admin $admin, KtmVerificationStatus $status, ?string $reason, ?CarbonInterface $at): void
    {
        $reason = $reason === null ? null : trim($reason);
        $reason = $reason === '' ? null : $reason;
        $length = mb_strlen($reason ?? '');

        if ($this->status !== KtmVerificationStatus::PendingReview || $admin->getKey() === null
            || ($status === KtmVerificationStatus::Rejected && $reason === null)
            || ($reason !== null && ($length < 5 || $length > 1000))) {
            throw new DomainException('A pending KTM request requires an admin and a valid review reason.');
        }

        $this->status = $status;
        $this->reviewed_by_admin_id = $admin->getKey();
        $this->review_reason = $reason;
        $this->reviewed_at = $at ?? now();
    }
}
