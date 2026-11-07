<?php

use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_member_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('user_id');
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['org_id', 'user_id', 'product_id']);
            $table->index(['org_id', 'product_id']);
            // Removing the membership (or the org / user) removes the access.
            $table->foreign(['org_id', 'user_id'])->references(['org_id', 'user_id'])->on('org_members')->cascadeOnDelete();
        });

        Schema::create('org_invite_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invite_id')->constrained('org_invites')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();

            $table->unique(['invite_id', 'product_id']);
        });

        $this->backfill();
    }

    /**
     * Portable (query builder only) so it runs on Postgres and the SQLite
     * test database alike. Public so tests can run it against seeded rows.
     *
     * Every existing member and every pending invite gets every product the
     * org has a subscription for: under the old org-wide seat model they had
     * access to all of them. Old invites never stored a product.
     */
    public function backfill(): void
    {
        $now = now();

        $orgProducts = DB::table('subscriptions')
            ->where('subscribable_type', Organization::class)
            ->select('subscribable_id as org_id', 'product_id')
            ->distinct()
            ->get()
            ->groupBy('org_id');

        foreach ($orgProducts as $orgId => $rows) {
            $productIds = $rows->pluck('product_id')->unique();

            foreach (DB::table('org_members')->where('org_id', $orgId)->pluck('user_id') as $userId) {
                foreach ($productIds as $productId) {
                    $exists = DB::table('org_member_products')
                        ->where(['org_id' => $orgId, 'user_id' => $userId, 'product_id' => $productId])
                        ->exists();

                    if (! $exists) {
                        DB::table('org_member_products')->insert([
                            'id' => (string) Str::uuid(),
                            'org_id' => $orgId,
                            'user_id' => $userId,
                            'product_id' => $productId,
                            'assigned_by' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }

            $inviteIds = DB::table('org_invites')->where('org_id', $orgId)->whereNull('accepted_at')->pluck('id');

            foreach ($inviteIds as $inviteId) {
                foreach ($productIds as $productId) {
                    $exists = DB::table('org_invite_products')
                        ->where(['invite_id' => $inviteId, 'product_id' => $productId])
                        ->exists();

                    if (! $exists) {
                        DB::table('org_invite_products')->insert([
                            'id' => (string) Str::uuid(),
                            'invite_id' => $inviteId,
                            'product_id' => $productId,
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('org_invite_products');
        Schema::dropIfExists('org_member_products');
    }
};
