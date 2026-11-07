<?php

namespace App\Services\Billing;

use App\Exceptions\BillingException;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;

/**
 * Pushes plans to Stripe (one Stripe Product + recurring Price per paid plan)
 * and records a snapshot of what Stripe holds (`stripe_synced_*`), from which
 * Plan::stripe_sync_status is computed.
 *
 * Stripe Prices are immutable: a price/period change creates a new Price on
 * the same Product and archives the old one. Existing subscribers stay on the
 * old Price (standard practice; they are not migrated).
 */
class StripePlanSync
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly BillingService $billing,
    ) {}

    /**
     * Bring one plan in line with Stripe. No-op for free and synced plans.
     *
     * @throws BillingException 503 when Stripe isn't configured
     * @throws ValidationException when the plan has no month/year period
     * @throws ApiErrorException rendered as 502 stripe_error
     */
    public function sync(Plan $plan): Plan
    {
        $status = $plan->computeStripeSyncStatus();

        if ($status === Plan::SYNC_NOT_REQUIRED || $status === Plan::SYNC_SYNCED) {
            return $plan;
        }

        $this->billing->assertAvailable();
        $this->assertSyncable($plan);

        $plan->loadMissing('product');
        $currency = $this->currency();
        $metadata = ['plan_id' => $plan->id];
        $stripeName = $this->stripeName($plan);

        [$interval, $intervalCount] = $plan->stripeRecurring();

        if ($status === Plan::SYNC_NOT_SYNCED) {
            if ($plan->stripe_product_id) {
                $price = $this->stripe->createPrice($plan->stripe_product_id, $metadata, $plan->price_cents, $currency, $interval, $intervalCount);
                $this->stripe->updateProduct($plan->stripe_product_id, ['name' => $stripeName, 'default_price' => $price->id]);
            } else {
                // Org plans are per seat: a per-unit Price, quantity = seats.
                $price = $this->stripe->createProductAndPrice(
                    $stripeName, $metadata, $plan->price_cents, $currency, $interval, $intervalCount,
                    $plan->isPerSeat() ? 'seat' : null,
                );
            }

            $plan->forceFill(['stripe_price_id' => $price->id, 'stripe_product_id' => $this->productIdOf($price)]);
            $this->snapshot($plan)->save();

            return $plan;
        }

        // out_of_sync: compare with what Stripe holds (the snapshot, or the
        // live Price when the plan was linked by hand and never verified).
        $oldPriceId = $plan->stripe_price_id;
        $live = null;

        if ($plan->stripe_synced_at === null || $plan->stripe_product_id === null) {
            $live = $this->stripe->retrievePrice($oldPriceId);
            $plan->stripe_product_id ??= $this->productIdOf($live);
        }

        $priceChanged = $live !== null
            ? ! $this->priceMatches($live, $plan, $currency)
            : $plan->stripe_synced_price_cents !== $plan->price_cents
                || $plan->stripe_synced_billing_period !== $plan->billing_period;

        if ($priceChanged) {
            $price = $this->stripe->createPrice($plan->stripe_product_id, $metadata, $plan->price_cents, $currency, $interval, $intervalCount);
            $this->stripe->updateProduct($plan->stripe_product_id, ['name' => $stripeName, 'default_price' => $price->id]);
            $plan->forceFill(['stripe_price_id' => $price->id]);
        } elseif ($plan->stripe_synced_name !== $plan->name) {
            $this->stripe->updateProduct($plan->stripe_product_id, ['name' => $stripeName]);
        }

        $this->snapshot($plan)->save();

        if ($priceChanged) {
            // Not fatal: the plan already points at the new Price.
            try {
                $this->stripe->deactivatePrice($oldPriceId);
            } catch (ApiErrorException $e) {
                Log::warning('Could not archive old Stripe price', ['price' => $oldPriceId, 'error' => $e->getMessage()]);
            }
        }

        return $plan;
    }

    /**
     * Sync every active paid plan of a product. Stops at the first error.
     *
     * @return Collection<int, Plan> all of the product's plans, refreshed
     */
    public function syncProduct(Product $product): Collection
    {
        $product->plans()
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->get()
            ->each(fn (Plan $plan) => $this->sync($plan->setRelation('product', $product)));

        return $product->plans()->orderBy('scope')->orderBy('price_cents')->get();
    }

    /**
     * After an admin pasted a Price ID by hand: verify it against Stripe and
     * record what Stripe holds. When Stripe can't be reached the snapshot is
     * cleared, so the plan reads out_of_sync until a sync verifies it.
     */
    public function verifyManualLink(Plan $plan): void
    {
        $plan->forceFill([
            'stripe_synced_price_cents' => null,
            'stripe_synced_billing_period' => null,
            'stripe_synced_name' => null,
            'stripe_synced_at' => null,
        ]);

        if ($plan->stripe_price_id !== null && $this->billing->isAvailable()) {
            try {
                $price = $this->stripe->retrievePrice($plan->stripe_price_id);
                $plan->stripe_product_id ??= $this->productIdOf($price);

                if (isset($price['unit_amount']) && ($price['active'] ?? true)
                    && strtolower((string) ($price['currency'] ?? '')) === $this->currency()) {
                    $plan->forceFill([
                        'stripe_synced_price_cents' => (int) $price['unit_amount'],
                        'stripe_synced_billing_period' => Plan::periodFromStripe(
                            $price['recurring']['interval'] ?? null,
                            $price['recurring']['interval_count'] ?? 1,
                        ),
                        // The Product name isn't fetched; a sync renames it if needed.
                        'stripe_synced_name' => $plan->name,
                        'stripe_synced_at' => now(),
                    ]);
                }
            } catch (BillingException|ApiErrorException $e) {
                Log::info('Could not verify Stripe price on manual link', ['price' => $plan->stripe_price_id, 'error' => $e->getMessage()]);
            }
        }

        $plan->save();
    }

    /**
     * Product-level rollup over its active plans.
     *
     * @param  iterable<Plan>  $plans
     * @return array{status: string, synced: int, out_of_sync: int, not_synced: int}
     */
    public static function summary(iterable $plans): array
    {
        $counts = [Plan::SYNC_SYNCED => 0, Plan::SYNC_OUT_OF_SYNC => 0, Plan::SYNC_NOT_SYNCED => 0];

        foreach ($plans as $plan) {
            if (! $plan->is_active) {
                continue;
            }

            $status = $plan->computeStripeSyncStatus();
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        $status = match (true) {
            $counts[Plan::SYNC_OUT_OF_SYNC] + $counts[Plan::SYNC_NOT_SYNCED] > 0 => 'needs_sync',
            $counts[Plan::SYNC_SYNCED] > 0 => 'synced',
            default => 'not_required',
        };

        return ['status' => $status, ...$counts];
    }

    public function stripeName(Plan $plan): string
    {
        return trim("{$plan->product?->name} {$plan->name}");
    }

    private function snapshot(Plan $plan): Plan
    {
        return $plan->forceFill([
            'stripe_synced_price_cents' => $plan->price_cents,
            'stripe_synced_billing_period' => $plan->billing_period,
            'stripe_synced_name' => $plan->name,
            'stripe_synced_at' => now(),
        ]);
    }

    private function priceMatches(Price $price, Plan $plan, string $currency): bool
    {
        return ($price['active'] ?? true)
            && ($price['unit_amount'] ?? null) === $plan->price_cents
            && Plan::periodFromStripe($price['recurring']['interval'] ?? null, $price['recurring']['interval_count'] ?? 1) === $plan->billing_period
            && strtolower((string) ($price['currency'] ?? '')) === $currency;
    }

    private function assertSyncable(Plan $plan): void
    {
        if ($plan->stripeRecurring() === null) {
            throw ValidationException::withMessages([
                'billing_period' => "\"{$plan->name}\" must bill per month, quarter or year to be synced with Stripe.",
            ]);
        }
    }

    private function productIdOf(Price $price): ?string
    {
        $product = $price['product'] ?? null;

        return is_string($product) ? $product : ($product['id'] ?? null);
    }

    private function currency(): string
    {
        return strtolower((string) config('services.stripe.currency', 'usd'));
    }
}
