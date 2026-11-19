<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class NotificationChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        private readonly string $recipientType,
        private readonly int $recipientId,
        private readonly ?int $notificationId = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->recipientType.'.'.$this->recipientId);
    }

    public function broadcastAs(): string
    {
        return 'notification.changed';
    }

    public function broadcastQueue(): string
    {
        return 'default';
    }

    public function broadcastWith(): array
    {
        return $this->notificationId === null ? [] : ['notification_id' => $this->notificationId];
    }
}
