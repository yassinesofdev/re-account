<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['product_id', 'name', 'slug', 'scope', 'price_cents', 'billing_period', 'is_active', 'stripe_price_id', 'stripe_product_id'])]
#[Appends(['is_purchasable', 'stripe_sync_status', 'max_seats'])]
class Plan extends Model
{
    use HasFactory, HasUuids;

    public const SYNC_NOT_REQUIRED = 'not_required';

    public const SYNC_NOT_SYNCED = 'not_synced';

    public const SYNC_OUT_OF_SYNC = 'out_of_sync';

    public const SYNC_SYNCED = 'synced';

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'is_active' => 'boolean',
            'stripe_synced_price_cents' => 'integer',
            'stripe_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** Billing periods a plan can have, mapped to Stripe's recurring [interval, interval_count]. */
    public const STRIPE_PERIODS = [
        'month' => ['month', 1],
        'quarter' => ['month', 3],
        'year' => ['year', 1],
    ];

    /** Periods new or re-priced org plans may use (prepaid terms, no monthly). */
    public const ORG_PERIODS = ['quarter', 'year'];

    public function isPaid(): bool
    {
        return $this->price_cents > 0;
    }

    /**
     * Org plans are priced per seat: price_cents is per seat per period. Plans
     * are not defined by seat numbers; the org buys a quantity (min 2).
     */
    public function isPerSeat(): bool
    {
        return $this->scope === 'organization';
    }

    /**
     * Most seats an org can take on this plan: free org plans are capped by
     * the global config('billing.free_org_max_seats'); paid ones are not.
     */
    public function seatCap(): ?int
    {
        return $this->isPerSeat() && ! $this->isPaid()
            ? max(2, (int) config('billing.free_org_max_seats', 3))
            : null;
    }

    /**
     * Stripe's recurring [interval, interval_count] for this plan's period.
     *
     * @return array{0: string, 1: int}|null
     */
    public function stripeRecurring(): ?array
    {
        return self::STRIPE_PERIODS[$this->billing_period] ?? null;
    }

    /**
     * Our period name for a Stripe recurring interval (null if unsupported).
     */
    public static function periodFromStripe(?string $interval, ?int $count): ?string
    {
        foreach (self::STRIPE_PERIODS as $period => [$i, $c]) {
            if ($i === $interval && $c === (int) ($count ?? 1)) {
                return $period;
            }
        }

        return null;
    }

    /**
     * Free plans are always purchasable; paid ones need a linked Stripe Price.
     */
    protected function isPurchasable(): Attribute
    {
        return Attribute::get(fn () => ! $this->isPaid() || $this->stripe_price_id !== null);
    }

    /**
     * How this plan compares to what was last pushed to / verified in Stripe:
     * not_required (free) · not_synced (paid, no Price) · out_of_sync (linked,
     * but price/period/name differ from the snapshot, or never verified) · synced.
     */
    public function computeStripeSyncStatus(): string
    {
        if (! $this->isPaid()) {
            return self::SYNC_NOT_REQUIRED;
        }

        if ($this->stripe_price_id === null) {
            return self::SYNC_NOT_SYNCED;
        }

        $matches = $this->stripe_synced_at !== null
            && $this->stripe_synced_price_cents === $this->price_cents
            && $this->stripe_synced_billing_period === $this->billing_period
            && $this->stripe_synced_name === $this->name;

        return $matches ? self::SYNC_SYNCED : self::SYNC_OUT_OF_SYNC;
    }

    /**
     * Read-only, derived from the global free-plan cap (not a plan setting).
     */
    protected function maxSeats(): Attribute
    {
        return Attribute::get(fn () => $this->seatCap());
    }

    protected function stripeSyncStatus(): Attribute
    {
        return Attribute::get(fn () => $this->computeStripeSyncStatus());
    }
}
