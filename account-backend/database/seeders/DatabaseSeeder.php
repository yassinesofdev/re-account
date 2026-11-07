<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(SubscriptionService $subscriptions): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@re-account.com'],
            [
                'name' => 'RE-Account Admin',
                'password' => Hash::make('secret'),
                'is_super_admin' => true,
                'email_verified_at' => now(),
            ],
        );

        $product = Product::firstOrCreate(
            ['slug' => 're-intel'],
            [
                'name' => 'RE Intel',
                'redirect_url' => 'http://localhost:3741/auth/callback',
                'secret_key' => Hash::make('re-intel-dev-secret-key-change-me'),
            ],
        );

        // Product roles (existing dev rows: only when none are declared yet).
        if (! $product->roles) {
            $product->forceFill(['roles' => [
                ['key' => 'manager', 'name' => 'Manager', 'default' => false],
                ['key' => 'agent', 'name' => 'Agent', 'default' => true],
            ]])->save();
        }

        // Data Admin: super admins only, no plans/seats.
        $data = Product::firstOrCreate(
            ['slug' => 're-data'],
            [
                'name' => 'Data Admin',
                'description' => 'Data-source administration (super admins only).',
                'redirect_url' => 'http://localhost:3743/auth/callback',
                'secret_key' => Hash::make('re-data-dev-secret-key-change-me'),
                'super_admin_only' => true,
            ],
        );

        if (! $data->super_admin_only) {
            $data->forceFill(['super_admin_only' => true])->save();
        }

        $personalFree = Plan::firstOrCreate(
            ['slug' => 'personal-free'],
            ['product_id' => $product->id, 'name' => 'Free', 'scope' => 'personal', 'price_cents' => 0],
        );

        Plan::firstOrCreate(
            ['slug' => 'personal-pro'],
            ['product_id' => $product->id, 'name' => 'Pro', 'scope' => 'personal', 'price_cents' => 1200, 'billing_period' => 'month'],
        );

        Plan::firstOrCreate(
            ['slug' => 'team-free'],
            ['product_id' => $product->id, 'name' => 'Free', 'scope' => 'organization', 'price_cents' => 0],
        );

        Plan::firstOrCreate(
            ['slug' => 'team-pro'],
            // Org plans are per seat: price_cents per seat per period; the org buys a quantity (min 2).
            ['product_id' => $product->id, 'name' => 'Team', 'scope' => 'organization', 'price_cents' => 4900, 'billing_period' => 'quarter'],
        );

        Plan::firstOrCreate(
            ['slug' => 'team-pro-annual'],
            ['product_id' => $product->id, 'name' => 'Team (annual)', 'scope' => 'organization', 'price_cents' => 16900, 'billing_period' => 'year'],
        );

        if (! $admin->subscriptionFor($product)) {
            $subscriptions->attachPlan($admin, $personalFree);
        }

        $this->command->info('Seeded super admin: admin@re-account.com / secret');
        $this->command->info('Seeded product "re-intel" with dev secret key: re-intel-dev-secret-key-change-me');
        $this->command->info('Seeded product "re-data" (super admins only) with dev secret key: re-data-dev-secret-key-change-me');
        $this->command->info('Seeded plans for re-intel: personal-free, personal-pro, team-free (capped by FREE_ORG_MAX_SEATS), team-pro ($49/seat/quarter), team-pro-annual ($169/seat/year)');
    }
}
