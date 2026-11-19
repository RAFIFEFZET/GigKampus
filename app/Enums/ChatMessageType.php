<?php

namespace App\Enums;

enum ChatMessageType: string
{
    case Regular = 'regular';
    case RefundProof = 'refund_proof';
}
