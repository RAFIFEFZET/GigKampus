<?php

namespace App\Services;

use App\Enums\ChatMessageType;
use App\Enums\ChatRoomStatus;
use App\Events\ChatChanged;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Gig;
use App\Models\Order;
use App\Models\PriceOffer;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Orders\CheckoutOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

final class ChatService
{
    private const FILE_TYPES = [
        'pdf' => ['application/pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    public function __construct(private readonly CheckoutOrder $checkout) {}

    public function startGig(User $actor, Gig $gig): ChatRoom
    {
        abort_if($actor->hasId($gig->seller_id), 403);

        return DB::transaction(function () use ($actor, $gig): ChatRoom {
            $accounts = $this->checkout->lockAccounts($actor->id, $gig->seller_id);
            $buyer = $accounts->get($actor->id);
            $seller = $accounts->get($gig->seller_id);

            $gig = Gig::query()->lock('for no key update')->findOrFail($gig->id);
            abort_unless($seller->hasId($gig->seller_id), 403);
            $gig->setRelation('seller', $seller);
            $gig->setRelation('category', $gig->category()->sharedLock()->firstOrFail());
            Gate::forUser($buyer)->authorize('order', $gig);

            return $this->activePrechat($buyer->id, $seller->id, $gig);
        }, 3);
    }

    public function startOffer(User $actor, PriceOffer $offer): ChatRoom
    {
        $buyerId = $offer->serviceRequest->buyer_id;
        abort_unless($actor->hasId($buyerId) || $actor->hasId($offer->seller_id), 403);

        return DB::transaction(function () use ($actor, $buyerId, $offer): ChatRoom {
            $accounts = $this->checkout->lockAccounts($buyerId, $offer->seller_id);
            $buyer = $accounts->get($buyerId);
            $seller = $accounts->get($offer->seller_id);

            $request = ServiceRequest::query()->lock('for no key update')->findOrFail($offer->service_request_id);
            $offer = PriceOffer::query()->lock('for no key update')->findOrFail($offer->id);
            abort_unless($buyer->hasId($request->buyer_id) && $seller->hasId($offer->seller_id)
                && $offer->service_request_id === $request->id, 403);

            if (($room = $offer->chatRooms()->whereNotNull('order_id')->first()) !== null) {
                Gate::forUser($actor)->authorize('view', $room);

                return $room;
            }

            $request->setRelation('buyer', $buyer);
            abort_unless($seller->canSubmitPriceOffer($request) && $offer->canBeEdited($request)
                && $request->category()->sharedLock()->firstOrFail()->is_active, 403);

            return $this->activePrechat($buyer->id, $seller->id, $offer);
        }, 3);
    }

    public function send(User $actor, ChatRoom $room, array $input): ChatMessage
    {
        Gate::forUser($actor)->authorize('view', $room);
        $key = Validator::make($input, ['client_message_key' => ['required', 'uuid']])->validate()['client_message_key'];
        $writtenPath = null;
        $committedMessageId = null;

        try {
            return DB::transaction(function () use ($actor, $room, $input, $key, &$writtenPath, &$committedMessageId): ChatMessage {
                [$actor, $room] = $this->lockContext($actor, $room);
                $gate = Gate::forUser($actor);
                $gate->authorize('view', $room);

                $existing = $room->messages()->where('client_message_key', $key)->first();
                if ($existing !== null && $actor->hasId($existing->sender_id)) {
                    return $existing;
                }

                $gate->authorize('send', $room);
                abort_if($existing !== null, 409, 'Kunci pesan sudah digunakan.');

                $fields = $this->validateMessage($input);
                $refund = $fields['message_type'] === ChatMessageType::RefundProof->value;
                if ($refund) {
                    $gate->authorize('sendRefundProof', $room);
                }

                $file = $fields['attachment'] ?? null;
                $attachment = $file === null ? [] : $this->storeFile($room, $file, $refund, $writtenPath);
                $message = $room->messages()->forceCreate([
                    ...$attachment,
                    'sender_id' => $actor->id,
                    'client_message_key' => $key,
                    'message_type' => $fields['message_type'],
                    'message_body' => $fields['message_body'] ?? null,
                    'link_url' => $fields['link_url'] ?? null,
                ]);
                $committedMessageId = $message->id;

                if ($refund) {
                    $room->order->notifications()->forceCreate([
                        'user_id' => $room->buyer_id,
                        'type' => 'refund.proof_submitted',
                        'title' => 'Bukti pengembalian dana dikirim',
                        'body' => 'Penyedia mengirim bukti pengembalian dana di chat pesanan.',
                        'data' => [
                            'chat_room_id' => $room->id,
                            'message_id' => $message->id,
                            'relative_url' => route('chat.show', $room, false),
                        ],
                    ]);
                }

                event(new ChatChanged($room->id, $room->buyer_id, $room->seller_id, $message->id));

                return $message;
            });
        } catch (Throwable $exception) {
            // An after-commit broadcast failure must not remove a committed attachment.
            if ($writtenPath !== null && ($committedMessageId === null
                || ! ChatMessage::whereKey($committedMessageId)->exists())) {
                Storage::disk('private')->delete($writtenPath);
            }

            throw $exception;
        }
    }

    public function markRead(User $actor, ChatRoom $room, ?int $throughId = null): int
    {
        Gate::forUser($actor)->authorize('view', $room);

        return DB::transaction(function () use ($actor, $room, $throughId): int {
            [$actor, $room] = $this->lockContext($actor, $room);
            Gate::forUser($actor)->authorize('markRead', $room);

            if ($throughId !== null) {
                $room->messages()->whereKey($throughId)->firstOrFail();
            }
            $unread = $room->messages()->where('sender_id', $actor->hasId($room->buyer_id) ? $room->seller_id : $room->buyer_id)
                ->whereNull('read_at')->when($throughId !== null, fn ($query) => $query->where('id', '<=', $throughId));
            $latest = (clone $unread)->max('id');
            if ($latest === null) {
                return 0;
            }

            $count = $unread->update(['read_at' => now()]);
            event(new ChatChanged($room->id, $room->buyer_id, $room->seller_id, $latest));

            return $count;
        }, 3);
    }

    private function activePrechat(int $buyerId, int $sellerId, Gig|PriceOffer $source): ChatRoom
    {
        $context = ['buyer_id' => $buyerId, 'seller_id' => $sellerId, 'status' => ChatRoomStatus::Active, 'order_id' => null];

        $room = $source->chatRooms()->where($context)->first();
        if ($room === null) {
            $room = $source->chatRooms()->forceCreate($context);
            event(new ChatChanged($room->id, $room->buyer_id, $room->seller_id));
        }

        return $room;
    }

    /** @return array{User, ChatRoom} */
    private function lockContext(User $actor, ChatRoom $room): array
    {
        $accounts = null;
        if ($room->order_id === null) {
            // Checkout also serializes on these account rows before linking a prechat.
            $accounts = $this->checkout->lockAccounts($room->buyer_id, $room->seller_id);
            $actor = $accounts->get($actor->id);
            $room = $room->fresh();
        }

        $order = null;
        if ($room->order_id !== null) {
            // Maintain the order -> payment -> room lock order used by refund and archive writers.
            $order = Order::query()->lockForUpdate()->findOrFail($room->order_id);
            $order->setRelation('payment', $order->payment()->lockForUpdate()->first());
        }

        $room = ChatRoom::query()->lockForUpdate()->findOrFail($room->id);
        $room->setRelation('order', $order);
        if ($accounts !== null) {
            $room->setRelation('buyer', $accounts->get($room->buyer_id));
            $room->setRelation('seller', $accounts->get($room->seller_id));
        }

        return [$actor, $room];
    }

    private function validateMessage(array $input): array
    {
        $input['message_type'] ??= ChatMessageType::Regular->value;
        if (is_string($input['message_body'] ?? null)) {
            $input['message_body'] = Str::trim($input['message_body']);
            if ($input['message_body'] === '') {
                $input['message_body'] = null;
            }
        }
        $refund = $input['message_type'] === ChatMessageType::RefundProof->value;

        return Validator::make($input, [
            'message_type' => ['required', Rule::enum(ChatMessageType::class)],
            'message_body' => ['nullable', 'required_without_all:attachment,link_url', 'string', 'max:5000', 'not_regex:/\x00/'],
            'link_url' => ['nullable', 'string', 'url:https', 'max:2048', 'prohibits:attachment'],
            'attachment' => ['nullable', 'required_if:message_type,refund_proof', 'file', $refund ? 'max:5120' : 'max:20480'],
        ], [
            'message_body.required_without_all' => 'Tulis pesan atau sertakan lampiran ataupun tautan HTTPS.',
            'message_body.max' => 'Pesan maksimal 5.000 karakter.',
            'link_url.url' => 'Gunakan tautan HTTPS yang valid.',
            'link_url.prohibits' => 'Pilih satu lampiran atau satu tautan HTTPS.',
            'attachment.required_if' => 'Sertakan bukti pengembalian dana dalam format JPG atau PNG.',
            'attachment.max' => $refund ? 'Bukti pengembalian dana maksimal 5 MB.' : 'Lampiran maksimal 20 MB.',
        ])->validate();
    }

    private function storeFile(ChatRoom $room, UploadedFile $file, bool $refund, ?string &$writtenPath): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = MimeTypes::getDefault()->guessMimeType($file->getPathname());
        $size = $file->getSize();
        $original = preg_replace('/[\x00-\x1f\x7f]/u', '', $file->getClientOriginalName());
        if (! is_string($original) || Str::trim($original) === '' || $size < 1
            || ! in_array($mime, self::FILE_TYPES[$extension] ?? [], true)
            || ($refund && ! in_array($mime, ['image/jpeg', 'image/png'], true))) {
            throw ValidationException::withMessages([
                'attachment' => $refund
                    ? 'Unggah bukti pengembalian dana berformat JPG atau PNG yang valid.'
                    : 'Unggah berkas PDF, DOCX, XLSX, PPTX, TXT, CSV, JPG, PNG, atau ZIP yang valid.',
            ]);
        }

        $directory = 'chat/'.$room->id;
        $name = Str::uuid().'.'.$extension;
        $writtenPath = $directory.'/'.$name;
        if ($file->storeAs($directory, $name, ['disk' => 'private', 'visibility' => 'private']) === false) {
            throw ValidationException::withMessages(['attachment' => 'Lampiran gagal disimpan. Silakan coba kembali.']);
        }

        return [
            'file_path' => $writtenPath,
            'original_filename' => Str::limit($original, 255, ''),
            'mime_type' => $mime,
            'file_size' => $size,
        ];
    }
}
