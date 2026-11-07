<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscribable_type' => User::class,
            'subscribable_id' => User::factory(),
            'plan_id' => Plan::factory(),
            'product_id' => fn (array $attributes) => Plan::find($attributes['plan_id'])->product_id,
            'status' => 'active',
            'started_at' => now(),
        ];
    }

    /**
     * Backed by a Stripe subscription.
     */
    public function stripe(string $subscriptionId, ?string $priceId = null): static
    {
        return $this->state(fn () => [
            'stripe_subscription_id' => $subscriptionId,
            'stripe_price_id' => $priceId,
            'current_period_end' => now()->addMonth()->startOfSecond(),
        ]);
    }
}
