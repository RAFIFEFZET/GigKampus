<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDelivery extends Model
{
    use HasPreciseTimestamps;

    protected $table = 'order_deliveries';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'revision_id' => 'integer',
            'file_size' => 'integer',
            'submitted_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(Revision::class, 'revision_id');
    }

    public function isFirstDelivery(): bool
    {
        return $this->revision_id === null;
    }
}
