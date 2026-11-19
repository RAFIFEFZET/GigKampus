<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasPreciseTimestamps;

    protected $table = 'audit_logs';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'actor_user_id' => 'integer',
            'actor_admin_id' => 'integer',
            'entity_id' => 'integer',
            'order_id' => 'integer',
            'report_id' => 'integer',
            'before_data' => 'array',
            'after_data' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actorAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'actor_admin_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }
}
