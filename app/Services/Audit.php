<?php

namespace App\Services;

use App\Enums\ActorType;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class Audit
{
    public static function record(
        User|Admin|null $actor,
        string $action,
        Model $entity,
        array $before = [],
        array $after = [],
        ?string $reason = null,
    ): void {
        $type = match (true) {
            $actor instanceof User => ActorType::User,
            $actor instanceof Admin => ActorType::Admin,
            default => ActorType::System,
        };

        AuditLog::query()->forceCreate([
            'actor_type' => $type,
            'actor_user_id' => $type === ActorType::User ? $actor->getKey() : null,
            'actor_admin_id' => $type === ActorType::Admin ? $actor->getKey() : null,
            'action' => $action,
            'entity_type' => $entity->getTable(),
            'entity_id' => $entity->getKey(),
            'order_id' => $entity instanceof Order ? $entity->getKey() : null,
            'before_data' => $before ?: null,
            'after_data' => $after ?: null,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }
}
