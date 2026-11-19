<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use DomainException;

final class ReviewService
{
    public function submit(User $buyer, Order $order, int $rating, ?string $comment): Review
    {
        return OrderTransaction::run($buyer, $order, function (Order $locked) use ($buyer, $rating, $comment): Review {
            abort_unless($locked->hasBuyer($buyer), 403);
            if ($locked->status !== OrderStatus::Completed || $locked->review()->exists()) {
                throw new DomainException('A review requires a completed order and may be sent once.');
            }

            $review = $locked->review()->forceCreate([
                'reviewer_id' => $buyer->id,
                'seller_id' => $locked->seller_id,
                'rating' => $rating,
                'comment' => $comment,
            ]);

            OrderActivity::record(
                $locked, $buyer, 'review.submitted',
                'Ulasan baru', 'Pemesan memberi ulasan pada pesanan yang selesai.',
                after: ['review_id' => $review->id, 'rating' => $review->rating],
            );

            return $review;
        });
    }
}
