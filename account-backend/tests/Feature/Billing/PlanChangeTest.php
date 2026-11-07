<?php

namespace Tests\Feature\Billing;

use App\Models\OrgInvite;
use App\Models\OrgMember;
use App\Models\OrgMemberProduct;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class PlanChangeTest extends BillingTestCase
{
    public function test_free_to_free_changes_without_stripe(): void
    {
        $user = User::factory()->create();
        $otherFree = Plan::factory()->for($this->product)->create(['slug' => 'personal-basic']);
        $this->subscribe($user, $this->personalFree);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile/subscription', ['plan_id' => $otherFree->id])
            ->assertOk()
            ->assertJsonPath('result', 'changed')
            ->assertJsonPath('checkout_url', null)
            ->assertJsonPath('subscription.plan_id', $otherFree->id)
            ->assertJsonPath('subscription.is_paid', false)
            ->assertJsonPath('subscription.next_plan', null);

        $this->assertSame([], $this->stripe->calls);
    }

    public function test_free_to_paid_returns_checkout_and_keeps_local_row(): void
    {
        $user = User::factory()->create();
        $sub = $this->subscribe($user, $this->personalFree);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/profile/subscription', ['plan_id' => $this->personalPro->id])
            ->assertOk()
            ->assertJsonPath('result', 'checkout')
            ->assertJsonPath('subscription.plan_id', $this->personalFree->id);

        $this->assertStringStartsWith('https://checkout.stripe.com/', $response->json('checkout_url'));

        [$params] = $this->stripe->callsTo('createCheckoutSession')[0];
        $this->assertSame('subscription', $params['mode']);
        $this->assertSame([['price' => 'price_personal_pro', 'quantity' => 1]], $params['line_items']);
        $this->assertSame('http://localhost:3742/billing/return?session_id={CHECKOUT_SESSION_ID}', $params['success_url']);
        $this->assertSame('http://localhost:3742/profile?billing=canceled', $params['cancel_url']);
        $this->assertSame($user->id, $params['client_reference_id']);
        $this->assertFalse($params['allow_promotion_codes']);
        $expected = $this->metadataFor($user, $this->personalPro);
        $this->assertSame($expected, $params['metadata']);
        $this->assertSame($expected, $params['subscription_data']['metadata']);

        $this->assertSame($this->personalFree->id, $sub->fresh()->plan_id);

        // Second attempt reuses the customer.
        $this->putJson('/api/profile/subscription', ['plan_id' => $this->personalPro->id])->assertOk();
        $this->assertCount(1, $this->stripe->callsTo('createCustomer'));
        [, $key] = $this->stripe->callsTo('createCustomer')[0];
        $this->assertSame("customer-user-{$user->id}", $key);
        $this->assertNotNull($user->fresh()->stripe_customer_id);
    }

    public function test_paid_to_paid_swaps_price(): void
    {
        $user = User::factory()->create();
        $yearly = Plan::factory()->for($this->product)->paid(12000, 'price_personal_pro_year')
            ->create(['slug' => 'personal-pro-year', 'billing_period' => 'year']);
        $this->subscribePaid($user, $this->personalPro, 'sub_1');
        Sanctum::actingAs($user);

        $this->putJson('/api/profile/subscription', ['plan_id' => $yearly->id])
            ->assertOk()
            ->assertJsonPath('result', 'changed')
            ->assertJsonPath('subscription.plan_id', $yearly->id)
            ->assertJsonPath('subscription.is_paid', true)
            ->assertJsonMissingPath('subscription.stripe_subscription_id');

        $this->assertSame([['sub_1', 'price_personal_pro_year']], $this->stripe->callsTo('swapSubscriptionPrice'));
    }

    public function test_paid_to_paid_card_failure_returns_402(): void
    {
        $user = User::factory()->create();
        $yearly = Plan::factory()->for($this->product)->paid(12000, 'price_year')->create(['billing_period' => 'year']);
        $sub = $this->subscribePaid($user, $this->personalPro, 'sub_1');
        $this->stripe->declineSwap = true;
        Sanctum::actingAs($user);

        $this->putJson('/api/profile/subscription', ['plan_id' => $yearly->id])
            ->assertStatus(402)
            ->assertJsonPath('code', 'payment_failed');

        $this->assertSame($this->personalPro->id, $sub->fresh()->plan_id);
    }

    public function test_paid_to_paid_pending_update_returns_402(): void
    {
        $user = User::factory()->create();
        $yearly = Plan::factory()->for($this->product)->paid(12000, 'price_year')->create(['billing_period' => 'year']);
        $this->subscribePaid($user, $this->personalPro, 'sub_1');
        $this->stripe->swapLeavesPendingUpdate = true;
        Sanctum::actingAs($user);

        $this->putJson('/api/profile/subscription', ['plan_id' => $yearly->id])
            ->assertStatus(402)
            ->assertJsonPath('code', 'payment_failed');
    }

    public function test_paid_to_free_is_scheduled_with_next_plan(): void
    {
        $user = User::factory()->create();
        $this->subscribePaid($user, $this->personalPro, 'sub_1');
        Sanctum::actingAs($user);

        $this->putJson('/api/profile/subscription', ['plan_id' => $this->personalFree->id])
            ->assertOk()
            ->assertJsonPath('result', 'scheduled')
            ->assertJsonPath('subscription.plan_id', $this->personalPro->id)
            ->assertJsonPath('subscription.cancel_at_period_end', true)
            ->assertJsonPath('subscription.next_plan.id', $this->personalFree->id);

        $this->assertSame([['sub_1', true]], $this->stripe->callsTo('setCancelAtPeriodEnd'));
    }

    public function test_same_plan_with_scheduled_cancellation_resumes(): void
    {
        $user = User::factory()->create();
        $this->subscribePaid($user, $this->personalPro, 'sub_1', ['cancel_at_period_end' => true]);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile/subscription', ['plan_id' => $this->personalPro->id])
            ->assertOk()
            ->assertJsonPath('result', 'resumed')
            ->assertJsonPath('subscription.cancel_at_period_end', false)
            ->assertJsonPath('subscription.next_plan', null);

        $this->assertSame([['sub_1', false]], $this->stripe->callsTo('setCancelAtPeriodEnd'));
    }

    public function test_past_due_blocks_plan_changes(): void
    {
        $user = User::factory()->create();
        $this->subscribePaid($user, $this->personalPro, 'sub_1', ['status' => 'past_due']);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile/subscription', ['plan_id' => $this->personalFree->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_issue');

        $this->assertSame([], $this->stripe->calls);
    }

    public function test_org_over_seat_limit_is_rejected(): void
    {
        // Seats are per product: the owner (auto-assigned on subscribe) + 3
        // assigned members + 1 pending invite for this product = 5.
        $owner = User::factory()->create();
        $members = User::factory()->count(3)->create()->all();
        $org = $this->orgWith($owner, ['member' => $members]);
        $this->subscribePaid($org, $this->teamPro, 'sub_org');
        foreach ($members as $member) {
            OrgMemberProduct::create(['org_id' => $org->id, 'user_id' => $member->id, 'product_id' => $this->product->id]);
        }
        $invite = OrgInvite::create([
            'org_id' => $org->id, 'email' => 'x@example.com', 'role' => 'member',
            'token' => hash('sha256', 'x'), 'invited_by' => $owner->id, 'expires_at' => now()->addDay(),
        ]);
        $invite->products()->attach($this->product->id, ['id' => (string) Str::uuid()]);
        // An unassigned member doesn't use a seat.
        OrgMember::create(['org_id' => $org->id, 'user_id' => User::factory()->create()->id, 'role' => 'member']);
        Sanctum::actingAs($owner);

        $this->putJson("/api/orgs/{$org->slug}/subscription", ['plan_id' => $this->teamFree->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'seats_over_limit')
            ->assertJsonPath('seats_used', 5)
            ->assertJsonPath('seat_limit', 3);
    }

    public function test_org_owner_upgrade_goes_to_checkout(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgWith($owner);
        $this->subscribe($org, $this->teamFree);
        Sanctum::actingAs($owner);

        $this->putJson("/api/orgs/{$org->slug}/subscription", ['plan_id' => $this->teamPro->id])
            ->assertOk()
            ->assertJsonPath('result', 'checkout')
            ->assertJsonPath('seats_used', 1)
            ->assertJsonPath('seat_limit', 3);

        [$params] = $this->stripe->callsTo('createCheckoutSession')[0];
        $this->assertSame("http://localhost:3742/orgs/{$org->slug}/settings?billing=canceled", $params['cancel_url']);
        $this->assertSame('organization', $params['metadata']['subscribable_type']);
        $this->assertSame($owner->id, $params['metadata']['initiated_by_user_id']);
        [$customer, $key] = $this->stripe->callsTo('createCustomer')[0];
        $this->assertSame($owner->email, $customer['email']);
        $this->assertSame("customer-organization-{$org->id}", $key);
    }

    public function test_org_non_owner_gets_403(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $org = $this->orgWith($owner, ['admin' => $admin]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/orgs/{$org->slug}/subscription", ['plan_id' => $this->teamPro->id])
            ->assertForbidden();
    }

    public function test_paid_plan_without_price_is_not_purchasable(): void
    {
        $user = User::factory()->create();
        $unlinked = Plan::factory()->for($this->product)->create(['price_cents' => 900, 'billing_period' => 'month']);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile/subscription', ['plan_id' => $unlinked->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'plan_not_purchasable');
    }

    public function test_missing_secret_returns_503(): void
    {
        config(['services.stripe.secret' => '']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/profile/subscription', ['plan_id' => $this->personalPro->id])
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_unavailable');

        // Free plans keep working without Stripe.
        $this->putJson('/api/profile/subscription', ['plan_id' => $this->personalFree->id])
            ->assertOk()
            ->assertJsonPath('result', 'changed');
    }

    public function test_wrong_scope_plan_is_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/profile/subscription', ['plan_id' => $this->teamPro->id])->assertNotFound();
    }
}
