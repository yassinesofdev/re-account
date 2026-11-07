<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->string('stripe_price_id')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->index('status');
        });

        // Deleting a plan must not silently delete (possibly Stripe-backed)
        // subscriptions; the admin API refuses it instead.
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
            $table->foreign('plan_id')->references('id')->on('plans')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
            $table->foreign('plan_id')->references('id')->on('plans')->cascadeOnDelete();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropUnique(['stripe_subscription_id']);
            $table->dropColumn(['stripe_subscription_id', 'stripe_price_id', 'current_period_end', 'cancel_at_period_end']);
        });
    }
};
