<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\BillingException;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Product;
use App\Services\Billing\StripePlanSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlanAdminController extends Controller
{
    public function __construct(private readonly StripePlanSync $stripeSync) {}

    public function index()
    {
        return response()->json(['plans' => Plan::orderBy('scope')->orderBy('price_cents')->get()]);
    }

    public function forProduct(Product $product)
    {
        return response()->json([
            'plans' => $product->plans()->orderBy('scope')->orderBy('price_cents')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'string', 'uuid', 'exists:products,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:plans,slug'],
            'scope' => ['required', Rule::in(['personal', 'organization'])],
            'price_cents' => ['sometimes', 'integer', 'min:0'],
            'billing_period' => ['nullable', Rule::in(array_keys(Plan::STRIPE_PERIODS))],
            'is_active' => ['sometimes', 'boolean'],
            'stripe_price_id' => ['nullable', 'string', 'max:255', 'regex:/^price_/', 'unique:plans,stripe_price_id'],
            'stripe_product_id' => ['nullable', 'string', 'max:255', 'regex:/^prod_/'],
        ]);

        $this->assertBillable($data);

        $plan = new Plan($data);
        $this->assertOrgPeriod($plan);
        $plan->save();

        if ($plan->stripe_price_id !== null) {
            $this->stripeSync->verifyManualLink($plan);
        }

        return response()->json(['plan' => $plan], 201);
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'product_id' => ['sometimes', 'string', 'uuid', 'exists:products,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'alpha_dash', Rule::unique('plans', 'slug')->ignore($plan->id)],
            'scope' => ['sometimes', Rule::in(['personal', 'organization'])],
            'price_cents' => ['sometimes', 'integer', 'min:0'],
            'billing_period' => ['nullable', Rule::in(array_keys(Plan::STRIPE_PERIODS))],
            'is_active' => ['sometimes', 'boolean'],
            'stripe_price_id' => ['nullable', 'string', 'max:255', 'regex:/^price_/', Rule::unique('plans', 'stripe_price_id')->ignore($plan->id)],
            'stripe_product_id' => ['nullable', 'string', 'max:255', 'regex:/^prod_/'],
        ]);

        $this->assertBillable($data, $plan);

        $plan->fill($data);
        $this->assertOrgPeriod($plan);
        $relinked = $plan->isDirty('stripe_price_id');
        $plan->save();

        if ($relinked) {
            $this->stripeSync->verifyManualLink($plan);
        }

        return response()->json(['plan' => $plan->fresh()]);
    }

    /**
     * Push one plan to Stripe (no-op when free or already in sync).
     */
    public function stripeSync(Plan $plan)
    {
        return response()->json(['plan' => $this->stripeSync->sync($plan)->fresh()]);
    }

    public function destroy(Plan $plan)
    {
        $hasPaidSubscribers = $plan->subscriptions()
            ->whereNotNull('stripe_subscription_id')
            ->exists();

        if ($hasPaidSubscribers) {
            throw BillingException::planHasPaidSubscribers();
        }

        // The FK is restrictOnDelete now; free/complimentary rows go with the
        // plan, as they did before billing.
        DB::transaction(function () use ($plan) {
            $plan->subscriptions()->delete();
            $plan->delete();
        });

        return response()->json(['message' => 'Plan deleted.']);
    }

    /**
     * A paid plan linked to a Stripe Price needs a recurring period Stripe
     * understands. (No live Stripe check in the MVP.)
     *
     * @param  array<string, mixed>  $data
     */
    private function assertBillable(array $data, ?Plan $plan = null): void
    {
        $price = $data['price_cents'] ?? $plan?->price_cents ?? 0;
        $priceId = array_key_exists('stripe_price_id', $data) ? $data['stripe_price_id'] : $plan?->stripe_price_id;
        $period = array_key_exists('billing_period', $data) ? $data['billing_period'] : $plan?->billing_period;

        if ($price > 0 && $priceId && ! isset(Plan::STRIPE_PERIODS[$period])) {
            throw ValidationException::withMessages([
                'billing_period' => 'A paid plan linked to Stripe must bill per month, quarter or year.',
            ]);
        }
    }

    /**
     * Paid org plans are prepaid per quarter or year. An existing monthly org
     * plan keeps working as long as its price and period aren't touched.
     */
    private function assertOrgPeriod(Plan $plan): void
    {
        if ($plan->scope !== 'organization' || ! $plan->isPaid()) {
            return;
        }

        $touched = ! $plan->exists || $plan->isDirty(['price_cents', 'billing_period', 'scope']);

        if ($touched && ! in_array($plan->billing_period, Plan::ORG_PERIODS, true)) {
            throw ValidationException::withMessages([
                'billing_period' => 'Organization plans bill per quarter or per year.',
            ]);
        }
    }
}
