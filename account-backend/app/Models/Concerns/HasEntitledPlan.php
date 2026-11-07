<?php

namespace App\Models\Concerns;

use App\Models\Plan;
use App\Models\Product;

/**
 * Shared by the two billable subjects (User, Organization). Expects a
 * subscriptionFor(Product) method on the model.
 */
trait HasEntitledPlan
{
    /**
     * The plan this subject may use for the product right now: the
     * subscription's plan if its status is entitled (active, trialing,
     * past_due), otherwise null.
     */
    public function entitledPlanFor(Product $product): ?Plan
    {
        $subscription = $this->subscriptionFor($product);

        return $subscription?->isEntitled() ? $subscription->plan : null;
    }
}
