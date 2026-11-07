<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesOrganization;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Product;
use App\Services\Billing\BillingService;
use App\Services\Billing\StripeGateway;
use Illuminate\Http\Request;
use Stripe\Exception\InvalidRequestException;

class BillingController extends Controller
{
    use ResolvesOrganization;

    public function __construct(
        private readonly BillingService $billing,
        private readonly StripeGateway $stripe,
    ) {}

    /**
     * Checkout return page: fetch the session and sync synchronously, so the
     * UI doesn't depend on webhook timing.
     */
    public function checkoutSession(Request $request, string $sessionId)
    {
        $this->billing->assertAvailable();

        try {
            $session = $this->stripe->retrieveCheckoutSession($sessionId);
        } catch (InvalidRequestException $e) {
            abort_if($e->getHttpStatus() === 404, 404, 'Checkout session not found.');
            throw $e;
        }

        $metadata = $session->toArray()['metadata'] ?? [];
        abort_if(($metadata['initiated_by_user_id'] ?? null) !== $request->user()->id, 404, 'Checkout session not found.');

        $subscription = $this->billing->syncCheckoutSession($session);
        $subject = $this->billing->subjectFromMetadata($metadata);
        $product = Product::find($metadata['product_id'] ?? null);

        return response()->json([
            'status' => $session->status,
            'subscription' => $this->billing->present($subscription),
            'context' => [
                'type' => $subject instanceof Organization ? 'organization' : 'personal',
                'org_slug' => $subject instanceof Organization ? $subject->slug : null,
                'product' => $product?->slug,
            ],
        ]);
    }

    public function profilePortal(Request $request)
    {
        return response()->json(['url' => $this->billing->portalUrl($request->user())]);
    }

    public function orgPortal(Request $request, string $slug)
    {
        $org = $this->resolveOrg($request, $slug, 'manageBilling');

        return response()->json(['url' => $this->billing->portalUrl($org)]);
    }
}
