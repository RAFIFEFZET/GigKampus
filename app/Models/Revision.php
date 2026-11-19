<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\RevisionStatus;
use App\Models\Concerns\HasPreciseTimestamps;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Revision extends Model
{
    use HasPreciseTimestamps;

    protected $table = 'revisions';

    public $timestamps = false;

    protected $attributes = [
        'status' => RevisionStatus::Requested->value,
    ];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'revision_no' => 'integer',
            'status' => RevisionStatus::class,
            'requested_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(OrderDelivery::class, 'revision_id');
    }

    public function start(User $seller, Order $order): void
    {
        $this->assertCanWork($seller, $order);

        if ($this->status !== RevisionStatus::Requested) {
            throw new DomainException('Only a requested revision can start.');
        }

        $this->status = RevisionStatus::InProgress;
    }

    public function complete(User $seller, Order $order, ?CarbonInterface $at = null): void
    {
        $this->assertCanWork($seller, $order);
        $this->status = RevisionStatus::Completed;
        $this->completed_at = $at ?? now();
    }

    private function assertCanWork(User $seller, Order $order): void
    {
        if ($order->getKey() === null || $order->getKey() !== $this->order_id
            || ! $order->hasSeller($seller) || $order->status !== OrderStatus::Revision
            || $this->status === RevisionStatus::Completed) {
            throw new DomainException('Only the seller can work on an open revision for this order.');
        }
    }
}
