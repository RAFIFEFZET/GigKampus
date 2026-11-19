<?php

namespace App\Models;

use App\Enums\ChatMessageType;
use App\Models\Concerns\HasPreciseTimestamps;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasPreciseTimestamps;

    public const CREATED_AT = 'sent_at';

    public const UPDATED_AT = null;

    protected $table = 'chat_messages';

    protected $attributes = [
        'message_type' => ChatMessageType::Regular->value,
    ];

    protected function casts(): array
    {
        return [
            'chat_room_id' => 'integer',
            'sender_id' => 'integer',
            'client_message_key' => 'string',
            'message_type' => ChatMessageType::class,
            'file_size' => 'integer',
            'sent_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
        ];
    }

    public function chatRoom(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'chat_room_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function markReadBy(User $reader, ChatRoom $room, ?CarbonInterface $at = null): void
    {
        if ($room->getKey() === null || $room->getKey() !== $this->chat_room_id
            || ! $room->hasParticipant($reader) || $reader->hasId($this->sender_id)) {
            throw new DomainException('Only the recipient can mark this message as read.');
        }

        $this->read_at ??= $at ?? now();
    }
}
