<?php

namespace App\Services\Billing;

use App\Exceptions\BillingException;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Subscription as StripeSubscription;

/**
 * Paid plans through Stripe (hosted Checkout + Customer Portal).
 *
 * syncSubscription() is the ONLY place that writes Stripe state onto local
 * rows. Webhooks and the checkout return page both call it with a freshly
 * fetched Stripe subscription, so event order doesn't matter.
 */
class BillingService
{
    /** Morph-type <-> the short name used in Stripe metadata. */
    private const SUBJECT_TYPES = [
        'user' => User::class,
        'organization' => Organization::class,
    ];

    /** Stripe statuses after which the subject drops back to the free plan. */
    private const ENDED_STATUSES = ['canceled', 'unpaid', 'incomplete_expired', 'paused'];

    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function isAvailable(): bool
    {
        return (string) config('services.stripe.secret') !== '';
    }

    public function assertAvailable(): void
    {
        if (! $this->isAvailable()) {
            throw BillingException::unavailable();
        }
    }

    /**
     * The single "make this my plan" entry point for personal plans
     * (PUT profile/subscription). Org plans are per seat and go through
     * OrgBillingService::changePlan.
     *
     * @return array{result: string, checkout_url: ?string, subscription: ?Subscription}
     */
    public function changePlan(User|Organization $subject, Plan $plan, User $actor): array
    {
        if ($subject instanceof Organization) {
            return app(OrgBillingService::class)->changePlan($subject, $plan, $actor);
        }

        return DB::transaction(function () use ($subject, $plan, $actor) {
            /** @var Subscription|null $current */
            $current = $subject->subscriptions()
                ->where('product_id', $plan->product_id)
                ->lockForUpdate()
                ->first();

            if ($current?->hasBillingIssue()) {
                throw BillingException::billingIssue();
            }

            if (! $plan->is_purchasable) {
                throw BillingException::planNotPurchasable();
            }

            // Same plan, nothing scheduled: no-op.
            if ($current && $current->plan_id === $plan->id && ! $current->cancel_at_period_end) {
                return $this->result('changed', $current);
            }

            // None, free or complimentary.
            if (! $current?->is_paid) {
                if (! $plan->isPaid()) {
                    return $this->result('changed', $this->subscriptions->switchPlan($subject, $plan));
                }

                $this->assertAvailable();

                return $this->result('checkout', $current, $this->createCheckout($subject, $plan, $actor));
            }

            // Paid (Stripe-backed) from here on.
            if (! $plan->isPaid()) {
                if (! $current->cancel_at_period_end) {
                    $this->assertAvailable();
                    $current = $this->syncSubscription(
                        $this->stripe->setCancelAtPeriodEnd($current->stripe_subscription_id, true),
                    ) ?? $current;
                }

                return $this->result('scheduled', $current);
            }

            $this->assertAvailable();

            if ($current->plan_id === $plan->id) {
                $current = $this->syncSubscription(
                    $this->stripe->setCancelAtPeriodEnd($current->stripe_subscription_id, false),
                ) ?? $current;

                return $this->result('resumed', $current);
            }

            $stripeSub = $this->stripe->retrieveSubscription($current->stripe_subscription_id);
            $updated = $this->stripe->swapSubscriptionPrice($stripeSub, $plan->stripe_price_id);

            // pending_if_incomplete: Stripe keeps the old price when the
            // proration invoice couldn't be paid.
            if ($updated->pending_update ?? null) {
                throw BillingException::paymentFailed();
            }

            return $this->result('changed', $this->syncSubscription($updated) ?? $current);
        });
    }

    /**
     * Create the subject's Stripe customer on first use.
     */
    public function ensureCustomer(User|Organization $subject): string
    {
        if ($subject->stripe_customer_id) {
            return $subject->stripe_customer_id;
        }

        $type = $this->shortType($subject);
        $email = $subject instanceof User
            ? $subject->email
            : $subject->members()->where('role', 'owner')->first()?->user?->email;

        $customerId = $this->stripe->createCustomer(array_filter([
            'name' => $subject->name,
            'email' => $email,
            'metadata' => ['subscribable_type' => $type, 'subscribable_id' => $subject->id],
        ]), "customer-{$type}-{$subject->id}");

        $subject->forceFill(['stripe_customer_id' => $customerId])->save();

        return $customerId;
    }

    /**
     * @return string The Checkout URL
     */
    public function createCheckout(User|Organization $subject, Plan $plan, User $actor, int $quantity = 1): string
    {
        $metadata = [
            'subscribable_type' => $this->shortType($subject),
            'subscribable_id' => $subject->id,
            'product_id' => $plan->product_id,
            'plan_id' => $plan->id,
            'initiated_by_user_id' => $actor->id,
        ];

        $session = $this->stripe->createCheckoutSession([
            'mode' => 'subscription',
            'customer' => $this->ensureCustomer($subject),
            'line_items' => [['price' => $plan->stripe_price_id, 'quantity' => $quantity]],
            'success_url' => $this->frontendUrl('/billing/return').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $this->contextUrl($subject).'?billing=canceled',
            'client_reference_id' => $subject->id,
            'metadata' => $metadata,
            'subscription_data' => ['metadata' => $metadata],
            'allow_promotion_codes' => false,
        ]);

        return $session->url;
    }

    public function portalUrl(User|Organization $subject): string
    {
        $this->assertAvailable();

        if (! $subject->stripe_customer_id) {
            throw BillingException::noBillingAccount();
        }

        return $this->stripe->createPortalSession($subject->stripe_customer_id, $this->contextUrl($subject));
    }

    /**
     * Frontend page for a subject: /profile or /orgs/{slug}/settings.
     */
    public function contextUrl(User|Organization $subject): string
    {
        return $subject instanceof Organization
            ? $this->frontendUrl("/orgs/{$subject->slug}/settings")
            : $this->frontendUrl('/profile');
    }

    /**
     * Subject (User/Organization) named by Stripe metadata, if it still exists.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function subjectFromMetadata(array $metadata): User|Organization|null
    {
        $class = self::SUBJECT_TYPES[$metadata['subscribable_type'] ?? ''] ?? null;

        return $class ? $class::find($metadata['subscribable_id'] ?? null) : null;
    }

    public function syncCheckoutSession(Session $session): ?Subscription
    {
        $metadata = $this->metadata($session);
        $subject = $this->subjectFromMetadata($metadata);

        if ($subject && ! $subject->stripe_customer_id && is_string($session->customer)) {
            $subject->forceFill(['stripe_customer_id' => $session->customer])->save();
        }

        if ($session->status === 'complete' && $session->subscription) {
            $stripeSub = is_string($session->subscription)
                ? $this->stripe->retrieveSubscription($session->subscription)
                : $session->subscription;

            return $this->syncSubscription($stripeSub);
        }

        return $this->localFor($subject, $metadata['product_id'] ?? null);
    }

    /**
     * Mirror a (freshly fetched) Stripe subscription onto the local row.
     */
    public function syncSubscription(StripeSubscription $s): ?Subscription
    {
        $data = $s->toArray();
        $metadata = $data['metadata'] ?? [];
        $status = $data['status'] ?? null;

        // One Stripe subscription per org, one item per product.
        $linkedRows = Subscription::where('stripe_subscription_id', $s->id)->get();
        $subjectType = $linkedRows->first()?->subscribable_type
            ?? self::SUBJECT_TYPES[$metadata['subscribable_type'] ?? ''] ?? null;

        if ($subjectType === Organization::class) {
            return $this->syncOrgSubscription($s, $data, $linkedRows);
        }

        $local = $linkedRows->first();
        $linked = $local !== null;

        if (! $local) {
            $subject = $this->subjectFromMetadata($metadata);
            if (! $subject || empty($metadata['product_id'])) {
                Log::warning('Stripe subscription without usable metadata', ['subscription' => $s->id]);

                return null;
            }
            $local = $this->localFor($subject, $metadata['product_id']);

            // Already linked to another live Stripe subscription: this one is a duplicate.
            if ($local?->stripe_subscription_id && $local->isEntitled()
                && in_array($status, Subscription::ENTITLED_STATUSES, true)) {
                Log::warning('Duplicate Stripe subscription canceled', [
                    'subscription' => $s->id,
                    'existing' => $local->stripe_subscription_id,
                ]);
                $this->stripe->cancelNow($s->id);

                return $local;
            }
        }

        $item = $data['items']['data'][0] ?? [];
        $priceId = $item['price']['id'] ?? null;
        $periodEnd = $item['current_period_end'] ?? ($data['current_period_end'] ?? null);

        if (in_array($status, Subscription::ENTITLED_STATUSES, true)) {
            $planId = Plan::where('stripe_price_id', $priceId)->value('id');

            if (! $planId) {
                Log::warning('Stripe price not linked to any plan; keeping current plan', [
                    'subscription' => $s->id, 'price' => $priceId,
                ]);
                $planId = $local?->plan_id ?? Plan::whereKey($metadata['plan_id'] ?? null)->value('id');
            }

            if (! $planId) {
                return $local;
            }

            if (! $local) {
                $subject = $this->subjectFromMetadata($metadata);
                $local = $subject->subscriptions()->make(['product_id' => $metadata['product_id']]);
            }

            $local->forceFill([
                'plan_id' => $planId,
                'stripe_subscription_id' => $s->id,
                'stripe_price_id' => $priceId,
                'status' => $status,
                'current_period_end' => $periodEnd ? now()->setTimestamp($periodEnd) : null,
                'cancel_at_period_end' => (bool) ($data['cancel_at_period_end'] ?? false),
                'canceled_at' => null,
                ...($linked ? [] : ['started_at' => now()]),
            ])->save();

            return $local;
        }

        if (in_array($status, self::ENDED_STATUSES, true)) {
            if ($linked) {
                $this->fallBackToFree($local);
            }

            return $local;
        }

        if ($status === 'incomplete' && $linked) {
            $local->forceFill(['status' => 'incomplete'])->save();
        }

        return $local;
    }

    /**
     * Org deletion: stop billing right away, no refund (decision 6).
     */
    public function cancelForDeletion(Organization $org): void
    {
        $ids = $org->subscriptions()->whereNotNull('stripe_subscription_id')
            ->pluck('stripe_subscription_id')->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $this->assertAvailable();

        foreach ($ids as $id) {
            $this->stripe->cancelNow($id);
        }
    }

    /**
     * Subscription JSON plus `next_plan` (the plan it falls to at period end).
     *
     * @return array<string, mixed>|null
     */
    public function present(?Subscription $subscription): ?array
    {
        if (! $subscription) {
            return null;
        }

        $subscription->loadMissing('plan', 'product');

        $nextPlan = $subscription->cancel_at_period_end && $subscription->product && $subscription->plan
            ? $this->subscriptions->defaultPlanFor($subscription->product, $subscription->plan->scope)
            : null;

        return [
            ...$subscription->withoutRelations()->toArray(),
            'plan' => $subscription->plan,
            'next_plan' => $nextPlan,
        ];
    }

    public function fallBackToFree(Subscription $local): void
    {
        $local->loadMissing('plan', 'product');
        $free = $local->product && $local->plan
            ? $this->subscriptions->defaultPlanFor($local->product, $local->plan->scope)
            : null;

        if (! $free) {
            $local->forceFill(['status' => 'canceled', 'canceled_at' => now()])->save();

            return;
        }

        $local->forceFill([
            'plan_id' => $free->id,
            'status' => 'active',
            'stripe_subscription_id' => null,
            'stripe_subscription_item_id' => null,
            'stripe_price_id' => null,
            'current_period_end' => null,
            'cancel_at_period_end' => false,
            'canceled_at' => now(),
            'scheduled_quantity' => null,
            ...($local->subscribable_type === Organization::class
                ? ['quantity' => $this->clampToPlan($local->quantity ?? OrgBillingService::MIN_SEATS, $free)]
                : []),
        ])->save();
    }

    private function clampToPlan(int $quantity, Plan $plan): int
    {
        $max = $plan->seatCap();

        return $max !== null ? min($quantity, $max) : $quantity;
    }

    /**
     * Org subscriptions: one Stripe subscription per org with one item per
     * product (quantity = seats). Every item is mirrored onto its product's
     * row; linked rows whose item is gone drop to free, unless they are
     * ending (item removed at no refund) and still inside their paid period.
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<int, Subscription>  $linkedRows
     */
    private function syncOrgSubscription(StripeSubscription $s, array $data, $linkedRows): ?Subscription
    {
        $metadata = $data['metadata'] ?? [];
        $status = $data['status'] ?? null;
        $org = $linkedRows->first()?->subscribable ?? $this->subjectFromMetadata($metadata);
        $primaryProductId = $metadata['product_id'] ?? null;
        $pick = fn ($rows) => collect($rows)->firstWhere('product_id', $primaryProductId) ?? collect($rows)->first();

        if (! $org instanceof Organization) {
            Log::warning('Stripe subscription without usable metadata', ['subscription' => $s->id]);

            return null;
        }

        if (in_array($status, self::ENDED_STATUSES, true)) {
            $linkedRows->each(fn (Subscription $row) => $this->fallBackToFree($row));

            return $pick($linkedRows);
        }

        if (! in_array($status, Subscription::ENTITLED_STATUSES, true)) {
            if ($status === 'incomplete') {
                $linkedRows->each(fn (Subscription $row) => $row->forceFill(['status' => 'incomplete'])->save());
            }

            return $pick($linkedRows);
        }

        $items = $data['items']['data'] ?? [];
        $resolved = [];

        foreach ($items as $item) {
            $priceId = $item['price']['id'] ?? null;
            $plan = $priceId ? Plan::where('stripe_price_id', $priceId)->first() : null;
            $row = $linkedRows->firstWhere('stripe_subscription_item_id', $item['id'] ?? null)
                ?? $linkedRows->first(fn (Subscription $r) => $r->stripe_subscription_item_id === null && $r->stripe_price_id === $priceId);
            $productId = $row?->product_id ?? $plan?->product_id ?? (count($items) === 1 ? $primaryProductId : null);

            if (! $productId) {
                Log::warning('Stripe subscription item not linked to any product', ['subscription' => $s->id, 'price' => $priceId]);

                continue;
            }

            $row ??= $org->subscriptions()->where('product_id', $productId)->first();
            $planId = $plan?->id ?? $row?->plan_id
                ?? (count($items) === 1 ? Plan::whereKey($metadata['plan_id'] ?? null)->value('id') : null);

            if (! $plan) {
                Log::warning('Stripe price not linked to any plan; keeping current plan', ['subscription' => $s->id, 'price' => $priceId]);
            }

            if ($planId) {
                $resolved[] = ['item' => $item, 'row' => $row, 'product_id' => $productId, 'plan_id' => $planId];
            }
        }

        // A second live Stripe subscription for a product the org already pays for.
        if ($linkedRows->isEmpty()) {
            foreach ($resolved as $r) {
                if ($r['row']?->stripe_subscription_id && $r['row']->isEntitled()) {
                    Log::warning('Duplicate Stripe subscription canceled', [
                        'subscription' => $s->id,
                        'existing' => $r['row']->stripe_subscription_id,
                    ]);
                    $this->stripe->cancelNow($s->id);

                    return $r['row'];
                }
            }
        }

        $touched = [];

        foreach ($resolved as $r) {
            $item = $r['item'];
            $row = $r['row'] ?? $org->subscriptions()->make(['product_id' => $r['product_id']]);
            $periodEnd = $item['current_period_end'] ?? ($data['current_period_end'] ?? null);
            [$quantity, $scheduled] = $this->itemQuantities($item, $periodEnd);

            $row->forceFill([
                'plan_id' => $r['plan_id'],
                'stripe_subscription_id' => $s->id,
                'stripe_subscription_item_id' => $item['id'] ?? null,
                'stripe_price_id' => $item['price']['id'] ?? null,
                'status' => $status,
                'current_period_end' => $periodEnd ? now()->setTimestamp($periodEnd) : null,
                'cancel_at_period_end' => (bool) ($data['cancel_at_period_end'] ?? false),
                'canceled_at' => null,
                'quantity' => $quantity,
                'scheduled_quantity' => $scheduled,
                ...($row->stripe_subscription_id === $s->id ? [] : ['started_at' => now()]),
            ])->save();

            $touched[$row->id] = $row;
        }

        foreach ($linkedRows as $row) {
            if (isset($touched[$row->id])) {
                continue;
            }

            // Item removed at no refund: keep the plan until the paid period ends.
            if ($row->cancel_at_period_end && $row->current_period_end?->isFuture()) {
                continue;
            }

            $this->fallBackToFree($row);
        }

        return $pick($touched) ?? $pick($linkedRows);
    }

    /**
     * [seats granted now, seats from the next renewal]. A reduction lowers
     * the item's quantity at once (no proration) and records the paid seats
     * in its metadata until the period it was paid for ends.
     *
     * @param  array<string, mixed>  $item
     * @return array{0: int, 1: ?int}
     */
    private function itemQuantities(array $item, ?int $periodEnd): array
    {
        $quantity = (int) ($item['quantity'] ?? 1);
        $paid = (int) ($item['metadata']['paid_quantity'] ?? 0);
        $paidUntil = (int) ($item['metadata']['paid_until'] ?? 0);

        if ($paid > $quantity && $periodEnd && $paidUntil >= $periodEnd) {
            return [$paid, $quantity];
        }

        return [$quantity, null];
    }

    private function localFor(User|Organization|null $subject, ?string $productId): ?Subscription
    {
        return $subject && $productId
            ? $subject->subscriptions()->where('product_id', $productId)->first()
            : null;
    }

    /**
     * @return array{result: string, checkout_url: ?string, subscription: ?Subscription}
     */
    private function result(string $result, ?Subscription $subscription, ?string $checkoutUrl = null): array
    {
        return ['result' => $result, 'checkout_url' => $checkoutUrl, 'subscription' => $subscription?->fresh()];
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(Session $session): array
    {
        return $session->toArray()['metadata'] ?? [];
    }

    private function shortType(Model $subject): string
    {
        return array_search($subject::class, self::SUBJECT_TYPES, true);
    }

    private function frontendUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }
}
