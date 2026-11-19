<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Admin extends BaseAccount
{
    protected $table = 'admins';

    public function verifiedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'verified_by_admin_id');
    }

    public function confirmedRefunds(): HasMany
    {
        return $this->hasMany(Payment::class, 'refund_confirmed_by_admin_id');
    }

    public function hiddenReviews(): HasMany
    {
        return $this->hasMany(Review::class, 'hidden_by_admin_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function handledReports(): HasMany
    {
        return $this->hasMany(Report::class, 'handled_by_admin_id');
    }

    public function requestedClarifications(): HasMany
    {
        return $this->hasMany(ReportClarification::class, 'requested_by_admin_id');
    }

    public function reviewedKtmVerificationRequests(): HasMany
    {
        return $this->hasMany(KtmVerificationRequest::class, 'reviewed_by_admin_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_admin_id');
    }
}
