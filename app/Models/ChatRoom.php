<?php

namespace App\Models;

use App\Enums\ChatRoomStatus;
use App\Models\Concerns\HasPreciseTimestamps;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatRoom extends Model
{
    use HasPreciseTimestamps;

    public const UPDATED_AT = null;

    protected $table = 'chat_rooms';

    protected $attributes = [
        'status' => ChatRoomStatus::Active->value,
    ];

    protected function casts(): array
    {
        return [
            'buyer_id' => 'integer',
            'seller_id' => 'integer',
            'gig_id' => 'integer',
            'price_offer_id' => 'integer',
            'order_id' => 'integer',
            'status' => ChatRoomStatus::class,
            'created_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function gig(): BelongsTo
    {
        return $this->belongsTo(Gig::class, 'gig_id');
    }

    public function priceOffer(): BelongsTo
    {
        return $this->belongsTo(PriceOffer::class, 'price_offer_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'chat_room_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'chat_room_id');
    }

    public function hasParticipant(User $user): bool
    {
        return $user->hasId($this->buyer_id) || $user->hasId($this->seller_id);
    }

    public function isArchived(): bool
    {
        return $this->status === ChatRoomStatus::Archived;
    }

    public function archive(?CarbonInterface $at = null): void
    {
        if (! $this->isArchived()) {
            $this->status = ChatRoomStatus::Archived;
            $this->archived_at = $at ?? now();
        }
    }
}
