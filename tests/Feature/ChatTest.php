<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Admin;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Order;
use App\Models\PriceOffer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

class ChatTest extends PostgresTestCase
{
    public function test_gig_start_reuses_an_active_prechat_and_checks_both_accounts(): void
    {
        [$buyer, $seller] = $this->accounts();
        $gig = $seller->gigs()->publiclyVisible()->firstOrFail();
        $url = "/jasa/{$gig->id}/chat";

        $this->actingAs($seller)->post($url)->assertForbidden();
        $this->actingAs($buyer)->post($url)->assertRedirect();
        $room = ChatRoom::whereBelongsTo($gig)->whereBelongsTo($buyer, 'buyer')
            ->whereNull('order_id')->where('status', 'active')->sole();
        $this->post($url)->assertRedirect(route('chat.show', $room));

        $seller->forceFill(['is_suspended' => true])->save();
        $this->post($url)->assertForbidden();
        $this->postJson("/chat/{$room->id}/pesan", $this->message())->assertForbidden();
    }

    public function test_only_the_offer_participants_can_start_its_prechat(): void
    {
        $offer = PriceOffer::where('status', 'pending')->with('serviceRequest.buyer', 'seller')->firstOrFail();
        $offer->serviceRequest->forceFill(['deadline_date' => now('Asia/Jakarta')->addDays(3)->toDateString()])->saveOrFail();
        $buyer = $offer->serviceRequest->buyer;
        $outside = User::whereNotIn('id', [$buyer->id, $offer->seller_id])->firstOrFail();
        $url = "/penawaran/{$offer->id}/chat";

        $this->actingAs($outside)->post($url)->assertForbidden();
        $this->actingAs($offer->seller)->post($url)->assertRedirect();
        $room = $offer->chatRooms()->whereNull('order_id')->where('status', 'active')->sole();
        $this->actingAs($buyer)->post($url)->assertRedirect(route('chat.show', $room));
    }

    public function test_send_is_idempotent_per_sender_and_read_receipts_belong_to_the_recipient(): void
    {
        [$buyer, $seller] = $this->accounts();
        $room = $this->makePrechat($buyer, $seller);
        $url = "/chat/{$room->id}/pesan";
        $payload = $this->message();

        $first = $this->actingAs($buyer)->postJson($url, $payload)->assertOk()->json('message_id');
        $this->postJson($url, [...$payload, 'message_body' => 'Isi berbeda'])->assertOk()
            ->assertJsonPath('message_id', $first);
        self::assertSame(1, $room->messages()->count());
        $this->assertDatabaseHas('chat_messages', ['id' => $first, 'message_body' => $payload['message_body']]);
        $this->postJson("/chat/{$room->id}/baca")->assertOk()->assertJsonPath('read_count', 0);
        $this->assertDatabaseHas('chat_messages', ['id' => $first, 'read_at' => null]);

        $this->actingAs($seller)->postJson($url, $payload)->assertConflict();
        $this->postJson("/chat/{$room->id}/baca")->assertOk()->assertJsonPath('read_count', 1);
        $read = ChatMessage::findOrFail($first)->read_at;
        self::assertNotNull($read);
        $this->postJson("/chat/{$room->id}/baca")->assertOk()->assertJsonPath('read_count', 0);
        self::assertTrue($read->equalTo(ChatMessage::findOrFail($first)->read_at));
    }

    public function test_upload_and_link_are_validated_and_chat_downloads_are_participant_only(): void
    {
        Storage::fake('private');
        [$buyer, $seller] = $this->accounts();
        $room = $this->makePrechat($buyer, $seller);
        $url = "/chat/{$room->id}/pesan";
        $this->actingAs($buyer)->postJson($url, $this->message(['message_body' => " \n "]))->assertUnprocessable();
        $this->postJson($url, $this->message(['link_url' => 'http://example.test']))->assertUnprocessable();
        $this->postJson($url, $this->message([
            'attachment' => UploadedFile::fake()->createWithContent('rencana.txt', 'Rencana pekerjaan'),
            'link_url' => 'https://example.test',
        ]))->assertUnprocessable();
        $this->postJson($url, $this->message([
            'attachment' => UploadedFile::fake()->createWithContent('invalid.pdf', 'Bukan PDF'),
        ]))->assertUnprocessable();

        $id = $this->postJson($url, $this->message([
            'message_body' => null,
            'attachment' => UploadedFile::fake()->createWithContent('rencana.txt', 'Rencana pekerjaan'),
        ]))->assertOk()->json('message_id');
        $message = ChatMessage::findOrFail($id);
        Storage::disk('private')->assertExists($message->file_path);

        $download = route('files.show', ['jenis' => 'chat_attachment', 'id' => $id]);
        $response = $this->actingAs($seller)->get($download)->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff');
        self::assertSame('Rencana pekerjaan', $response->streamedContent());

        $outside = User::whereNotIn('id', [$buyer->id, $seller->id])->firstOrFail();
        $this->actingAs($outside)->getJson($download)->assertForbidden();
        $this->postJson($url, $this->message())->assertForbidden();
        auth('web')->logout();
        $this->actingAs(Admin::firstOrFail(), 'admin')->getJson($download)->assertForbidden();
    }

    public function test_only_order_seller_may_upload_pending_refund_proof_even_during_a_dispute(): void
    {
        Storage::fake('private');
        $order = Order::whereHas('payment', fn ($query) => $query->where('refund_status', 'pending'))->firstOrFail();
        $order->forceFill(['status' => OrderStatus::Disputed])->saveOrFail();
        $room = $order->chatRoom;
        $url = "/chat/{$room->id}/pesan";
        $key = (string) Str::uuid();
        $proof = fn () => $this->message([
            'client_message_key' => $key,
            'message_type' => 'refund_proof',
            'attachment' => UploadedFile::fake()->createWithContent('bukti.png', base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a+yQAAAAASUVORK5CYII='
            )),
        ]);

        $this->actingAs($order->buyer)->postJson($url, $proof())->assertForbidden();
        $seller = $order->seller;
        $seller->forceFill(['is_suspended' => true])->saveOrFail();
        $this->actingAs($seller)->postJson($url, $this->message())->assertOk();
        $id = $this->postJson($url, $proof())->assertOk()->json('message_id');
        $this->postJson($url, $proof())->assertOk()->assertJsonPath('message_id', $id);
        $notification = $order->notifications()->where('type', 'refund.proof_submitted')->sole();
        self::assertSame($order->buyer_id, $notification->user_id);
        self::assertSame($id, $notification->data->message_id);
        self::assertSame($room->id, $notification->data->chat_room_id);
        self::assertSame(OrderStatus::Disputed, $order->refresh()->status);
        $anotherOrder = $seller->sellerOrders()->where('status', OrderStatus::InProgress)
            ->whereHas('payment', fn ($query) => $query->where('refund_status', 'none'))->firstOrFail();
        $this->postJson("/chat/{$anotherOrder->chatRoom->id}/pesan", $proof())->assertForbidden();
    }

    public function test_archived_room_keeps_attachments_readable_and_blocks_message_and_receipt_writes(): void
    {
        Storage::fake('private');
        [$buyer, $seller] = $this->accounts();
        $room = $this->makePrechat($buyer, $seller);
        $url = "/chat/{$room->id}/pesan";
        $payload = $this->message([
            'attachment' => UploadedFile::fake()->createWithContent('rencana.txt', 'Rencana pekerjaan'),
        ]);
        $id = $this->actingAs($buyer)->postJson($url, $payload)->assertOk()->json('message_id');
        $room->archive();
        $room->saveOrFail();

        $this->postJson($url, ['client_message_key' => $payload['client_message_key']])->assertOk()
            ->assertJsonPath('message_id', $id);
        $this->actingAs($seller)->postJson($url, $this->message())->assertForbidden();
        $this->postJson("/chat/{$room->id}/baca")->assertForbidden();
        $this->assertDatabaseHas('chat_messages', ['id' => $id, 'read_at' => null]);
        $this->get(route('files.show', ['jenis' => 'chat_attachment', 'id' => $id]))->assertOk();
    }

    public function test_read_only_updates_incoming_messages_up_to_a_message_in_the_same_room(): void
    {
        [$buyer, $seller] = $this->accounts();
        $room = $this->makePrechat($buyer, $seller);
        $url = "/chat/{$room->id}/pesan";
        $readUrl = "/chat/{$room->id}/baca";

        $link = 'https://example.test/rencana';
        $first = $this->actingAs($buyer)->postJson($url, $this->message([
            'message_body' => null, 'link_url' => $link,
        ]))->assertOk()->json('message_id');
        $second = $this->postJson($url, $this->message())->assertOk()->json('message_id');
        $outsideMessage = ChatMessage::where('chat_room_id', '!=', $room->id)->firstOrFail();

        $this->actingAs($seller)->postJson($readUrl, ['message_id' => $outsideMessage->id])->assertNotFound();
        $this->postJson($readUrl, ['message_id' => $first])->assertOk()->assertJsonPath('read_count', 1);
        $linkMessage = ChatMessage::findOrFail($first);
        self::assertSame($link, $linkMessage->link_url);
        self::assertNotNull($linkMessage->read_at);
        $this->assertDatabaseHas('chat_messages', ['id' => $second, 'read_at' => null]);
    }

    private function accounts(): array
    {
        return [
            User::where('email', 'buyer.demo@apps.ipb.ac.id')->sole(),
            User::where('email', 'seller.demo@apps.ipb.ac.id')->sole(),
        ];
    }

    private function makePrechat(User $buyer, User $seller): ChatRoom
    {
        $gig = $seller->gigs()->publiclyVisible()->firstOrFail();
        $room = $gig->chatRooms()->whereBelongsTo($buyer, 'buyer')->whereNull('order_id')
            ->where('status', 'active')->first();
        if ($room !== null) {
            $room->archive();
            $room->saveOrFail();
        }

        return $gig->chatRooms()->forceCreate(['buyer_id' => $buyer->id, 'seller_id' => $seller->id]);
    }

    private function message(array $overrides = []): array
    {
        return ['client_message_key' => (string) Str::uuid(), 'message_body' => 'Diskusi pekerjaan', ...$overrides];
    }
}
