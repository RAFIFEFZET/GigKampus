<?php

namespace App\Enums;

enum OrderStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case AwaitingValidation = 'awaiting_validation';
    case InProgress = 'in_progress';
    case Delivered = 'delivered';
    case Revision = 'revision';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Disputed = 'disputed';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingPayment => 'Menunggu pembayaran',
            self::AwaitingValidation => 'Menunggu validasi',
            self::InProgress => 'Diproses',
            self::Delivered => 'Hasil dikirim',
            self::Revision => 'Dalam revisi',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
            self::Disputed => 'Dalam sengketa',
        };
    }

    public function isPaymentStage(): bool
    {
        return $this === self::AwaitingPayment || $this === self::AwaitingValidation;
    }

    public function isPaidWorkStage(): bool
    {
        return $this === self::InProgress || $this === self::Delivered || $this === self::Revision;
    }

    public function canBeDisputed(): bool
    {
        return $this->isPaymentStage() || $this->isPaidWorkStage() || $this === self::Cancelled;
    }
}
