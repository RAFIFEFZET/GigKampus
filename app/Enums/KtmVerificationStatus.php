<?php

namespace App\Enums;

enum KtmVerificationStatus: string
{
    case Processing = 'processing';
    case OcrFailed = 'ocr_failed';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
