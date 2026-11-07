<?php

namespace App\Models;

use App\Services\OrgService;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'subscribable_type', 'subscribable_id', 'product_id', 'plan_id', 'status', 'started_at', 'canceled_at',
    'stripe_subscription_id', 'stripe_price_id', 'current_period_end', 'cancel_at_period_end',
    'quantity', 'scheduled_quantity', 'stripe_subscription_item_id',
])]
#[Hidden(['stripe_subscription_id', 'stripe_price_id', 'stripe_subscription_item_id'])]
#[Appends(['is_paid'])]
class Subscription extends Model
{
    use HasFactory, HasUuids;

    /** Statuses that still grant the plan (past_due = grace while Stripe retries). */
    public const ENTITLED_STATUSES = ['active', 'trialing', 'past_due'];

    /** Statuses that block plan changes until the payment method is fixed. */
    public const BILLING_ISSUE_STATUSES = ['past_due', 'unpaid', 'incomplete'];

    protected static function booted(): void
    {
        // An org's first subscription to a product gives its owner a seat.
        static::created(fn (Subscription $subscription) => app(OrgService::class)->assignOwnerToNewProduct($subscription));
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'canceled_at' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'quantity' => 'integer',
            'scheduled_quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isEntitled(): bool
    {
        return in_array($this->status, self::ENTITLED_STATUSES, true);
    }

    public function hasBillingIssue(): bool
    {
        return in_array($this->status, self::BILLING_ISSUE_STATUSES, true);
    }

    /**
     * Seats this row grants: the purchased quantity (org rows; null =
     * unlimited, e.g. personal rows).
     */
    public function seatLimit(): ?int
    {
        return $this->quantity;
    }

    /**
     * Backed by a Stripe subscription (as opposed to free or complimentary).
     */
    protected function isPaid(): Attribute
    {
        return Attribute::get(fn () => $this->stripe_subscription_id !== null);
    }
}
