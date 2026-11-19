<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class ChatChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly int $roomId,
        private readonly int $buyerId,
        private readonly int $sellerId,
        public readonly ?int $messageId = null,
    ) {}

    public function broadcastAs(): string
    {
        return 'chat.changed';
    }

    public function broadcastQueue(): string
    {
        return 'default';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.room.'.$this->roomId),
            new PrivateChannel('user.'.$this->buyerId),
            new PrivateChannel('user.'.$this->sellerId),
        ];
    }

    public function broadcastWith(): array
    {
        return ['room_id' => $this->roomId, 'message_id' => $this->messageId];
    }
}
