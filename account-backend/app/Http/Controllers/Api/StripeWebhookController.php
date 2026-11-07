<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StripeWebhookEvent;
use App\Services\Billing\BillingService;
use App\Services\Billing\StripeGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;

/**
 * Public, verified by the Stripe-Signature header. Handled synchronously
 * (no queue worker in compose). Any exception → 5xx so Stripe retries.
 */
class StripeWebhookController extends Controller
{
    private const SUBSCRIPTION_EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ];

    private const INVOICE_EVENTS = ['invoice.paid', 'invoice.payment_failed'];

    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly BillingService $billing,
    ) {}

    public function handle(Request $request)
    {
        try {
            $event = $this->stripe->constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'));
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $record = StripeWebhookEvent::firstOrCreate(['id' => $event->id], ['type' => $event->type]);

        if ($record->processed_at) {
            return response()->json(['received' => true]);
        }

        DB::transaction(function () use ($event, $record) {
            $this->dispatch($event->type, $event->data->object->toArray());
            $record->forceFill(['processed_at' => now()])->save();
        });

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function dispatch(string $type, array $object): void
    {
        if ($type === 'checkout.session.completed') {
            $this->billing->syncCheckoutSession($this->stripe->retrieveCheckoutSession($object['id']));

            return;
        }

        if (in_array($type, self::SUBSCRIPTION_EVENTS, true)) {
            $this->billing->syncSubscription($this->stripe->retrieveSubscription($object['id']));

            return;
        }

        if (in_array($type, self::INVOICE_EVENTS, true)) {
            // basil+ moved it under parent.subscription_details; older versions had `subscription`.
            $subscriptionId = data_get($object, 'parent.subscription_details.subscription') ?? ($object['subscription'] ?? null);

            if (is_array($subscriptionId)) {
                $subscriptionId = $subscriptionId['id'] ?? null;
            }

            if ($subscriptionId) {
                $this->billing->syncSubscription($this->stripe->retrieveSubscription($subscriptionId));
            }
        }
    }
}
