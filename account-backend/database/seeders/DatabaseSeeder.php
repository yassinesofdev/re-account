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
        // Super admin: dev defaults locally; in production the password must come
        // from SEED_ADMIN_PASSWORD (never the well-known dev password).
        $adminEmail = env('SEED_ADMIN_EMAIL', 'admin@re-account.com');
        $adminPassword = env('SEED_ADMIN_PASSWORD');
        if (blank($adminPassword)) {
            if (app()->isProduction()) {
                throw new \RuntimeException('Set SEED_ADMIN_PASSWORD before seeding in production.');
            }
            $adminPassword = 'secret';
        }

        $admin = User::firstOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'RE-Account Admin',
                'password' => Hash::make($adminPassword),
                'is_super_admin' => true,
                'email_verified_at' => now(),
            ],
        );

        // Sign-in callback URL + product secret per environment (env vars, local
        // defaults below). Locally nothing is set, so existing rows stay as they are;
        // on AWS the deploy sets them and re-running db:seed applies them.
        $product = Product::firstOrCreate(
            ['slug' => 're-intel'],
            [
                'name' => 'RE Intel',
                'redirect_url' => env('RE_INTEL_REDIRECT_URL', 'http://localhost:3741/intelligence/auth/callback'),
                'secret_key' => Hash::make(env('RE_INTEL_PRODUCT_SECRET', 're-intel-dev-secret-key-change-me')),
            ],
        );
        $this->applyEnvOverrides($product, 'RE_INTEL_REDIRECT_URL', 'RE_INTEL_PRODUCT_SECRET');

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
                'redirect_url' => env('RE_DATA_REDIRECT_URL', 'http://localhost:3743/auth/callback'),
                'secret_key' => Hash::make(env('RE_DATA_PRODUCT_SECRET', 're-data-dev-secret-key-change-me')),
                'super_admin_only' => true,
            ],
        );
        $this->applyEnvOverrides($data, 'RE_DATA_REDIRECT_URL', 'RE_DATA_PRODUCT_SECRET');

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

        $this->command->info('Seeded super admin: '.$adminEmail.(filled(env('SEED_ADMIN_PASSWORD')) ? ' (password from SEED_ADMIN_PASSWORD)' : ' / secret'));
        $this->command->info('Seeded product "re-intel" ('.$product->redirect_url.') with '.(filled(env('RE_INTEL_PRODUCT_SECRET')) ? 'secret key from RE_INTEL_PRODUCT_SECRET' : 'dev secret key: re-intel-dev-secret-key-change-me'));
        $this->command->info('Seeded product "re-data" (super admins only, '.$data->redirect_url.') with '.(filled(env('RE_DATA_PRODUCT_SECRET')) ? 'secret key from RE_DATA_PRODUCT_SECRET' : 'dev secret key: re-data-dev-secret-key-change-me'));
        $this->command->info('Seeded plans for re-intel: personal-free, personal-pro, team-free (capped by FREE_ORG_MAX_SEATS), team-pro ($49/seat/quarter), team-pro-annual ($169/seat/year)');
    }

    /**
     * Existing product rows: apply the redirect URL / secret from env when set
     * (e.g. after switching AWS from the ALB host to a domain).
     */
    private function applyEnvOverrides(Product $product, string $redirectEnv, string $secretEnv): void
    {
        if ($product->wasRecentlyCreated) {
            return;
        }

        if (filled($url = env($redirectEnv))) {
            $product->redirect_url = $url;
        }

        if (filled($secret = env($secretEnv)) && ! Hash::check($secret, $product->secret_key)) {
            $product->secret_key = Hash::make($secret);
        }

        $product->save();
    }
}
