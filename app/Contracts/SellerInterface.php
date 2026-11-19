<?php

namespace App\Contracts;

use App\Models\ServiceRequest;
use Carbon\CarbonInterface;

interface SellerInterface
{
    public function hasCompletePaymentMethod(): bool;

    public function canPublishGig(): bool;

    public function canSubmitPriceOffer(ServiceRequest $request, ?CarbonInterface $at = null): bool;
}
