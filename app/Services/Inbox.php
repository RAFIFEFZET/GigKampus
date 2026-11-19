<?php

namespace App\Services;

use App\Models\KtmVerificationRequest;
use App\Models\Notification;
use App\Models\PriceOffer;

final class Inbox
{
    public static function offer(int $recipient, string $type, string $title, string $message, PriceOffer $offer): void
    {
        self::student($recipient, $type, $title, $message, [
            'service_request_id' => $offer->service_request_id,
            'price_offer_id' => $offer->id,
        ]);
    }

    public static function ktm(KtmVerificationRequest $verification, string $title, string $message): void
    {
        self::student($verification->user_id, 'ktm.'.$verification->status->value, $title, $message, [
            'verification_id' => $verification->id,
        ]);
    }

    private static function student(int $recipient, string $type, string $title, string $message, array $data): void
    {
        Notification::query()->forceCreate([
            'user_id' => $recipient,
            'type' => $type,
            'title' => $title,
            'body' => $message,
            'data' => $data,
        ]);
    }
}
