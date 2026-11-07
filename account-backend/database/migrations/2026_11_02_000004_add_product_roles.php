<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product roles (plan: .project/plans/restructure.md, "Contract: RE-Account additions").
 *
 * - products.roles: jsonb list [{key, name, default}] the product declares
 *   (admin-editable). re-intel: manager + agent (default agent).
 * - products.super_admin_only: only super admins may sign in (re-data).
 * - org_member_products.role / org_invite_products.role: the member's role
 *   in that product. Null when the product declares no roles.
 */
return new class extends Migration
{
    public const RE_INTEL_ROLES = [
        ['key' => 'manager', 'name' => 'Manager', 'default' => false],
        ['key' => 'agent', 'name' => 'Agent', 'default' => true],
    ];

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->jsonb('roles')->nullable();
            $table->boolean('super_admin_only')->default(false);
        });

        Schema::table('org_member_products', function (Blueprint $table) {
            $table->string('role', 64)->nullable();
        });

        Schema::table('org_invite_products', function (Blueprint $table) {
            $table->string('role', 64)->nullable();
        });

        DB::table('products')->where('slug', 're-intel')->whereNull('roles')
            ->update(['roles' => json_encode(self::RE_INTEL_ROLES)]);

        $this->backfill();
    }

    /**
     * Portable (query builder only). Public so tests can run it.
     *
     * Rows without a role get the product's default for that person:
     * owner/admin → `manager` when the product has it, otherwise the product's
     * default role. Products without roles stay null.
     */
    public function backfill(): void
    {
        $products = DB::table('products')->get(['id', 'roles'])->keyBy('id');

        $pick = function (string $productId, ?string $orgRole) use ($products): ?string {
            $roles = json_decode((string) ($products[$productId]->roles ?? ''), true) ?: [];
            $keys = array_column($roles, 'key');

            if (! $keys) {
                return null;
            }

            if (in_array($orgRole, ['owner', 'admin'], true) && in_array('manager', $keys, true)) {
                return 'manager';
            }

            foreach ($roles as $role) {
                if (! empty($role['default'])) {
                    return $role['key'];
                }
            }

            return $keys[0];
        };

        $rows = DB::table('org_member_products as omp')
            ->join('org_members as m', fn ($j) => $j->on('m.org_id', '=', 'omp.org_id')->on('m.user_id', '=', 'omp.user_id'))
            ->whereNull('omp.role')
            ->get(['omp.id', 'omp.product_id', 'm.role as org_role']);

        foreach ($rows as $row) {
            if ($role = $pick($row->product_id, $row->org_role)) {
                DB::table('org_member_products')->where('id', $row->id)->update(['role' => $role]);
            }
        }

        $rows = DB::table('org_invite_products as oip')
            ->join('org_invites as i', 'i.id', '=', 'oip.invite_id')
            ->whereNull('oip.role')
            ->get(['oip.id', 'oip.product_id', 'i.role as org_role']);

        foreach ($rows as $row) {
            if ($role = $pick($row->product_id, $row->org_role)) {
                DB::table('org_invite_products')->where('id', $row->id)->update(['role' => $role]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('org_invite_products', fn (Blueprint $table) => $table->dropColumn('role'));
        Schema::table('org_member_products', fn (Blueprint $table) => $table->dropColumn('role'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['roles', 'super_admin_only']));
    }
};
