<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Review extends Model
{
    use HasPreciseTimestamps;

    public const CREATED_AT = 'review_date';

    public const UPDATED_AT = null;

    protected $table = 'reviews';

    protected $attributes = [
        'is_hidden' => false,
    ];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'reviewer_id' => 'integer',
            'seller_id' => 'integer',
            'rating' => 'integer',
            'is_hidden' => 'boolean',
            'hidden_by_admin_id' => 'integer',
            'hidden_at' => 'immutable_datetime',
            'review_date' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function hiddenByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'hidden_by_admin_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'review_id');
    }

    /** @param Builder<Review> $query */
    public function scopeVisible(Builder $query): void
    {
        $query->where('is_hidden', false);
    }

    public function hide(Admin $admin, string $reason, ?CarbonInterface $at = null): void
    {
        $reason = trim($reason);

        if ($admin->getKey() === null || $this->is_hidden || mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
            throw new DomainException('Hiding a visible review requires an admin and a reason of 5–1000 characters.');
        }

        $this->is_hidden = true;
        $this->hidden_by_admin_id = $admin->getKey();
        $this->hide_reason = $reason;
        $this->hidden_at = $at ?? now();
    }
}
