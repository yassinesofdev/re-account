<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Model;

class SubscriptionService
{
    /**
     * Attach a subscribable (User or Organization) to a plan, for that plan's product.
     * Used at creation time when the product is known — e.g. registering through
     * a product's handoff flow.
     */
    public function attachPlan(Model $subscribable, Plan $plan, ?int $quantity = null): Subscription
    {
        return $subscribable->subscriptions()->create([
            'product_id' => $plan->product_id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => now(),
            'quantity' => $quantity,
        ]);
    }

    /**
     * Switch a subscribable onto a different plan for that plan's product,
     * instantly, with no payment. Since Stripe billing, this is only used for
     * free-to-free changes and for leaving a complimentary (unpaid) paid plan
     * for a free one; anything paid goes through Billing\BillingService.
     * $quantity = org seats (null for personal; null keeps the current one).
     */
    public function switchPlan(Model $subscribable, Plan $plan, ?int $quantity = null): Subscription
    {
        $existing = $subscribable->subscriptions()->where('product_id', $plan->product_id)->first();

        if ($existing) {
            $existing->forceFill([
                'plan_id' => $plan->id,
                'status' => 'active',
                'started_at' => now(),
                'canceled_at' => null,
                'current_period_end' => null,
                'cancel_at_period_end' => false,
                'scheduled_quantity' => null,
                'quantity' => $quantity ?? $existing->quantity,
            ])->save();

            return $existing;
        }

        return $this->attachPlan($subscribable, $plan, $quantity);
    }

    /**
     * The free plan a product offers for the given scope, if it has one —
     * used to auto-attach a new user/org when the product context is known.
     */
    public function defaultPlanFor(Product $product, string $scope): ?Plan
    {
        return Plan::where('product_id', $product->id)
            ->where('scope', $scope)
            ->where('price_cents', 0)
            ->where('is_active', true)
            ->first();
    }
}
