<?php

namespace App\Services;

use App\Models\Admin;
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

    public static function ktmPendingReview(KtmVerificationRequest $verification): void
    {
        Admin::query()->eachById(function (Admin $admin) use ($verification): void {
            $admin->notifications()->forceCreate([
                'type' => 'ktm.pending_review',
                'title' => 'KTM siap diperiksa',
                'body' => 'Ada pengajuan verifikasi KTM yang menunggu pemeriksaan.',
                'data' => [
                    'verification_id' => $verification->id,
                    'relative_url' => route('admin.ktm.show', $verification, false),
                ],
            ]);
        });
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
