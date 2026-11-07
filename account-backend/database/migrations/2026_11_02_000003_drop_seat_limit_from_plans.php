<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plans are no longer defined by seat numbers: an org's seat limit is the
 * quantity it bought (min 2), and free org plans share one global cap
 * (config('billing.free_org_max_seats')). Existing subscription quantities
 * are left as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('plans', 'seat_limit')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE plans DROP COLUMN IF EXISTS seat_limit');

            return;
        }

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('seat_limit');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('plans', 'seat_limit')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('seat_limit')->nullable()->after('scope');
        });
    }
};
