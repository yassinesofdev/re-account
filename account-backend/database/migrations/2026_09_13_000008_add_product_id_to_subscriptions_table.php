<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->uuid('product_id')->nullable()->after('id');
        });

        // Postgres-only syntax; skipped on the SQLite test database (which
        // starts empty, so there is nothing to backfill).
        if (DB::getDriverName() === 'pgsql') {
            // Backfill from the plan each subscription already points to.
            DB::statement('
                UPDATE subscriptions
                SET product_id = plans.product_id
                FROM plans
                WHERE subscriptions.plan_id = plans.id
            ');

            DB::statement('ALTER TABLE subscriptions ALTER COLUMN product_id SET NOT NULL');
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->dropUnique('subscriptions_subscribable_type_subscribable_id_unique');
            $table->unique(['subscribable_type', 'subscribable_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['subscribable_type', 'subscribable_id', 'product_id']);
            $table->unique(['subscribable_type', 'subscribable_id']);
            $table->dropForeign(['product_id']);
            $table->dropColumn('product_id');
        });
    }
};
