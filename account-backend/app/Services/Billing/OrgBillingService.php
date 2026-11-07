<?php

namespace App\Services\Billing;

use App\Exceptions\BillingException;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrgService;
use App\Services\SubscriptionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Stripe\Subscription as StripeSubscription;

/**
 * Per-seat org billing (.project/plans/per-seat-billing.md).
 *
 * One Stripe subscription per org, one item per product (quantity = seats).
 * The first paid product goes through Checkout (it sets the org's billing
 * anniversary); later products are added as items co-termed to it. Added
 * seats are charged now (prorated), removed seats take effect at renewal.
 *
 * plan() decides what a change means (shared by preview() and changePlan());
 * every Stripe write is followed by BillingService::syncSubscription, the only
 * code that writes Stripe state locally.
 */
class OrgBillingService
{
    public const MIN_SEATS = 2;

    /** A preview's proration_date is honoured for this long. */
    private const PRORATION_DATE_TTL = 3600;

    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly BillingService $billing,
        private readonly OrgService $orgs,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * @return array{result: string, checkout_url: ?string, subscription: ?Subscription}
     */
    public function changePlan(Organization $org, Plan $plan, User $actor, ?int $quantity = null, ?int $prorationDate = null): array
    {
        return DB::transaction(function () use ($org, $plan, $actor, $quantity, $prorationDate) {
            $change = $this->plan($org, $plan, $quantity, lock: true);
            $current = $change['current'];
            $q = $change['quantity'];
            $prorationDate = $this->validProrationDate($prorationDate);

            switch ($change['action']) {
                case 'none':
                    return $this->result($change['result'], $current);

                case 'update_free':
                    if ($current && $current->plan_id === $plan->id) {
                        $current->forceFill(['quantity' => $q, 'scheduled_quantity' => null])->save();

                        return $this->result('changed', $current);
                    }

                    return $this->result('changed', $this->subscriptions->switchPlan($org, $plan, $q));

                case 'subscribe':
                    $this->billing->assertAvailable();

                    return $this->result('checkout', $current, $this->billing->createCheckout($org, $plan, $actor, $q));

                case 'add_product':
                    $this->billing->assertAvailable();
                    $updated = $this->stripe->addSubscriptionItem($change['stripe_subscription_id'], array_filter([
                        'price' => $plan->stripe_price_id,
                        'quantity' => $q,
                        'proration_behavior' => 'always_invoice',
                        'payment_behavior' => 'error_if_incomplete',
                        'proration_date' => $prorationDate,
                    ]));

                    return $this->synced('changed', $org, $plan, $updated);

                case 'add_seats':
                    $this->billing->assertAvailable();
                    $this->restorePaidQuantity($current);
                    $updated = $this->stripe->updateSubscriptionItem($current->stripe_subscription_id, $current->stripe_subscription_item_id, array_filter([
                        'quantity' => $q,
                        'proration_behavior' => 'always_invoice',
                        'payment_behavior' => 'error_if_incomplete',
                        'proration_date' => $prorationDate,
                    ]));

                    return $this->synced('changed', $org, $plan, $updated);

                case 'reduce_seats':
                    $this->billing->assertAvailable();
                    $updated = $this->stripe->updateSubscriptionItem($current->stripe_subscription_id, $current->stripe_subscription_item_id, [
                        'quantity' => $q,
                        'proration_behavior' => 'none',
                        'metadata' => [
                            'paid_quantity' => (string) $current->seatLimit(),
                            'paid_until' => (string) $current->current_period_end?->getTimestamp(),
                        ],
                    ]);

                    return $this->synced('scheduled', $org, $plan, $updated);

                case 'keep_seats':
                    $this->billing->assertAvailable();

                    return $this->synced('resumed', $org, $plan, $this->restorePaidQuantity($current));

                case 'downgrade':
                    $this->billing->assertAvailable();

                    return $this->synced('scheduled', $org, $current->plan, $this->endAtPeriodEnd($current));

                case 'resume':
                    $this->billing->assertAvailable();

                    return $this->synced('resumed', $org, $plan, $this->resume($current));

                case 'change_plan':
                    $this->billing->assertAvailable();
                    if ($current->cancel_at_period_end) {
                        $this->stripe->setCancelAtPeriodEnd($current->stripe_subscription_id, false);
                    }
                    $updated = $this->stripe->updateSubscriptionItem($current->stripe_subscription_id, $current->stripe_subscription_item_id, array_filter([
                        'price' => $plan->stripe_price_id,
                        'quantity' => $q,
                        'proration_behavior' => 'always_invoice',
                        'payment_behavior' => 'error_if_incomplete',
                        'proration_date' => $prorationDate,
                    ]));

                    return $this->synced('changed', $org, $plan, $updated);
            }

            throw new \LogicException("Unhandled org billing action {$change['action']}.");
        });
    }

    /**
     * What changePlan() would do, and what it costs. Amounts that depend on
     * proration come from Stripe's invoice preview; the returned
     * proration_date makes the real charge match it.
     *
     * @return array<string, mixed>
     */
    public function preview(Organization $org, Plan $plan, ?int $quantity = null): array
    {
        $change = $this->plan($org, $plan, $quantity, lock: false);
        $current = $change['current'];
        $q = $change['quantity'];
        $renewsAt = $change['renews_at'];
        $amountNow = 0;
        $prorationDate = null;
        $effectiveAt = now();

        switch ($change['action']) {
            case 'subscribe':
                $amountNow = $q * $plan->price_cents;
                break;

            case 'add_product':
            case 'add_seats':
            case 'change_plan':
                $this->billing->assertAvailable();
                $prorationDate = now()->getTimestamp();
                $item = match ($change['action']) {
                    'add_product' => ['price' => $plan->stripe_price_id, 'quantity' => $q],
                    // Proration is linear in the seat delta; Stripe holds the
                    // post-reduction quantity while a reduction is pending.
                    'add_seats' => [
                        'id' => $current->stripe_subscription_item_id,
                        'quantity' => $q - $current->seatLimit() + ($current->scheduled_quantity ?? $current->seatLimit()),
                    ],
                    'change_plan' => ['id' => $current->stripe_subscription_item_id, 'price' => $plan->stripe_price_id, 'quantity' => $q],
                };
                $invoice = $this->stripe->previewInvoice([
                    'customer' => $org->stripe_customer_id,
                    'subscription' => $change['stripe_subscription_id'],
                    'subscription_details' => array_filter([
                        'items' => [$item],
                        'proration_behavior' => 'always_invoice',
                        'proration_date' => $prorationDate,
                        'cancel_at_period_end' => $change['action'] === 'change_plan' && $current->cancel_at_period_end ? false : null,
                    ], fn ($v) => $v !== null),
                ]);
                $amountNow = (int) ($invoice['amount_due'] ?? 0);
                break;

            case 'reduce_seats':
            case 'downgrade':
                $effectiveAt = $current->current_period_end;
                break;
        }

        return [
            'result' => $change['result'],
            'action' => $change['action'],
            'plan' => $plan,
            'quantity' => $q,
            'current_quantity' => $current?->seatLimit(),
            'unit_amount_cents' => $plan->price_cents,
            'billing_period' => $plan->billing_period,
            'recurring_amount_cents' => $plan->isPaid() ? $q * $plan->price_cents : 0,
            'amount_due_now_cents' => $amountNow,
            'currency' => strtolower((string) config('services.stripe.currency', 'usd')),
            'effective_at' => $effectiveAt?->toJSON(),
            'renews_at' => $renewsAt?->toJSON(),
            'proration_date' => $prorationDate,
        ];
    }

    /**
     * Validate a change and classify it (decision table in the plan doc).
     * No Stripe calls, no writes.
     *
     * @return array{action: string, result: string, current: ?Subscription, quantity: int, stripe_subscription_id: ?string, renews_at: ?Carbon}
     */
    public function plan(Organization $org, Plan $plan, ?int $quantity, bool $lock = false): array
    {
        /** @var Subscription|null $current */
        $current = $org->subscriptions()
            ->where('product_id', $plan->product_id)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->with('plan')
            ->first();

        if ($current?->hasBillingIssue()) {
            throw BillingException::billingIssue();
        }

        $usage = $this->orgs->seatUsage($org, $plan->product);
        $used = array_sum($usage);

        $currentQty = $current?->seatLimit();
        $explicit = $quantity !== null;
        $quantity ??= $currentQty ?? max(self::MIN_SEATS, $used);

        if ($quantity < self::MIN_SEATS) {
            throw ValidationException::withMessages(['quantity' => 'An organization plan needs at least '.self::MIN_SEATS.' seats.']);
        }

        // Only free org plans are capped (global setting); an org already on
        // that free plan keeps the seats it has.
        $max = $plan->seatCap();
        if ($max !== null && $current?->plan_id === $plan->id && $currentQty !== null) {
            $max = max($max, $currentQty);
        }

        if ($max !== null && $quantity > $max) {
            if ($explicit) {
                throw ValidationException::withMessages(['quantity' => "{$plan->name} allows up to {$max} seats."]);
            }
            $quantity = max(self::MIN_SEATS, $max);
        }

        if ($used > $quantity) {
            throw BillingException::seatsOverLimit($used, $quantity, $usage);
        }

        if (! $plan->is_purchasable) {
            throw BillingException::planNotPurchasable();
        }

        $samePlan = $current && $current->plan_id === $plan->id;
        $ending = (bool) $current?->cancel_at_period_end;
        $orgSubId = $current?->is_paid ? $current->stripe_subscription_id : $this->liveSubscriptionId($org);
        $base = [
            'current' => $current,
            'quantity' => $quantity,
            'stripe_subscription_id' => $orgSubId,
            'renews_at' => $orgSubId ? $this->renewsAt($org, $orgSubId) : null,
        ];
        $is = fn (string $action, string $result) => ['action' => $action, 'result' => $result, ...$base];

        // None, free or complimentary.
        if (! $current?->is_paid) {
            if ($samePlan && $quantity === $currentQty) {
                return $is('none', 'changed');
            }

            if (! $plan->isPaid()) {
                return $is('update_free', 'changed');
            }

            if (! $orgSubId) {
                return $is('subscribe', 'checkout');
            }

            $this->assertCanAddTo($org, $orgSubId, $plan);

            return $is('add_product', 'changed');
        }

        // Paid (Stripe-backed) from here on.
        if (! $plan->isPaid()) {
            return $ending ? $is('none', 'scheduled') : $is('downgrade', 'scheduled');
        }

        if ($samePlan) {
            if ($ending) {
                return $is('resume', 'resumed');
            }

            return match (true) {
                $quantity > $currentQty => $is('add_seats', 'changed'),
                $quantity < $currentQty => $quantity === $current->scheduled_quantity
                    ? $is('none', 'scheduled')
                    : $is('reduce_seats', 'scheduled'),
                $current->scheduled_quantity !== null => $is('keep_seats', 'resumed'),
                default => $is('none', 'changed'),
            };
        }

        if ($current->scheduled_quantity !== null) {
            throw BillingException::seatChangeScheduled();
        }

        if ($ending && $current->stripe_subscription_item_id === null) {
            throw BillingException::planEnding();
        }

        if ($quantity < $currentQty) {
            throw ValidationException::withMessages(['quantity' => 'Change the plan first, then reduce seats (reductions apply at renewal).']);
        }

        if ($plan->billing_period !== $current->plan?->billing_period) {
            $this->assertSamePeriod($org, $orgSubId, $plan, exceptProductId: $plan->product_id);
        }

        return $is('change_plan', 'changed');
    }

    /**
     * The org's live Stripe subscription (any product), if any.
     */
    public function liveSubscriptionId(Organization $org): ?string
    {
        return $org->subscriptions()
            ->whereNotNull('stripe_subscription_id')
            ->whereIn('status', Subscription::ENTITLED_STATUSES)
            ->value('stripe_subscription_id');
    }

    private function renewsAt(Organization $org, string $subscriptionId): ?Carbon
    {
        return $org->subscriptions()
            ->where('stripe_subscription_id', $subscriptionId)
            ->whereNotNull('current_period_end')
            ->orderByDesc('current_period_end')
            ->first()?->current_period_end;
    }

    /**
     * A product added to the org's subscription must share its period, and
     * the subscription must not be set to end.
     */
    private function assertCanAddTo(Organization $org, string $subscriptionId, Plan $plan): void
    {
        $this->assertSamePeriod($org, $subscriptionId, $plan, $plan->product_id);

        $ending = $org->subscriptions()
            ->where('stripe_subscription_id', $subscriptionId)
            ->where('cancel_at_period_end', true)
            ->whereNotNull('stripe_subscription_item_id')
            ->first();

        if ($ending) {
            throw BillingException::subscriptionEnding($ending->current_period_end?->toDateString());
        }
    }

    private function assertSamePeriod(Organization $org, string $subscriptionId, Plan $plan, string $exceptProductId): void
    {
        $other = $org->subscriptions()
            ->with('plan')
            ->where('stripe_subscription_id', $subscriptionId)
            ->where('product_id', '!=', $exceptProductId)
            ->get()
            ->first(fn (Subscription $row) => $row->plan?->billing_period !== $plan->billing_period);

        if ($other) {
            throw BillingException::billingPeriodMismatch((string) $other->plan->billing_period);
        }
    }

    /**
     * Undo a pending reduction: the item goes back to the paid quantity,
     * without proration (it was paid for), and its metadata is cleared.
     */
    private function restorePaidQuantity(Subscription $row): ?StripeSubscription
    {
        if ($row->scheduled_quantity === null) {
            return null;
        }

        return $this->stripe->updateSubscriptionItem($row->stripe_subscription_id, $row->stripe_subscription_item_id, [
            'quantity' => $row->quantity,
            'proration_behavior' => 'none',
            'metadata' => ['paid_quantity' => '', 'paid_until' => ''],
        ]);
    }

    /**
     * Paid → free for one product. The last item ends with the subscription
     * (cancel_at_period_end); otherwise the item is removed now with no
     * refund and the row keeps its plan until current_period_end.
     */
    private function endAtPeriodEnd(Subscription $row): StripeSubscription
    {
        $items = Subscription::where('stripe_subscription_id', $row->stripe_subscription_id)
            ->whereNotNull('stripe_subscription_item_id')
            ->count();

        if ($items <= 1) {
            return $this->stripe->setCancelAtPeriodEnd($row->stripe_subscription_id, true);
        }

        $updated = $this->stripe->deleteSubscriptionItem($row->stripe_subscription_id, $row->stripe_subscription_item_id, [
            'proration_behavior' => 'none',
        ]);

        $row->forceFill([
            'stripe_subscription_item_id' => null,
            'cancel_at_period_end' => true,
            'scheduled_quantity' => null,
        ])->save();

        return $updated;
    }

    /**
     * Keep a plan that was set to end: clear the subscription's cancellation,
     * or put the removed item back (already paid for, so no proration).
     */
    private function resume(Subscription $row): StripeSubscription
    {
        if ($row->stripe_subscription_item_id !== null) {
            return $this->stripe->setCancelAtPeriodEnd($row->stripe_subscription_id, false);
        }

        $updated = $this->stripe->addSubscriptionItem($row->stripe_subscription_id, [
            'price' => $row->stripe_price_id,
            'quantity' => $row->quantity,
            'proration_behavior' => 'none',
        ]);

        $row->forceFill(['cancel_at_period_end' => false])->save();

        return $updated;
    }

    private function validProrationDate(?int $ts): ?int
    {
        $now = now()->getTimestamp();

        return $ts !== null && $ts <= $now && $ts >= $now - self::PRORATION_DATE_TTL ? $ts : null;
    }

    /**
     * @return array{result: string, checkout_url: ?string, subscription: ?Subscription}
     */
    private function synced(string $result, Organization $org, ?Plan $plan, ?StripeSubscription $updated): array
    {
        if ($updated) {
            $this->billing->syncSubscription($updated);
        }

        return $this->result($result, $org->subscriptions()->where('product_id', $plan?->product_id)->first());
    }

    /**
     * @return array{result: string, checkout_url: ?string, subscription: ?Subscription}
     */
    private function result(string $result, ?Subscription $subscription, ?string $checkoutUrl = null): array
    {
        return ['result' => $result, 'checkout_url' => $checkoutUrl, 'subscription' => $subscription?->fresh()];
    }
}
