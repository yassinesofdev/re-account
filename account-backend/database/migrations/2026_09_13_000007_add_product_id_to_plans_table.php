<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->foreignUuid('product_id')->nullable()->after('id')->constrained('products')->cascadeOnDelete();
        });

        $defaultProductId = DB::table('products')->orderBy('created_at')->value('id');

        if ($defaultProductId) {
            DB::table('plans')->update(['product_id' => $defaultProductId]);
        }

        // Postgres-only syntax; skipped on the SQLite test database.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE plans ALTER COLUMN product_id SET NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
    }
};
