<?php

use App\Models\Admin;
use App\Models\ChatRoom;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

Broadcast::channel('user.{id}', fn (User $user, string $id): bool => (string) $user->id === $id, ['guards' => ['web']]);

Broadcast::channel('admin.{id}', fn (Admin $admin, string $id): bool => (string) $admin->id === $id, ['guards' => ['admin']]);

Broadcast::channel('chat.room.{id}', function (User $user, string $id): bool {
    $key = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    $room = $key === false ? null : ChatRoom::find($key);

    return $room !== null && Gate::forUser($user)->allows('view', $room);
}, ['guards' => ['web']]);
