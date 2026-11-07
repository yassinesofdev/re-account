<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Product;
use App\Services\Billing\BillingService;
use Illuminate\Http\Request;

class ProfileSubscriptionController extends Controller
{
    public function __construct(private readonly BillingService $billing) {}

    public function show(Request $request)
    {
        $data = $request->validate([
            'product' => ['required', 'string', 'exists:products,slug'],
        ]);

        $product = Product::where('slug', $data['product'])->firstOrFail();
        $user = $request->user();

        return response()->json([
            'subscription' => $this->billing->present($user->subscriptionFor($product)),
            'billing_portal_available' => $user->stripe_customer_id !== null,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'string', 'uuid'],
        ]);

        $plan = Plan::where('id', $data['plan_id'])
            ->where('scope', 'personal')
            ->where('is_active', true)
            ->firstOrFail();

        $outcome = $this->billing->changePlan($request->user(), $plan, $request->user());

        return response()->json([
            'result' => $outcome['result'],
            'checkout_url' => $outcome['checkout_url'],
            'subscription' => $this->billing->present($outcome['subscription']),
        ]);
    }
}
