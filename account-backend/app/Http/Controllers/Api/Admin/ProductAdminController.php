<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Billing\StripePlanSync;
use App\Services\OrgService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductAdminController extends Controller
{
    public function __construct(
        private readonly StripePlanSync $stripeSync,
        private readonly OrgService $orgs,
    ) {}

    /**
     * Product roles: [{key, name, default}] (exactly one default; the first
     * role if none is flagged). `super_admin_only`: only super admins may sign in.
     *
     * @return array<string, array<int, mixed>>
     */
    private static function roleRules(): array
    {
        return [
            'roles' => ['sometimes', 'array', 'max:20'],
            'roles.*.key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_-]*$/', 'distinct'],
            'roles.*.name' => ['required', 'string', 'max:100'],
            'roles.*.default' => ['sometimes', 'boolean'],
            'super_admin_only' => ['sometimes', 'boolean'],
        ];
    }

    public function index()
    {
        $products = Product::with('plans')->orderBy('name')->get();

        return response()->json(['products' => $products->map(fn (Product $p) => $this->present($p))]);
    }

    public function show(Product $product)
    {
        return response()->json(['product' => $this->present($product)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:products,slug'],
            'description' => ['nullable', 'string'],
            'redirect_url' => ['required', 'url'],
            'is_active' => ['sometimes', 'boolean'],
            ...self::roleRules(),
        ]);

        $plainSecret = Str::random(48);

        $product = Product::create([
            ...$data,
            'secret_key' => Hash::make($plainSecret),
        ]);

        return response()->json([
            'product' => $this->present($product),
            'secret_key' => $plainSecret,
        ], 201);
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'alpha_dash', Rule::unique('products', 'slug')->ignore($product->id)],
            'description' => ['nullable', 'string'],
            'redirect_url' => ['sometimes', 'url'],
            'is_active' => ['sometimes', 'boolean'],
            ...self::roleRules(),
        ]);

        $product->fill($data)->save();

        if (array_key_exists('roles', $data)) {
            // Assignments whose role was removed fall back to the default.
            $this->orgs->reconcileProductRoles($product);
        }

        return response()->json(['product' => $this->present($product->fresh())]);
    }

    /**
     * Sync all of the product's active paid plans with Stripe.
     */
    public function stripeSync(Product $product)
    {
        $plans = $this->stripeSync->syncProduct($product);

        return response()->json(['plans' => $plans, 'summary' => StripePlanSync::summary($plans)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Product $product): array
    {
        $summary = StripePlanSync::summary($product->plans);
        $product->unsetRelation('plans');

        return [...$product->toArray(), 'stripe_sync_summary' => $summary];
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }
}
