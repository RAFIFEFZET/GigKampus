<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportClarification extends Model
{
    use HasPreciseTimestamps;

    protected $table = 'report_clarifications';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'report_id' => 'integer',
            'requested_by_admin_id' => 'integer',
            'recipient_user_id' => 'integer',
            'requested_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function requestedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'requested_by_admin_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(ReportClarificationResponse::class, 'clarification_id');
    }

    public function isOverdue(?CarbonInterface $at = null): bool
    {
        return $this->due_at->lessThan($at ?? now());
    }
}
