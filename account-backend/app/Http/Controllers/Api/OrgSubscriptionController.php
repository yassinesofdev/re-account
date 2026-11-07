<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesOrganization;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use App\Services\Billing\OrgBillingService;
use App\Services\OrgService;
use Illuminate\Http\Request;

/**
 * Org plans are per seat per product (.project/plans/per-seat-billing.md).
 */
class OrgSubscriptionController extends Controller
{
    use ResolvesOrganization;

    public function __construct(
        private readonly OrgService $orgs,
        private readonly BillingService $billing,
        private readonly OrgBillingService $orgBilling,
    ) {}

    public function show(Request $request, string $slug)
    {
        $org = $this->resolveOrg($request, $slug);

        $data = $request->validate([
            'product' => ['required', 'string', 'exists:products,slug'],
        ]);

        $product = Product::where('slug', $data['product'])->firstOrFail();
        $canManageBilling = $request->user()->can('manageBilling', $org);
        $subscription = $org->subscriptionFor($product);

        return response()->json([
            'subscription' => $this->billing->present($subscription),
            ...$this->seatInfo($org, $product),
            'max_seats' => $this->maxSeats($subscription),
            'billing_portal_available' => $canManageBilling && $org->stripe_customer_id !== null,
        ]);
    }

    /**
     * Free org plans: the global cap (an org already above it keeps its
     * seats). Paid plans: no cap.
     */
    private function maxSeats(?Subscription $subscription): ?int
    {
        $cap = $subscription?->plan?->seatCap();

        return $cap === null ? null : max($cap, (int) $subscription->quantity);
    }

    /**
     * PUT {plan_id, quantity?, proration_date?}: change plan and/or seats.
     */
    public function update(Request $request, string $slug)
    {
        [$org, $plan, $data] = $this->resolveChange($request, $slug, [
            'proration_date' => ['nullable', 'integer'],
        ]);

        $outcome = $this->orgBilling->changePlan(
            $org, $plan, $request->user(), $data['quantity'] ?? null, $data['proration_date'] ?? null,
        );

        return response()->json([
            'result' => $outcome['result'],
            'checkout_url' => $outcome['checkout_url'],
            'subscription' => $this->billing->present($outcome['subscription']),
            ...$this->seatInfo($org, $plan->product),
        ]);
    }

    /**
     * POST {plan_id, quantity?}: what PUT would do and what it costs now.
     */
    public function preview(Request $request, string $slug)
    {
        [$org, $plan, $data] = $this->resolveChange($request, $slug);

        return response()->json([
            'preview' => $this->orgBilling->preview($org, $plan, $data['quantity'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extraRules
     * @return array{0: Organization, 1: Plan, 2: array<string, mixed>}
     */
    private function resolveChange(Request $request, string $slug, array $extraRules = []): array
    {
        $data = $request->validate([
            'plan_id' => ['required', 'string', 'uuid'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            ...$extraRules,
        ]);

        // Contract check order: unknown/inactive/wrong-scope plan (404), then role (403).
        $plan = Plan::where('id', $data['plan_id'])
            ->where('scope', 'organization')
            ->where('is_active', true)
            ->firstOrFail();

        $org = $this->resolveOrg($request, $slug, 'manageBilling');

        return [$org, $plan, $data];
    }

    /**
     * @return array<string, mixed>
     */
    private function seatInfo(Organization $org, Product $product): array
    {
        $usage = $this->orgs->seatUsage($org, $product);

        return [
            'seats_used' => array_sum($usage),
            'seats_assigned' => $usage['assigned'],
            'pending_invites' => $usage['pending_invites'],
            'seat_limit' => $this->orgs->seatLimitFor($org, $product),
            'min_seats' => OrgBillingService::MIN_SEATS,
        ];
    }
}
