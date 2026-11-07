<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per received Stripe event (id = evt_…), for idempotency.
 */
#[Fillable(['id', 'type', 'processed_at'])]
class StripeWebhookEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
