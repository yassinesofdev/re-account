<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organization settings: B2B profile, Dubai registration (RERA ORN, trade
 * licence) and product defaults (timezone, brand colour).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('website')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('city', 100)->nullable();
            // RERA office registration number; unique when set (NULLs don't collide).
            $table->string('orn', 20)->nullable()->unique();
            $table->string('trade_license_number', 50)->nullable();
            $table->string('timezone', 64)->default('Asia/Dubai');
            $table->string('brand_color', 7)->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_brand_color_hex CHECK (brand_color IS NULL OR brand_color ~ '^#[0-9A-Fa-f]{6}$')");
            DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_orn_digits CHECK (orn IS NULL OR orn ~ '^[0-9]{1,20}$')");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_brand_color_hex');
            DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_orn_digits');
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['orn']);
            $table->dropColumn([
                'website', 'contact_email', 'phone', 'address', 'city',
                'orn', 'trade_license_number', 'timezone', 'brand_color',
            ]);
        });
    }
};
