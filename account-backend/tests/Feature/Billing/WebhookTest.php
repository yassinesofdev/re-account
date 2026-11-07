<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\Product;
use App\Models\StripeWebhookEvent;
use App\Models\Subscription;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Facades\Hash;
use Tests\Fakes\FakeStripeGateway;

class WebhookTest extends BillingTestCase
{
    public function test_bad_signature_returns_400(): void
    {
        $this->postWebhook('customer.subscription.updated', ['id' => 'sub_1'], 'evt_1', 'whsec_wrong')
            ->assertStatus(400)
            ->assertJson(['message' => 'Invalid signature.']);

        $this->assertSame(0, StripeWebhookEvent::count());
    }

    public function test_duplicate_event_is_processed_once(): void
    {
        $user = User::factory()->create();
        $this->subscribePaid($user, $this->personalPro, 'sub_1');

        $this->postWebhook('customer.subscription.updated', ['id' => 'sub_1'], 'evt_dup')->assertOk()->assertJson(['received' => true]);
        $this->postWebhook('customer.subscription.updated', ['id' => 'sub_1'], 'evt_dup')->assertOk()->assertJson(['received' => true]);

        $this->assertCount(1, $this->stripe->callsTo('retrieveSubscription'));
        $this->assertNotNull(StripeWebhookEvent::find('evt_dup')->processed_at);
    }

    public function test_ignored_event_type_returns_200(): void
    {
        $this->postWebhook('customer.created', ['id' => 'cus_1'])->assertOk()->assertJson(['received' => true]);
        $this->assertSame([], $this->stripe->calls);
    }

    public function test_checkout_completed_links_paid_plan(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => null]);
        $sub = $this->subscribe($user, $this->personalFree);
        $metadata = $this->metadataFor($user, $this->personalPro);
        $this->stripe->putSubscription(FakeStripeGateway::subscriptionPayload('sub_new', 'price_personal_pro', 'active', $metadata));
        $this->stripe->sessions['cs_1'] = [
            'id' => 'cs_1', 'object' => 'checkout.session', 'status' => 'complete',
            'customer' => 'cus_new', 'metadata' => $metadata, 'subscription' => 'sub_new',
        ];

        $this->postWebhook('checkout.session.completed', ['id' => 'cs_1'])->assertOk();

        $sub->refresh();
        $this->assertSame($this->personalPro->id, $sub->plan_id);
        $this->assertSame('sub_new', $sub->stripe_subscription_id);
        $this->assertSame('active', $sub->status);
        $this->assertNotNull($sub->current_period_end);
        $this->assertSame('cus_new', $user->fresh()->stripe_customer_id);
    }

    public function test_checkout_completed_creates_row_when_none_exists(): void
    {
        $user = User::factory()->create();
        $metadata = $this->metadataFor($user, $this->personalPro);
        $this->stripe->putSubscription(FakeStripeGateway::subscriptionPayload('sub_new', 'price_personal_pro', 'active', $metadata));

        $this->postWebhook('customer.subscription.created', ['id' => 'sub_new'])->assertOk();

        $sub = $user->subscriptionFor($this->product);
        $this->assertSame($this->personalPro->id, $sub->plan_id);
        $this->assertTrue($sub->is_paid);
    }

    public function test_past_due_keeps_plan(): void
    {
        $user = User::factory()->create();
        $sub = $this->subscribePaid($user, $this->personalPro, 'sub_1');
        $this->stripe->subscriptions['sub_1']['status'] = 'past_due';

        $this->postWebhook('customer.subscription.updated', ['id' => 'sub_1'])->assertOk();

        $sub->refresh();
        $this->assertSame('past_due', $sub->status);
        $this->assertSame($this->personalPro->id, $sub->plan_id);
        $this->assertTrue($sub->isEntitled());
    }

    public function test_deleted_falls_back_to_free(): void
    {
        $user = User::factory()->create();
        $sub = $this->subscribePaid($user, $this->personalPro, 'sub_1');
        $this->stripe->subscriptions['sub_1']['status'] = 'canceled';

        $this->postWebhook('customer.subscription.deleted', ['id' => 'sub_1'])->assertOk();

        $this->assertFreeFallback($sub->fresh());
    }

    public function test_unpaid_falls_back_to_free(): void
    {
        $user = User::factory()->create();
        $sub = $this->subscribePaid($user, $this->personalPro, 'sub_1');
        $this->stripe->subscriptions['sub_1']['status'] = 'unpaid';

        $this->postWebhook('customer.subscription.updated', ['id' => 'sub_1'])->assertOk();

        $this->assertFreeFallback($sub->fresh());
    }

    public function test_without_free_plan_row_is_canceled_and_verify_token_has_no_plan(): void
    {
        $product = Product::factory()->create(['secret_key' => Hash::make('the-secret')]);
        $pro = Plan::factory()->for($product)->paid(1500, 'price_solo')->create();
        $user = User::factory()->create();
        $sub = $this->subscribe($user, $pro, 'sub_solo');
        $this->stripe->putSubscription(FakeStripeGateway::subscriptionPayload('sub_solo', 'price_solo', 'canceled'));

        $this->postWebhook('customer.subscription.deleted', ['id' => 'sub_solo'])->assertOk();

        $sub->refresh();
        $this->assertSame('canceled', $sub->status);
        $this->assertSame($pro->id, $sub->plan_id);

        $token = app(TokenService::class)->issueHandoffToken($user, $product->slug);
        $this->postJson('/api/verify-token', ['token' => $token], ['X-Product-Secret' => 'the-secret'])
            ->assertOk()
            ->assertJsonPath('plan', null);
    }

    public function test_unknown_price_keeps_plan(): void
    {
        $user = User::factory()->create();
        $sub = $this->subscribePaid($user, $this->personalPro, 'sub_1');
        $this->stripe->subscriptions['sub_1']['items']['data'][0]['price']['id'] = 'price_unknown';

        $this->postWebhook('customer.subscription.updated', ['id' => 'sub_1'])->assertOk();

        $sub->refresh();
        $this->assertSame($this->personalPro->id, $sub->plan_id);
        $this->assertSame('price_unknown', $sub->stripe_price_id);
    }

    public function test_invoice_paid_updates_period_end_basil_shape(): void
    {
        $user = User::factory()->create();
        $sub = $this->subscribePaid($user, $this->personalPro, 'sub_1');
        $newEnd = now()->addMonths(2)->startOfSecond();
        $this->stripe->subscriptions['sub_1']['items']['data'][0]['current_period_end'] = $newEnd->getTimestamp();

        $this->postWebhook('invoice.paid', [
            'id' => 'in_1',
            'object' => 'invoice',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']],
        ])->assertOk();

        $this->assertSame($newEnd->getTimestamp(), $sub->fresh()->current_period_end->getTimestamp());
    }

    public function test_duplicate_stripe_subscription_is_canceled(): void
    {
        $user = User::factory()->create();
        $sub = $this->subscribePaid($user, $this->personalPro, 'sub_1');
        $this->stripe->putSubscription(FakeStripeGateway::subscriptionPayload(
            'sub_2', 'price_personal_pro', 'active', $this->metadataFor($user, $this->personalPro),
        ));

        $this->postWebhook('customer.subscription.created', ['id' => 'sub_2'])->assertOk();

        $this->assertSame([['sub_2']], $this->stripe->callsTo('cancelNow'));
        $this->assertSame('sub_1', $sub->fresh()->stripe_subscription_id);
    }

    public function test_missing_webhook_secret_returns_503(): void
    {
        config(['services.stripe.webhook_secret' => '']);

        $this->postWebhook('customer.subscription.updated', ['id' => 'sub_1'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_unavailable');
    }

    private function assertFreeFallback(Subscription $sub): void
    {
        $this->assertSame($this->personalFree->id, $sub->plan_id);
        $this->assertSame('active', $sub->status);
        $this->assertNull($sub->stripe_subscription_id);
        $this->assertNull($sub->current_period_end);
        $this->assertFalse($sub->cancel_at_period_end);
        $this->assertNotNull($sub->canceled_at);
    }
}
