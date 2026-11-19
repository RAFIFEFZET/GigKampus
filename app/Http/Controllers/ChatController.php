<?php

namespace App\Http\Controllers;

use App\Models\ChatRoom;
use App\Models\Gig;
use App\Models\Order;
use App\Models\PriceOffer;
use App\Services\ChatService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ChatController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', ChatRoom::class);

        return view('pages.chat.index', ['room' => null]);
    }

    public function show(ChatRoom $room): View
    {
        Gate::authorize('view', $room);

        return view('pages.chat.index', ['room' => $room]);
    }

    public function gig(Request $request, Gig $gig, ChatService $chat): RedirectResponse
    {
        return to_route('chat.show', $chat->startGig($request->user(), $gig));
    }

    public function offer(Request $request, PriceOffer $offer, ChatService $chat): RedirectResponse
    {
        return to_route('chat.show', $chat->startOffer($request->user(), $offer));
    }

    public function order(Request $request, Order $order): View
    {
        abort_unless($order->hasParticipant($request->user()), 403);

        return $this->show($order->chatRoom()->firstOrFail());
    }

    public function store(Request $request, ChatRoom $room, ChatService $chat): JsonResponse|RedirectResponse
    {
        $message = $chat->send($request->user(), $room, $request->only(
            'client_message_key', 'message_type', 'message_body', 'link_url', 'attachment',
        ));

        return $request->expectsJson()
            ? response()->json(['message_id' => $message->id])
            : to_route('chat.show', $room);
    }

    public function read(Request $request, ChatRoom $room, ChatService $chat): JsonResponse|RedirectResponse
    {
        Gate::authorize('view', $room);
        $data = $request->validate(['message_id' => ['sometimes', 'required', 'integer', 'min:1']]);
        $through = isset($data['message_id']) ? (int) $data['message_id'] : null;
        $count = $chat->markRead($request->user(), $room, $through);

        return $request->expectsJson()
            ? response()->json(['read_count' => $count])
            : to_route('chat.show', $room);
    }
}
