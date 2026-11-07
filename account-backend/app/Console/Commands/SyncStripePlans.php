<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\Billing\BillingService;
use App\Services\Billing\StripePlanSync;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Sync every active paid plan that isn't in sync with Stripe (same logic as
 * the admin "Sync with Stripe" buttons, see StripePlanSync).
 */
class SyncStripePlans extends Command
{
    protected $signature = 'billing:sync-stripe-plans {--dry-run : Only list what would be synced}';

    protected $description = 'Create or update Stripe Products/Prices for active paid plans that are not in sync';

    public function handle(StripePlanSync $sync, BillingService $billing): int
    {
        $plans = Plan::with('product')
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->get()
            ->filter(fn (Plan $plan) => in_array($plan->stripe_sync_status, [Plan::SYNC_NOT_SYNCED, Plan::SYNC_OUT_OF_SYNC], true));

        if ($plans->isEmpty()) {
            $this->info('Every active paid plan is in sync with Stripe.');

            return self::SUCCESS;
        }

        if (! $this->option('dry-run') && ! $billing->isAvailable()) {
            $this->error('STRIPE_SECRET is not set.');

            return self::FAILURE;
        }

        foreach ($plans as $plan) {
            $label = "{$plan->slug} ({$plan->stripe_sync_status}): {$plan->price_cents}/".($plan->billing_period ?? '?');

            if ($this->option('dry-run')) {
                $this->line("Would sync {$label}");

                continue;
            }

            try {
                $sync->sync($plan);
            } catch (ValidationException $e) {
                $this->warn("Skipping {$label}: ".collect($e->errors())->flatten()->first());

                continue;
            }

            $this->info("Synced {$label} → {$plan->stripe_price_id}");
        }

        return self::SUCCESS;
    }
}
