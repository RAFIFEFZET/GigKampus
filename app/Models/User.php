<?php

namespace App\Models;

use App\Contracts\BuyerInterface;
use App\Contracts\SellerInterface;
use App\Models\Concerns\HasBuyerCapabilities;
use App\Models\Concerns\HasSellerCapabilities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends BaseAccount implements BuyerInterface, SellerInterface
{
    use HasBuyerCapabilities, HasSellerCapabilities;

    protected $table = 'users';

    protected $attributes = [
        'is_verified' => false,
        'is_suspended' => false,
    ];

    protected $fillable = ['name', 'phone_number', 'bio'];

    protected $hidden = [
        'password_hash',
        'email',
        'phone_number',
        'nim',
        'bank_id',
        'bank_account_number',
        'bank_account_holder_name',
        'qris_image_path',
        'qris_holder_name',
    ];

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'is_verified' => 'boolean',
            'is_suspended' => 'boolean',
            'bank_id' => 'integer',
            'bank_account_number' => 'string',
        ];
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(Order::class, 'cancellation_requested_by_id');
    }

    public function verifiedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'verified_by_user_id');
    }

    public function confirmedRefunds(): HasMany
    {
        return $this->hasMany(Payment::class, 'refund_confirmed_by_user_id');
    }

    public function reviewedPaymentProofs(): HasMany
    {
        return $this->hasMany(PaymentProof::class, 'reviewed_by_seller_id');
    }

    public function sentChatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'sender_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function submittedReports(): HasMany
    {
        return $this->hasMany(Report::class, 'reporter_id');
    }

    public function receivedClarifications(): HasMany
    {
        return $this->hasMany(ReportClarification::class, 'recipient_user_id');
    }

    public function clarificationResponses(): HasMany
    {
        return $this->hasMany(ReportClarificationResponse::class, 'respondent_id');
    }

    public function ktmVerificationRequests(): HasMany
    {
        return $this->hasMany(KtmVerificationRequest::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_user_id');
    }

    public function canCreateNewTransaction(): bool
    {
        return $this->is_verified && ! $this->is_suspended;
    }

    public function scopeEligibleStudent(Builder $query): void
    {
        $query->where('is_verified', true)->where('is_suspended', false);
    }

    public function scopeCanListGigs(Builder $query): void
    {
        $query->eligibleStudent()->where(fn (Builder $methods) => $methods
            ->whereNotNull('bank_id')
            ->orWhereNotNull('qris_image_path'));
    }

    public function canAccessExistingOrder(Order $order): bool
    {
        return $order->hasParticipant($this);
    }
}
