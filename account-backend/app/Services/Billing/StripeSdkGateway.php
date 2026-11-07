<?php

namespace App\Services\Billing;

use App\Exceptions\BillingException;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Invoice;
use Stripe\Price;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Stripe\Webhook;

class StripeSdkGateway implements StripeGateway
{
    private ?StripeClient $client = null;

    public function createCustomer(array $params, string $idempotencyKey): string
    {
        return $this->client()->customers->create($params, ['idempotency_key' => $idempotencyKey])->id;
    }

    public function createCheckoutSession(array $params): Session
    {
        return $this->client()->checkout->sessions->create($params);
    }

    public function retrieveCheckoutSession(string $id): Session
    {
        return $this->client()->checkout->sessions->retrieve($id, ['expand' => ['subscription']]);
    }

    public function createPortalSession(string $customerId, string $returnUrl): string
    {
        return $this->client()->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ])->url;
    }

    public function retrieveSubscription(string $id): StripeSubscription
    {
        return $this->client()->subscriptions->retrieve($id);
    }

    public function swapSubscriptionPrice(StripeSubscription $subscription, string $priceId): StripeSubscription
    {
        return $this->client()->subscriptions->update($subscription->id, [
            'items' => [['id' => $subscription->items->data[0]->id, 'price' => $priceId]],
            'proration_behavior' => 'always_invoice',
            'payment_behavior' => 'pending_if_incomplete',
            'cancel_at_period_end' => false,
        ]);
    }

    public function setCancelAtPeriodEnd(string $id, bool $flag): StripeSubscription
    {
        return $this->client()->subscriptions->update($id, ['cancel_at_period_end' => $flag]);
    }

    public function addSubscriptionItem(string $subscriptionId, array $params): StripeSubscription
    {
        $this->client()->subscriptionItems->create(['subscription' => $subscriptionId, ...$params]);

        return $this->retrieveSubscription($subscriptionId);
    }

    public function updateSubscriptionItem(string $subscriptionId, string $itemId, array $params): StripeSubscription
    {
        $this->client()->subscriptionItems->update($itemId, $params);

        return $this->retrieveSubscription($subscriptionId);
    }

    public function deleteSubscriptionItem(string $subscriptionId, string $itemId, array $params): StripeSubscription
    {
        $this->client()->subscriptionItems->delete($itemId, $params);

        return $this->retrieveSubscription($subscriptionId);
    }

    public function previewInvoice(array $params): Invoice
    {
        return $this->client()->invoices->createPreview($params);
    }

    public function cancelNow(string $id): void
    {
        $this->client()->subscriptions->cancel($id);
    }

    public function retrievePrice(string $id): Price
    {
        return $this->client()->prices->retrieve($id);
    }

    public function createProductAndPrice(string $name, array $metadata, int $unitAmount, string $currency, string $interval, int $intervalCount = 1, ?string $unitLabel = null): Price
    {
        $product = $this->client()->products->create(array_filter([
            'name' => $name,
            'metadata' => $metadata,
            'unit_label' => $unitLabel,
        ]));

        return $this->createPrice($product->id, $metadata, $unitAmount, $currency, $interval, $intervalCount);
    }

    public function createPrice(string $productId, array $metadata, int $unitAmount, string $currency, string $interval, int $intervalCount = 1): Price
    {
        // billing_scheme defaults to per_unit: the subscription item's quantity = seats.
        return $this->client()->prices->create([
            'product' => $productId,
            'unit_amount' => $unitAmount,
            'currency' => $currency,
            'recurring' => ['interval' => $interval, 'interval_count' => $intervalCount],
            'metadata' => $metadata,
        ]);
    }

    public function updateProduct(string $productId, array $params): void
    {
        $this->client()->products->update($productId, $params);
    }

    public function deactivatePrice(string $priceId): void
    {
        $this->client()->prices->update($priceId, ['active' => false]);
    }

    public function constructEvent(string $payload, string $sigHeader): Event
    {
        $secret = (string) config('services.stripe.webhook_secret');

        if ($secret === '') {
            throw BillingException::unavailable();
        }

        return Webhook::constructEvent($payload, $sigHeader, $secret);
    }

    private function client(): StripeClient
    {
        $secret = (string) config('services.stripe.secret');

        if ($secret === '') {
            throw BillingException::unavailable();
        }

        return $this->client ??= new StripeClient($secret);
    }
}
