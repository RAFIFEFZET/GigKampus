<?php

namespace App\Models;

use App\Casts\WholeRupiah;
use App\Enums\GigStatus;
use App\Models\Concerns\HasPreciseTimestamps;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gig extends Model
{
    use HasPreciseTimestamps;

    protected $table = 'gigs';

    protected $attributes = [
        'status' => GigStatus::Active->value,
        'version' => 1,
    ];

    protected $fillable = ['title', 'description', 'base_price', 'delivery_days', 'max_revisions'];

    protected function casts(): array
    {
        return [
            'seller_id' => 'integer',
            'category_id' => 'integer',
            'base_price' => WholeRupiah::class,
            'delivery_days' => 'integer',
            'max_revisions' => 'integer',
            'status' => GigStatus::class,
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'gig_id');
    }

    public function chatRooms(): HasMany
    {
        return $this->hasMany(ChatRoom::class, 'gig_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'gig_id');
    }

    public function scopePubliclyVisible(Builder $query): void
    {
        $query->where('status', GigStatus::Active)
            ->whereHas('category', fn (Builder $category) => $category->active())
            ->whereHas('seller', fn (Builder $seller) => $seller->canListGigs());
    }

    public function scopeWithSellerRating(Builder $query): void
    {
        $query->addSelect(['seller_rating' => Review::visible()
            ->whereColumn('reviews.seller_id', 'gigs.seller_id')
            ->selectRaw('avg(rating)')]);
    }

    protected static function booted(): void
    {
        static::updating(function (self $gig): void {
            if ($gig->isDirty([...$gig->getFillable(), 'category_id', 'portfolio_path', 'status'])) {
                $gig->version = $gig->getOriginal('version') + 1;
            }
        });
    }

    public function activate(User $seller): void
    {
        if (! $seller->hasId($this->seller_id) || ! $seller->canPublishGig()
            || $this->status === GigStatus::Suspended) {
            throw new DomainException('Only an eligible seller can activate their non-suspended gig.');
        }

        $this->status = GigStatus::Active;
    }

    public function deactivate(User $seller): void
    {
        if (! $seller->hasId($this->seller_id) || $this->status === GigStatus::Suspended) {
            throw new DomainException('Only the seller can deactivate their non-suspended gig.');
        }

        $this->status = GigStatus::Inactive;
    }

    public function suspend(): void
    {
        $this->status = GigStatus::Suspended;
    }

    public function liftSuspension(): void
    {
        if ($this->status !== GigStatus::Suspended) {
            throw new DomainException('Only a suspended gig can have its suspension lifted.');
        }

        $this->status = GigStatus::Inactive;
    }
}
