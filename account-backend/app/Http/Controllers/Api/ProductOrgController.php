<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Product;
use App\Support\OrgSubdomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Tenant lookup for products serving `<org-slug>.<domain>`: which org (if any)
 * owns this sub-domain, with its public branding.
 *
 * GET /api/product/orgs/{slug}?product=<slug>  (X-Product-Secret)
 * → 200 {id, name, slug, logo_url, brand_color}
 * → 404 {message, code: reserved | org_not_found}
 *
 * Guarded by the product secret (like verify-token) although the data is
 * mostly public branding: it stops anyone from enumerating org names by slug,
 * and only product servers need it (they already hold the secret).
 */
class ProductOrgController extends Controller
{
    public function show(Request $request, string $slug)
    {
        $data = $request->validate([
            'product' => ['required', 'string'],
        ]);

        $secret = $request->header('X-Product-Secret');
        abort_if(! $secret, 401, 'Missing X-Product-Secret header.');

        $product = Product::where('slug', $data['product'])->where('is_active', true)->first();
        abort_if(! $product || ! Hash::check($secret, $product->secret_key), 401, 'Invalid product credentials.');

        $slug = strtolower($slug);

        if (OrgSubdomain::isReserved($slug)) {
            return response()->json(['message' => 'Reserved name.', 'code' => 'reserved'], 404);
        }

        $org = OrgSubdomain::isDnsSafe($slug) ? Organization::where('slug', $slug)->first() : null;

        if (! $org) {
            return response()->json(['message' => 'Organization not found.', 'code' => 'org_not_found'], 404);
        }

        return response()->json([
            'id' => $org->id,
            'name' => $org->name,
            'slug' => $org->slug,
            'logo_url' => $org->logo_url,
            'brand_color' => $org->brand_color,
        ]);
    }
}
