<?php

namespace Tests\Fakes;

use App\Exceptions\BillingException;
use App\Services\Billing\StripeGateway;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Invoice;
use Stripe\Price;
use Stripe\Subscription as StripeSubscription;
use Stripe\Webhook;

/**
 * In-memory Stripe for tests: records every call, keeps subscriptions and
 * checkout sessions as plain arrays (basil+ shape), never touches the network.
 */
class FakeStripeGateway implements StripeGateway
{
    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>> */
    public array $subscriptions = [];

    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];

    /** @var array<string, array<string, mixed>> Prices retrievePrice() returns (others get a bare stub). */
    public array $prices = [];

    public bool $declineSwap = false;

    public bool $swapLeavesPendingUpdate = false;

    /** Charges made right away (always_invoice) fail with a CardException. */
    public bool $declineCharges = false;

    /** Share of the period left, used to prorate previews and immediate invoices. */
    public float $prorationFraction = 0.5;

    /** @var array<int, array{subscription: string, amount_due: int, proration_date: ?int}> Proration invoices charged now. */
    public array $invoices = [];

    private int $seq = 0;

    /**
     * A Stripe subscription payload with `current_period_end` on the item (basil+).
     *
     * @param  array<string, string>  $metadata
     * @return array<string, mixed>
     */
    public static function subscriptionPayload(
        string $id,
        string $priceId,
        string $status = 'active',
        array $metadata = [],
        ?int $periodEnd = null,
        bool $cancelAtPeriodEnd = false,
        int $quantity = 1,
    ): array {
        return [
            'id' => $id,
            'object' => 'subscription',
            'status' => $status,
            'customer' => 'cus_test',
            'cancel_at_period_end' => $cancelAtPeriodEnd,
            'metadata' => $metadata,
            'items' => [
                'object' => 'list',
                'data' => [[
                    'id' => 'si_'.$id,
                    'object' => 'subscription_item',
                    'price' => ['id' => $priceId, 'object' => 'price'],
                    'quantity' => $quantity,
                    'metadata' => [],
                    'current_period_end' => $periodEnd ?? now()->addMonth()->getTimestamp(),
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function putSubscription(array $payload): void
    {
        $this->subscriptions[$payload['id']] = $payload;
    }

    /**
     * @return array<int, array<int, mixed>> The args of every call to $method
     */
    public function callsTo(string $method): array
    {
        return array_values(array_map(
            fn ($c) => $c[1],
            array_filter($this->calls, fn ($c) => $c[0] === $method),
        ));
    }

    public function createCustomer(array $params, string $idempotencyKey): string
    {
        $this->record(__FUNCTION__, func_get_args());

        return 'cus_fake_'.++$this->seq;
    }

    public function createCheckoutSession(array $params): Session
    {
        $this->record(__FUNCTION__, func_get_args());
        $id = 'cs_test_'.++$this->seq;

        $this->sessions[$id] = [
            'id' => $id,
            'object' => 'checkout.session',
            'status' => 'open',
            'url' => "https://checkout.stripe.com/c/pay/{$id}",
            'customer' => $params['customer'] ?? null,
            'metadata' => $params['metadata'] ?? [],
            'subscription' => null,
        ];

        return Session::constructFrom($this->sessions[$id]);
    }

    public function retrieveCheckoutSession(string $id): Session
    {
        $this->record(__FUNCTION__, func_get_args());

        $session = $this->sessions[$id] ?? throw InvalidRequestException::factory("No such checkout.session: '{$id}'", 404);

        if (is_string($session['subscription']) && isset($this->subscriptions[$session['subscription']])) {
            $session['subscription'] = $this->subscriptions[$session['subscription']];
        }

        return Session::constructFrom($session);
    }

    public function createPortalSession(string $customerId, string $returnUrl): string
    {
        $this->record(__FUNCTION__, func_get_args());

        return 'https://billing.stripe.com/p/session/test_'.$customerId;
    }

    public function retrieveSubscription(string $id): StripeSubscription
    {
        $this->record(__FUNCTION__, func_get_args());

        return StripeSubscription::constructFrom(
            $this->subscriptions[$id] ?? throw InvalidRequestException::factory("No such subscription: '{$id}'", 404),
        );
    }

    public function swapSubscriptionPrice(StripeSubscription $subscription, string $priceId): StripeSubscription
    {
        $this->record(__FUNCTION__, [$subscription->id, $priceId]);

        if ($this->declineSwap) {
            throw CardException::factory('Your card was declined.', 402, null, null, null, 'card_declined', 'generic_decline');
        }

        $payload = $this->subscriptions[$subscription->id];

        if ($this->swapLeavesPendingUpdate) {
            $payload['pending_update'] = ['subscription_items' => [['price' => ['id' => $priceId]]]];

            return StripeSubscription::constructFrom($payload);
        }

        $payload['items']['data'][0]['price']['id'] = $priceId;
        $payload['cancel_at_period_end'] = false;
        $this->subscriptions[$subscription->id] = $payload;

        return StripeSubscription::constructFrom($payload);
    }

    public function setCancelAtPeriodEnd(string $id, bool $flag): StripeSubscription
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->subscriptions[$id]['cancel_at_period_end'] = $flag;

        return StripeSubscription::constructFrom($this->subscriptions[$id]);
    }

    public function addSubscriptionItem(string $subscriptionId, array $params): StripeSubscription
    {
        $this->record(__FUNCTION__, func_get_args());
        $payload = $this->subscriptions[$subscriptionId] ?? throw InvalidRequestException::factory("No such subscription: '{$subscriptionId}'", 404);
        $this->charge($subscriptionId, $params, $this->unitAmount($params['price']) * $params['quantity']);

        $payload['items']['data'][] = [
            'id' => 'si_fake_'.++$this->seq,
            'object' => 'subscription_item',
            'price' => ['id' => $params['price'], 'object' => 'price'],
            'quantity' => $params['quantity'],
            'metadata' => $params['metadata'] ?? [],
            // Co-termed: same period end as the subscription's other items.
            'current_period_end' => $payload['items']['data'][0]['current_period_end'] ?? now()->addMonths(3)->getTimestamp(),
        ];
        $this->subscriptions[$subscriptionId] = $payload;

        return StripeSubscription::constructFrom($payload);
    }

    public function updateSubscriptionItem(string $subscriptionId, string $itemId, array $params): StripeSubscription
    {
        $this->record(__FUNCTION__, func_get_args());
        $payload = $this->subscriptions[$subscriptionId];

        foreach ($payload['items']['data'] as $i => $item) {
            if ($item['id'] !== $itemId) {
                continue;
            }

            $old = $this->unitAmount($item['price']['id']) * $item['quantity'];
            $item['price']['id'] = $params['price'] ?? $item['price']['id'];
            $item['quantity'] = $params['quantity'] ?? $item['quantity'];
            $this->charge($subscriptionId, $params, $this->unitAmount($item['price']['id']) * $item['quantity'] - $old);

            // Stripe semantics: an empty string unsets a metadata key.
            foreach ($params['metadata'] ?? [] as $key => $value) {
                if ($value === '') {
                    unset($item['metadata'][$key]);
                } else {
                    $item['metadata'][$key] = $value;
                }
            }

            $payload['items']['data'][$i] = $item;
        }

        $this->subscriptions[$subscriptionId] = $payload;

        return StripeSubscription::constructFrom($payload);
    }

    public function deleteSubscriptionItem(string $subscriptionId, string $itemId, array $params): StripeSubscription
    {
        $this->record(__FUNCTION__, func_get_args());
        $payload = $this->subscriptions[$subscriptionId];
        $payload['items']['data'] = array_values(array_filter($payload['items']['data'], fn ($item) => $item['id'] !== $itemId));
        $this->subscriptions[$subscriptionId] = $payload;

        return StripeSubscription::constructFrom($payload);
    }

    /**
     * Prorates the item changes in subscription_details by $prorationFraction.
     */
    public function previewInvoice(array $params): Invoice
    {
        $this->record(__FUNCTION__, func_get_args());
        $payload = $this->subscriptions[$params['subscription']] ?? ['items' => ['data' => []]];
        $delta = 0;

        foreach ($params['subscription_details']['items'] ?? [] as $change) {
            $existing = collect($payload['items']['data'])->firstWhere('id', $change['id'] ?? null);
            $old = $existing ? $this->unitAmount($existing['price']['id']) * $existing['quantity'] : 0;
            $price = $change['price'] ?? $existing['price']['id'];
            $delta += $this->unitAmount($price) * ($change['quantity'] ?? $existing['quantity'] ?? 1) - $old;
        }

        return Invoice::constructFrom([
            'id' => 'upcoming',
            'object' => 'invoice',
            'amount_due' => max(0, (int) round($delta * $this->prorationFraction)),
            'currency' => 'usd',
        ]);
    }

    public function cancelNow(string $id): void
    {
        $this->record(__FUNCTION__, func_get_args());

        if (isset($this->subscriptions[$id])) {
            $this->subscriptions[$id]['status'] = 'canceled';
        }
    }

    public function retrievePrice(string $id): Price
    {
        $this->record(__FUNCTION__, func_get_args());

        return Price::constructFrom($this->prices[$id] ?? ['id' => $id, 'object' => 'price', 'product' => 'prod_test']);
    }

    public function createProductAndPrice(string $name, array $metadata, int $unitAmount, string $currency, string $interval, int $intervalCount = 1, ?string $unitLabel = null): Price
    {
        $this->record(__FUNCTION__, func_get_args());
        $n = ++$this->seq;

        return $this->storePrice("price_fake_{$n}", "prod_fake_{$n}", $unitAmount, $currency, $interval, $intervalCount);
    }

    public function createPrice(string $productId, array $metadata, int $unitAmount, string $currency, string $interval, int $intervalCount = 1): Price
    {
        $this->record(__FUNCTION__, func_get_args());

        return $this->storePrice('price_fake_'.++$this->seq, $productId, $unitAmount, $currency, $interval, $intervalCount);
    }

    public function updateProduct(string $productId, array $params): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function deactivatePrice(string $priceId): void
    {
        $this->record(__FUNCTION__, func_get_args());

        if (isset($this->prices[$priceId])) {
            $this->prices[$priceId]['active'] = false;
        }
    }

    public function storePrice(string $id, string $productId, int $unitAmount, string $currency = 'usd', string $interval = 'month', int $intervalCount = 1): Price
    {
        $this->prices[$id] = [
            'id' => $id,
            'object' => 'price',
            'product' => $productId,
            'active' => true,
            'unit_amount' => $unitAmount,
            'currency' => $currency,
            'recurring' => ['interval' => $interval, 'interval_count' => $intervalCount],
        ];

        return Price::constructFrom($this->prices[$id]);
    }

    /**
     * Uses the real signature check with the configured test secret.
     */
    public function constructEvent(string $payload, string $sigHeader): Event
    {
        $secret = (string) config('services.stripe.webhook_secret');

        if ($secret === '') {
            throw BillingException::unavailable();
        }

        return Webhook::constructEvent($payload, $sigHeader, $secret);
    }

    private function unitAmount(?string $priceId): int
    {
        return (int) ($this->prices[$priceId]['unit_amount'] ?? 0);
    }

    /**
     * always_invoice: the prorated difference is invoiced and paid now.
     *
     * @param  array<string, mixed>  $params
     */
    private function charge(string $subscriptionId, array $params, int $delta): void
    {
        if (($params['proration_behavior'] ?? null) !== 'always_invoice') {
            return;
        }

        if ($this->declineCharges) {
            throw CardException::factory('Your card was declined.', 402, null, null, null, 'card_declined', 'generic_decline');
        }

        $this->invoices[] = [
            'subscription' => $subscriptionId,
            'amount_due' => max(0, (int) round($delta * $this->prorationFraction)),
            'proration_date' => $params['proration_date'] ?? null,
        ];
    }

    /**
     * @param  array<int, mixed>  $args
     */
    private function record(string $method, array $args): void
    {
        $this->calls[] = [$method, $args];
    }
}
