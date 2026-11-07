<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot of what was last pushed to (or verified against) Stripe, so the
 * admin UI can tell when a plan's price/period/name drifted from its Price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->integer('stripe_synced_price_cents')->nullable();
            $table->string('stripe_synced_billing_period', 50)->nullable();
            $table->string('stripe_synced_name')->nullable();
            $table->timestamp('stripe_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_synced_price_cents',
                'stripe_synced_billing_period',
                'stripe_synced_name',
                'stripe_synced_at',
            ]);
        });
    }
};
