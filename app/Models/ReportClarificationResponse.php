<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportClarificationResponse extends Model
{
    use HasPreciseTimestamps;

    protected $table = 'report_clarification_responses';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'clarification_id' => 'integer',
            'respondent_id' => 'integer',
            'file_size' => 'integer',
            'submitted_at' => 'immutable_datetime',
        ];
    }

    public function clarification(): BelongsTo
    {
        return $this->belongsTo(ReportClarification::class, 'clarification_id');
    }

    public function respondent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondent_id');
    }

    public function isLate(): bool
    {
        return $this->clarification->isOverdue($this->submitted_at);
    }
}
