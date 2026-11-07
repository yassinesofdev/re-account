<?php

namespace App\Services\Billing;

use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Invoice;
use Stripe\Price;
use Stripe\Subscription as StripeSubscription;

/**
 * The only door to the Stripe API. Production binds StripeSdkGateway;
 * tests bind Tests\Fakes\FakeStripeGateway so no network call is made.
 *
 * Implementations throw App\Exceptions\BillingException::unavailable() when
 * Stripe isn't configured, and let \Stripe\Exception\ApiErrorException
 * bubble up (rendered as 502 / 402 in bootstrap/app.php).
 */
interface StripeGateway
{
    /**
     * @param  array<string, mixed>  $params
     * @return string The Stripe customer ID (cus_…)
     */
    public function createCustomer(array $params, string $idempotencyKey): string;

    /**
     * @param  array<string, mixed>  $params
     */
    public function createCheckoutSession(array $params): Session;

    /**
     * Retrieved with `subscription` expanded.
     */
    public function retrieveCheckoutSession(string $id): Session;

    /**
     * @return string The portal URL
     */
    public function createPortalSession(string $customerId, string $returnUrl): string;

    public function retrieveSubscription(string $id): StripeSubscription;

    /**
     * Swap the first item's price (proration_behavior=always_invoice,
     * payment_behavior=pending_if_incomplete) and clear any scheduled
     * cancellation.
     */
    public function swapSubscriptionPrice(StripeSubscription $subscription, string $priceId): StripeSubscription;

    public function setCancelAtPeriodEnd(string $id, bool $flag): StripeSubscription;

    /**
     * Add an item (one product of an org subscription) and return the
     * refreshed subscription. $params: price, quantity, proration_behavior,
     * proration_date?, payment_behavior?, metadata?
     *
     * @param  array<string, mixed>  $params
     */
    public function addSubscriptionItem(string $subscriptionId, array $params): StripeSubscription;

    /**
     * Update one item (quantity, price, metadata, proration_behavior,
     * proration_date, payment_behavior) and return the refreshed subscription.
     *
     * @param  array<string, mixed>  $params
     */
    public function updateSubscriptionItem(string $subscriptionId, string $itemId, array $params): StripeSubscription;

    /**
     * Remove one item (e.g. proration_behavior=none) and return the refreshed subscription.
     *
     * @param  array<string, mixed>  $params
     */
    public function deleteSubscriptionItem(string $subscriptionId, string $itemId, array $params): StripeSubscription;

    /**
     * Invoice preview (invoices.createPreview): with
     * subscription_details.proration_behavior=always_invoice it is the
     * proration invoice a change would charge right now.
     *
     * @param  array<string, mixed>  $params
     */
    public function previewInvoice(array $params): Invoice;

    public function cancelNow(string $id): void;

    public function retrievePrice(string $id): Price;

    /**
     * Create a Product and a recurring per-unit Price for it ($unitLabel
     * "seat" for per-seat org plans).
     *
     * @param  array<string, string>  $metadata
     */
    public function createProductAndPrice(string $name, array $metadata, int $unitAmount, string $currency, string $interval, int $intervalCount = 1, ?string $unitLabel = null): Price;

    /**
     * Create a recurring per-unit Price on an existing Product.
     *
     * @param  array<string, string>  $metadata
     */
    public function createPrice(string $productId, array $metadata, int $unitAmount, string $currency, string $interval, int $intervalCount = 1): Price;

    /**
     * Update a Product (e.g. `name`, `default_price`).
     *
     * @param  array<string, mixed>  $params
     */
    public function updateProduct(string $productId, array $params): void;

    /**
     * Archive a Price (`active=false`): no new checkouts, existing
     * subscriptions keep billing on it.
     */
    public function deactivatePrice(string $priceId): void;

    /**
     * Verify the Stripe-Signature header and build the event.
     *
     * @throws SignatureVerificationException
     * @throws \UnexpectedValueException
     */
    public function constructEvent(string $payload, string $sigHeader): Event;
}
