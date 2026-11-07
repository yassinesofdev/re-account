<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => 'Free',
            'slug' => fake()->unique()->slug(2),
            'scope' => 'personal',
            'price_cents' => 0,
            'billing_period' => null,
            'is_active' => true,
        ];
    }

    public function paid(int $cents = 1200, ?string $priceId = null): static
    {
        return $this->state(fn () => [
            'name' => 'Pro',
            'price_cents' => $cents,
            'billing_period' => 'month',
            'stripe_price_id' => $priceId ?? 'price_'.fake()->unique()->bothify('????????'),
            'stripe_product_id' => 'prod_'.fake()->bothify('????????'),
        ]);
    }

    public function organization(): static
    {
        return $this->state(fn () => ['scope' => 'organization']);
    }
}
