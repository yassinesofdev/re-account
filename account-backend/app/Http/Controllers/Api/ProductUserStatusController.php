<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrgMember;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Server-to-server check a product runs before acting for a user offline
 * (e.g. re's scheduled report emails). Guarded by the product secret.
 *
 * POST /api/product/user-status  {product, user_id, org_id?}  (X-Product-Secret)
 * → {exists, active, org_member, timezone, org}
 *   - org_member is null when no org_id was given.
 *   - org = {id, slug} of org_id when the user is a member (products build tenant links), else null.
 *   - active == exists today: RE-Account has no suspended/cancelled user state yet.
 */
class ProductUserStatusController extends Controller
{
    public function show(Request $request)
    {
        $data = $request->validate([
            'product' => ['required', 'string'],
            'user_id' => ['required', 'string'],
            'org_id' => ['nullable', 'string'],
        ]);

        $secret = $request->header('X-Product-Secret');
        abort_if(! $secret, 401, 'Missing X-Product-Secret header.');

        $product = Product::where('slug', $data['product'])->where('is_active', true)->first();
        abort_if(! $product || ! Hash::check($secret, $product->secret_key), 401, 'Invalid product credentials.');

        $user = Str::isUuid($data['user_id']) ? User::find($data['user_id']) : null;

        $orgMember = null;
        $org = null;
        if (! empty($data['org_id'])) {
            $orgMember = $user !== null
                && Str::isUuid($data['org_id'])
                && OrgMember::where('org_id', $data['org_id'])->where('user_id', $user->id)->exists();
            if ($orgMember) {
                $found = Organization::find($data['org_id']);
                $org = $found ? ['id' => $found->id, 'slug' => $found->slug] : null;
            }
        }

        return response()->json([
            'exists' => $user !== null,
            'active' => $user !== null,
            'org_member' => $orgMember,
            'timezone' => $user?->timezone,
            'org' => $org,
        ]);
    }
}
