<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\OrgService;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TokenController extends Controller
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly OrgService $orgs,
    ) {}

    public function verify(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $secret = $request->header('X-Product-Secret');
        abort_if(! $secret, 401, 'Missing X-Product-Secret header.');

        $payload = $this->tokens->decode($data['token']);
        abort_if(! $payload || ($payload['purpose'] ?? null) !== 'handoff', 401, 'Invalid or expired token.');

        $product = Product::where('slug', $payload['product'])->where('is_active', true)->first();
        abort_if(! $product, 401, 'Unknown or inactive product.');
        abort_if(! Hash::check($secret, $product->secret_key), 401, 'Invalid product secret.');

        $subscribable = $payload['context'] === 'org'
            ? Organization::find($payload['org_id'] ?? null)
            : User::find($payload['user_id']);

        // Only an entitled subscription (active, trialing, past_due) grants a plan.
        $subscription = $subscribable?->subscriptionFor($product)?->load('plan');
        $plan = $subscription?->isEntitled() ? $subscription->plan : null;

        $user = User::find($payload['user_id']);
        $org = $subscribable instanceof Organization ? $subscribable : null;

        return response()->json([
            'user_id' => $payload['user_id'],
            'name' => $payload['name'],
            'email' => $payload['email'],
            'timezone' => $user?->timezone ?? 'UTC',
            // Additive (restructure contract): live, not from the JWT.
            'is_super_admin' => (bool) $user?->is_super_admin,
            // The member's role for this product in org context; null in personal context.
            'product_role' => $org && $user ? $this->orgs->productRoleFor($org->id, $user->id, $product) : null,
            'context' => $payload['context'],
            'org_id' => $payload['org_id'] ?? null,
            'org_name' => $payload['org_name'] ?? null,
            'org_role' => $payload['org_role'] ?? null,
            // Additive: org branding/defaults for products (null in personal context).
            'org' => $org?->productProfile(),
            'product' => $payload['product'],
            'plan' => $plan ? [
                'slug' => $plan->slug,
                'name' => $plan->name,
                // Org plans are per seat: the purchased quantity is the limit.
                'seat_limit' => $subscription->seatLimit(),
                'quantity' => $subscription->quantity,
                'price_cents' => $plan->price_cents,
                'billing_period' => $plan->billing_period,
                'status' => $subscription->status,
                'current_period_end' => $subscription->current_period_end?->toJSON(),
            ] : null,
        ]);
    }
}
