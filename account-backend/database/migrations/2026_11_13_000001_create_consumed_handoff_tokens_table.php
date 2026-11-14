<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product handoff tokens are single-use: verify-token records each token's
 * `jti` here on first success. The primary key makes the claim atomic across
 * app containers (INSERT … ON CONFLICT DO NOTHING); rows past `expires_at`
 * are useless (the JWT itself is expired) and are pruned on write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumed_handoff_tokens', function (Blueprint $table) {
            $table->string('jti', 64)->primary();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumed_handoff_tokens');
    }
};
