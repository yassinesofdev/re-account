<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeStripeGateway;

class CheckoutSessionTest extends BillingTestCase
{
    public function test_complete_session_syncs_and_returns_context(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, $this->personalFree);
        $metadata = $this->metadataFor($user, $this->personalPro);
        $this->stripe->putSubscription(FakeStripeGateway::subscriptionPayload('sub_new', 'price_personal_pro', 'active', $metadata));
        $this->stripe->sessions['cs_1'] = [
            'id' => 'cs_1', 'object' => 'checkout.session', 'status' => 'complete',
            'customer' => 'cus_1', 'metadata' => $metadata, 'subscription' => 'sub_new',
        ];
        Sanctum::actingAs($user);

        $this->getJson('/api/billing/checkout-sessions/cs_1')
            ->assertOk()
            ->assertJsonPath('status', 'complete')
            ->assertJsonPath('subscription.plan_id', $this->personalPro->id)
            ->assertJsonPath('subscription.is_paid', true)
            ->assertJsonPath('context', ['type' => 'personal', 'org_slug' => null, 'product' => 're-intel']);
    }

    public function test_org_session_context(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgWith($owner);
        $this->stripe->sessions['cs_org'] = [
            'id' => 'cs_org', 'object' => 'checkout.session', 'status' => 'open',
            'customer' => 'cus_1', 'metadata' => $this->metadataFor($org, $this->teamPro, $owner), 'subscription' => null,
        ];
        Sanctum::actingAs($owner);

        $this->getJson('/api/billing/checkout-sessions/cs_org')
            ->assertOk()
            ->assertJsonPath('context.type', 'organization')
            ->assertJsonPath('context.org_slug', $org->slug);
    }

    public function test_open_session_returns_open(): void
    {
        $user = User::factory()->create();
        $sub = $this->subscribe($user, $this->personalFree);
        $this->stripe->sessions['cs_1'] = [
            'id' => 'cs_1', 'object' => 'checkout.session', 'status' => 'open',
            'customer' => 'cus_1', 'metadata' => $this->metadataFor($user, $this->personalPro), 'subscription' => null,
        ];
        Sanctum::actingAs($user);

        $this->getJson('/api/billing/checkout-sessions/cs_1')
            ->assertOk()
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('subscription.plan_id', $this->personalFree->id);

        $this->assertSame($this->personalFree->id, $sub->fresh()->plan_id);
    }

    public function test_foreign_or_unknown_session_is_404(): void
    {
        $owner = User::factory()->create();
        $this->stripe->sessions['cs_1'] = [
            'id' => 'cs_1', 'object' => 'checkout.session', 'status' => 'open',
            'customer' => 'cus_1', 'metadata' => $this->metadataFor($owner, $this->personalPro), 'subscription' => null,
        ];
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/billing/checkout-sessions/cs_1')->assertNotFound();
        $this->getJson('/api/billing/checkout-sessions/cs_missing')->assertNotFound();
    }
}
