<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant sub-domains: a product may also accept sign-in callbacks on its
 * organizations' hosts. `tenant_redirect_url` is a template whose host holds
 * `{tenant}` (an org slug), e.g. https://{tenant}.re-es.xyz/intelligence/auth/callback.
 * Null = the product has no tenant hosts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('tenant_redirect_url')->nullable()->after('redirect_url');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('tenant_redirect_url');
        });
    }
};
