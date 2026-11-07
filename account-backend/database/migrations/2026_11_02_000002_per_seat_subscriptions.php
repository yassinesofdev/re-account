<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-seat org billing (.project/plans/per-seat-billing.md):
 * - `quantity` = purchased seats (org rows; null for personal),
 *   `scheduled_quantity` = seats from the next renewal,
 * - one Stripe subscription per org shared by its product rows, so
 *   `stripe_subscription_id` is no longer unique; each row keeps its item id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->nullable();
            $table->unsignedInteger('scheduled_quantity')->nullable();
            $table->string('stripe_subscription_item_id')->nullable()->unique();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['stripe_subscription_id']);
            $table->index('stripe_subscription_id');
        });

        $this->backfill();
    }

    /**
     * Existing org rows keep the limit they had: the plan's seat_limit, or
     * (unlimited plans) the seats in use, at least 2.
     */
    public function backfill(): void
    {
        $rows = DB::table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.subscribable_type', 'App\\Models\\Organization')
            ->whereNull('subscriptions.quantity')
            ->get(['subscriptions.id', 'subscriptions.subscribable_id', 'subscriptions.product_id',
                // plans.seat_limit was dropped later (2026_11_02_000003).
                ...(Schema::hasColumn('plans', 'seat_limit') ? ['plans.seat_limit'] : [])]);

        foreach ($rows as $row) {
            $quantity = $row->seat_limit ?? null;

            if ($quantity === null) {
                $assigned = DB::table('org_member_products')
                    ->where('org_id', $row->subscribable_id)
                    ->where('product_id', $row->product_id)
                    ->count();
                $pending = DB::table('org_invites')
                    ->join('org_invite_products', 'org_invite_products.invite_id', '=', 'org_invites.id')
                    ->where('org_invites.org_id', $row->subscribable_id)
                    ->where('org_invite_products.product_id', $row->product_id)
                    ->whereNull('org_invites.accepted_at')
                    ->where('org_invites.expires_at', '>', now())
                    ->count();
                $quantity = max(2, $assigned + $pending);
            }

            DB::table('subscriptions')->where('id', $row->id)->update(['quantity' => (int) $quantity]);
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['stripe_subscription_id']);
            $table->unique('stripe_subscription_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['stripe_subscription_item_id']);
            $table->dropColumn(['quantity', 'scheduled_quantity', 'stripe_subscription_item_id']);
        });
    }
};
