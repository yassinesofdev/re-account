<?php

namespace App\Exceptions;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A product's seats (assigned members + pending invites) are all used.
 * Rendered as 422 {message, code: seat_limit_reached, product, seats_used, seat_limit}.
 */
class SeatLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly ?Product $product = null,
        public readonly ?int $seatsUsed = null,
        public readonly ?int $seatLimit = null,
    ) {
        parent::__construct($product
            ? "{$product->name} has no seats left ({$seatsUsed}/{$seatLimit}). Upgrade the plan or free a seat first."
            : "You've reached your plan's seat limit. Upgrade to invite more members.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'seat_limit_reached',
            'product' => $this->product?->only(['id', 'slug', 'name']),
            'seats_used' => $this->seatsUsed,
            'seat_limit' => $this->seatLimit,
        ], 422);
    }
}
