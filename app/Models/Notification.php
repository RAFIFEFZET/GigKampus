<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasPreciseTimestamps;

    public const UPDATED_AT = null;

    protected $table = 'notifications';

    protected $attributes = [
        'data' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'admin_id' => 'integer',
            'order_id' => 'integer',
            'report_id' => 'integer',
            'data' => 'object',
            'created_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function markReadBy(User|Admin $recipient, ?CarbonInterface $at = null): void
    {
        $recipientId = $recipient instanceof User ? $this->user_id : $this->admin_id;

        if (! $recipient->hasId($recipientId)) {
            throw new DomainException('Only the recipient can mark this notification as read.');
        }

        $this->read_at ??= $at ?? now();
    }
}
