<?php

namespace App\Enums;

enum ReportType: string
{
    case GigReport = 'gig_report';
    case RequestReport = 'request_report';
    case ReviewReport = 'review_report';
    case ChatReport = 'chat_report';
    case OrderComplaint = 'order_complaint';

    public function targetColumn(): string
    {
        return match ($this) {
            self::GigReport => 'gig_id',
            self::RequestReport => 'service_request_id',
            self::ReviewReport => 'review_id',
            self::ChatReport => 'chat_room_id',
            self::OrderComplaint => 'order_id',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GigReport => 'Laporan Gig',
            self::RequestReport => 'Laporan Permintaan Jasa',
            self::ReviewReport => 'Laporan Ulasan',
            self::ChatReport => 'Laporan Percakapan',
            self::OrderComplaint => 'Komplain Pesanan',
        };
    }
}
