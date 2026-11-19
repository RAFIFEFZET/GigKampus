<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ChatRoom;
use App\Models\User;
use Tests\PostgresTestCase;

class BroadcastAuthorizationTest extends PostgresTestCase
{
    public function test_channels_bind_to_guard_recipient_and_room_participation(): void
    {
        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');
        $room = ChatRoom::firstOrFail();
        $member = $room->buyer;
        $stranger = User::whereNotIn('id', [$room->buyer_id, $room->seller_id])->firstOrFail();
        $admin = Admin::firstOrFail();

        $this->postJson('/broadcasting/auth', ['socket_id' => '10.20', 'channel_name' => 'private-user.'.$member->id])
            ->assertUnauthorized();
        $this->actingAs($member, 'web');
        $this->authorizeChannel('user.'.$member->id)->assertOk()->assertJsonStructure(['auth']);
        $this->authorizeChannel('chat.room.'.$room->id)->assertOk();
        $this->authorizeChannel('user.'.$stranger->id)->assertForbidden();
        $this->authorizeChannel('admin.'.$admin->id)->assertForbidden();

        $this->actingAs($stranger, 'web');
        $this->authorizeChannel('chat.room.'.$room->id)->assertForbidden();
        auth('web')->logout();

        $this->actingAs($admin, 'admin');
        $this->authorizeChannel('admin.'.$admin->id)->assertOk()->assertJsonStructure(['auth']);
        $this->authorizeChannel('user.'.$admin->id)->assertForbidden();
        $this->authorizeChannel('chat.room.'.$room->id)->assertForbidden();
    }

    private function authorizeChannel(string $channel): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/broadcasting/auth', [
            'socket_id' => '10.20',
            'channel_name' => 'private-'.$channel,
        ]);
    }
}
