<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'scope' => ['sometimes', Rule::in(['personal', 'organization'])],
            'product' => ['sometimes', 'string', 'exists:products,slug'],
        ]);

        $plans = Plan::query()
            ->where('is_active', true)
            ->when($request->query('scope'), fn ($q, $scope) => $q->where('scope', $scope))
            ->when($request->query('product'), function ($q, $productSlug) {
                $productId = Product::where('slug', $productSlug)->value('id');
                $q->where('product_id', $productId);
            })
            ->orderBy('price_cents')
            ->get();

        return response()->json(['plans' => $plans]);
    }
}
