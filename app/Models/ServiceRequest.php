<?php

namespace App\Models;

use App\Casts\WholeRupiah;
use App\Enums\ServiceRequestStatus;
use App\Models\Concerns\HasPreciseTimestamps;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceRequest extends Model
{
    use HasPreciseTimestamps;

    protected $table = 'service_requests';

    protected $attributes = [
        'status' => ServiceRequestStatus::Open->value,
        'is_hidden' => false,
        'version' => 1,
    ];

    protected $fillable = ['title', 'description', 'budget_min', 'budget_max', 'deadline_date'];

    protected function casts(): array
    {
        return [
            'buyer_id' => 'integer',
            'category_id' => 'integer',
            'budget_min' => WholeRupiah::class,
            'budget_max' => WholeRupiah::class,
            'deadline_date' => 'immutable_date:Y-m-d',
            'status' => ServiceRequestStatus::class,
            'is_hidden' => 'boolean',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function priceOffers(): HasMany
    {
        return $this->hasMany(PriceOffer::class, 'service_request_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'service_request_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $request): void {
            if ($request->isDirty([...$request->getFillable(), 'category_id'])) {
                $request->version = $request->getOriginal('version') + 1;
            }
        });
    }

    public function acceptsOffers(?CarbonInterface $at = null): bool
    {
        $today = CarbonImmutable::instance($at ?? now())->setTimezone('Asia/Jakarta')->toDateString();

        return $this->status === ServiceRequestStatus::Open
            && ! $this->is_hidden
            && $this->deadline_date !== null
            && $this->deadline_date->toDateString() >= $today
            && $this->buyer?->canCreateNewTransaction() === true;
    }

    public function canChangeTerms(): bool
    {
        return $this->status === ServiceRequestStatus::Open && ! $this->priceOffers()->exists();
    }

    public function close(): void
    {
        $this->assertOpen();
        $this->status = ServiceRequestStatus::Closed;
    }

    public function markOrdered(?CarbonInterface $at = null): void
    {
        if (! $this->acceptsOffers($at)) {
            throw new DomainException('Only an eligible request can be ordered.');
        }

        $this->status = ServiceRequestStatus::Ordered;
    }

    private function assertOpen(): void
    {
        if ($this->status !== ServiceRequestStatus::Open) {
            throw new DomainException('Only an open request can be closed.');
        }
    }
}
