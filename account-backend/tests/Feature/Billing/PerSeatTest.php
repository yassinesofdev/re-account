<?php

namespace Tests\Feature\Billing;

use App\Models\Organization;
use App\Models\OrgInvite;
use App\Models\OrgMember;
use App\Models\OrgMemberProduct;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeStripeGateway;

/**
 * Per-seat org billing (.project/plans/per-seat-billing.md).
 */
class PerSeatTest extends BillingTestCase
{
    private Plan $teamQ;

    private User $owner;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamQ = Plan::factory()->for($this->product)->organization()->paid(3000, 'price_team_q')
            ->create(['slug' => 'team-q', 'name' => 'Team', 'billing_period' => 'quarter']);
        $this->stripe->storePrice('price_team_q', 'prod_team', 3000, 'usd', 'month', 3);

        $this->owner = User::factory()->create();
        $this->org = $this->orgWith($this->owner);
        Sanctum::actingAs($this->owner);
    }

    public function test_subscribe_goes_to_checkout_with_the_seat_quantity(): void
    {
        $this->subscribe($this->org, $this->teamFree, null, ['quantity' => 2]);

        $this->putJson($this->url(), ['plan_id' => $this->teamQ->id, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        $this->putJson($this->url(), ['plan_id' => $this->teamQ->id, 'quantity' => 5])
            ->assertOk()
            ->assertJsonPath('result', 'checkout')
            ->assertJsonPath('min_seats', 2);

        [$params] = $this->stripe->callsTo('createCheckoutSession')[0];
        $this->assertSame([['price' => 'price_team_q', 'quantity' => 5]], $params['line_items']);

        // Without a quantity: the current one (2).
        $this->putJson($this->url(), ['plan_id' => $this->teamQ->id])->assertOk();
        [$params] = $this->stripe->callsTo('createCheckoutSession')[1];
        $this->assertSame(2, $params['line_items'][0]['quantity']);
    }

    public function test_subscribe_preview_shows_the_first_period_total(): void
    {
        $this->postJson("{$this->url()}/preview", ['plan_id' => $this->teamQ->id, 'quantity' => 4])
            ->assertOk()
            ->assertJsonPath('preview.action', 'subscribe')
            ->assertJsonPath('preview.result', 'checkout')
            ->assertJsonPath('preview.unit_amount_cents', 3000)
            ->assertJsonPath('preview.recurring_amount_cents', 12000)
            ->assertJsonPath('preview.amount_due_now_cents', 12000)
            ->assertJsonPath('preview.billing_period', 'quarter');

        $this->assertSame([], $this->stripe->callsTo('previewInvoice'));
    }

    public function test_quantity_below_seats_in_use_is_rejected_with_counts(): void
    {
        $this->subscribe($this->org, $this->teamFree, null, ['quantity' => 3]);
        $this->assignMembers(2);
        $this->pendingInvite();

        $this->putJson($this->url(), ['plan_id' => $this->teamQ->id, 'quantity' => 3])
            ->assertStatus(409)
            ->assertJsonPath('code', 'seats_over_limit')
            ->assertJsonPath('seats_used', 4)
            ->assertJsonPath('seat_limit', 3)
            ->assertJsonPath('assigned', 3)
            ->assertJsonPath('pending_invites', 1);
    }

    public function test_checkout_completion_stores_quantity_and_item(): void
    {
        $this->subscribe($this->org, $this->teamFree, null, ['quantity' => 2]);
        $metadata = $this->metadataFor($this->org, $this->teamQ, $this->owner);
        $this->stripe->putSubscription(FakeStripeGateway::subscriptionPayload('sub_org', 'price_team_q', 'active', $metadata, null, false, 5));
        $this->stripe->sessions['cs_1'] = [
            'id' => 'cs_1', 'object' => 'checkout.session', 'status' => 'complete',
            'customer' => 'cus_org', 'metadata' => $metadata, 'subscription' => 'sub_org',
        ];

        $this->postWebhook('checkout.session.completed', ['id' => 'cs_1'])->assertOk();

        $row = $this->org->subscriptionFor($this->product);
        $this->assertSame($this->teamQ->id, $row->plan_id);
        $this->assertSame(5, $row->quantity);
        $this->assertSame('si_sub_org', $row->stripe_subscription_item_id);
        $this->getJson("/api/orgs/{$this->org->slug}/seats")->assertJsonPath('seats.0.limit', 5);
    }

    public function test_add_seats_previews_and_charges_the_proration_now(): void
    {
        $row = $this->paidOrg($this->teamQ, 3);

        $preview = $this->postJson("{$this->url()}/preview", ['plan_id' => $this->teamQ->id, 'quantity' => 5])
            ->assertOk()
            ->assertJsonPath('preview.action', 'add_seats')
            ->assertJsonPath('preview.result', 'changed')
            ->assertJsonPath('preview.current_quantity', 3)
            ->assertJsonPath('preview.amount_due_now_cents', 3000) // 2 seats × $30 × half a period
            ->assertJsonPath('preview.recurring_amount_cents', 15000)
            ->json('preview');

        [$previewParams] = $this->stripe->callsTo('previewInvoice')[0];
        $this->assertSame([['id' => 'si_sub_org', 'quantity' => 5]], $previewParams['subscription_details']['items']);
        $this->assertSame('always_invoice', $previewParams['subscription_details']['proration_behavior']);

        $this->putJson($this->url(), [
            'plan_id' => $this->teamQ->id, 'quantity' => 5, 'proration_date' => $preview['proration_date'],
        ])->assertOk()
            ->assertJsonPath('result', 'changed')
            ->assertJsonPath('subscription.quantity', 5)
            ->assertJsonPath('seat_limit', 5);

        [, , $params] = $this->stripe->callsTo('updateSubscriptionItem')[0];
        $this->assertSame('always_invoice', $params['proration_behavior']);
        $this->assertSame('error_if_incomplete', $params['payment_behavior']);
        $this->assertSame([['subscription' => 'sub_org', 'amount_due' => 3000, 'proration_date' => $preview['proration_date']]], $this->stripe->invoices);
        $this->assertSame(5, $row->fresh()->quantity);
    }

    public function test_add_seats_card_failure_returns_402_and_keeps_seats(): void
    {
        $row = $this->paidOrg($this->teamQ, 3);
        $this->stripe->declineCharges = true;

        $this->putJson($this->url(), ['plan_id' => $this->teamQ->id, 'quantity' => 4])
            ->assertStatus(402)
            ->assertJsonPath('code', 'payment_failed');

        $this->assertSame(3, $row->fresh()->quantity);
    }

    public function test_reduce_seats_is_scheduled_for_renewal(): void
    {
        $row = $this->paidOrg($this->teamQ, 5);

        $this->postJson("{$this->url()}/preview", ['plan_id' => $this->teamQ->id, 'quantity' => 3])
            ->assertOk()
            ->assertJsonPath('preview.action', 'reduce_seats')
            ->assertJsonPath('preview.amount_due_now_cents', 0)
            ->assertJsonPath('preview.effective_at', $row->current_period_end->toJSON());

        $this->putJson($this->url(), ['plan_id' => $this->teamQ->id, 'quantity' => 3])
            ->assertOk()
            ->assertJsonPath('result', 'scheduled')
            ->assertJsonPath('subscription.quantity', 5)
            ->assertJsonPath('subscription.scheduled_quantity', 3)
            ->assertJsonPath('seat_limit', 5);

        [, , $params] = $this->stripe->callsTo('updateSubscriptionItem')[0];
        $this->assertSame(3, $params['quantity']);
        $this->assertSame('none', $params['proration_behavior']);
        $this->assertSame([], $this->stripe->invoices);

        // A webhook before renewal keeps the paid seats.
        $this->postWebhook('customer.subscription.updated', ['id' => 'sub_org'], 'evt_a')->assertOk();
        $this->assertSame([5, 3], [$row->fresh()->quantity, $row->fresh()->scheduled_quantity]);

        // Renewal: the period moves on, the lower quantity applies.
        $this->stripe->subscriptions['sub_org']['items']['data'][0]['current_period_end'] = now()->addMonths(6)->getTimestamp();
        $this->postWebhook('invoice.paid', [
            'id' => 'in_renew', 'object' => 'invoice',
            'parent' => ['subscription_details' => ['subscription' => 'sub_org']],
        ], 'evt_b')->assertOk();

        $this->assertSame([3, null], [$row->fresh()->quantity, $row->fresh()->scheduled_quantity]);
    }

    public function test_reduce_below_assigned_or_minimum_is_blocked(): void
    {
        $this->paidOrg($this->teamQ, 5);
        $this->assignMembers(3); // + owner = 4

        $this->putJson($this->url(), ['plan_id' => $this->teamQ->id, 'quantity' => 3])
            ->assertStatus(409)
            ->assertJsonPath('code', 'seats_over_limit')
            ->assertJsonPath('seats_used', 4)
            ->assertJsonPath('assigned', 4);

        $this->putJson($this->url(), ['plan_id' => $this->teamQ->id, 'quantity' => 1])->assertStatus(422);
        $this->assertSame([], $this->stripe->callsTo('updateSubscriptionItem'));
    }

    public function test_keeping_seats_cancels_a_scheduled_reduction(): void
    {
        $row = $this->paidOrg($this->teamQ, 5);
        $this->putJson($this->url(), ['plan_id' => $this->teamQ->id, 'quantity' => 3])->assertOk();

        $this->putJson($this->url(), ['plan_id' => $this->teamQ->id, 'quantity' => 5])
            ->assertOk()
            ->assertJsonPath('result', 'resumed')
            ->assertJsonPath('subscription.scheduled_quantity', null);

        $item = $this->stripe->subscriptions['sub_org']['items']['data'][0];
        $this->assertSame(5, $item['quantity']);
        $this->assertSame([], $item['metadata']);
        $this->assertSame([], $this->stripe->invoices);
        $this->assertSame(5, $row->fresh()->quantity);
    }

    public function test_adding_seats_during_a_scheduled_reduction_charges_only_new_seats(): void
    {
        $this->paidOrg($this->teamQ, 5);
        $this->putJson($this->url(), ['plan_id' => $this->teamQ->id, 'quantity' => 3])->assertOk();

        // Preview from Stripe's post-reduction quantity (3) by the same delta (+1).
        $this->postJson("{$this->url()}/preview", ['plan_id' => $this->teamQ->id, 'quantity' => 6])
            ->assertJsonPath('preview.amount_due_now_cents', 1500);

        $this->putJson($this->url(), ['plan_id' => $this->teamQ->id, 'quantity' => 6])
            ->assertOk()
            ->assertJsonPath('subscription.quantity', 6)
            ->assertJsonPath('subscription.scheduled_quantity', null);

        $this->assertSame([['subscription' => 'sub_org', 'amount_due' => 1500, 'proration_date' => null]], $this->stripe->invoices);
    }

    public function test_second_product_is_co_termed_on_the_same_subscription(): void
    {
        $this->paidOrg($this->teamQ, 3);
        $roleplay = Product::factory()->create(['slug' => 'roleplay', 'name' => 'Roleplay']);
        $roleplayTeam = Plan::factory()->for($roleplay)->organization()->paid(2000, 'price_rp_q')
            ->create(['billing_period' => 'quarter']);
        $this->stripe->storePrice('price_rp_q', 'prod_rp', 2000, 'usd', 'month', 3);
        $url = "/api/orgs/{$this->org->slug}/subscription";

        $this->postJson("{$url}/preview", ['plan_id' => $roleplayTeam->id, 'quantity' => 4])
            ->assertOk()
            ->assertJsonPath('preview.action', 'add_product')
            ->assertJsonPath('preview.amount_due_now_cents', 4000)
            ->assertJsonPath('preview.renews_at', $this->org->subscriptionFor($this->product)->current_period_end->toJSON());

        $this->putJson($url, ['plan_id' => $roleplayTeam->id, 'quantity' => 4])
            ->assertOk()
            ->assertJsonPath('result', 'changed')
            ->assertJsonPath('subscription.quantity', 4)
            ->assertJsonPath('subscription.is_paid', true);

        [$subId, $params] = $this->stripe->callsTo('addSubscriptionItem')[0];
        $this->assertSame('sub_org', $subId);
        $this->assertSame(['price' => 'price_rp_q', 'quantity' => 4, 'proration_behavior' => 'always_invoice', 'payment_behavior' => 'error_if_incomplete'], $params);
        $this->assertSame([], $this->stripe->callsTo('createCheckoutSession'));

        $intel = $this->org->subscriptionFor($this->product);
        $rp = $this->org->subscriptionFor($roleplay);
        $this->assertSame('sub_org', $rp->stripe_subscription_id);
        $this->assertNotSame($intel->stripe_subscription_item_id, $rp->stripe_subscription_item_id);
        $this->assertSame($intel->current_period_end->getTimestamp(), $rp->current_period_end->getTimestamp());
        $this->assertTrue(OrgMemberProduct::where(['org_id' => $this->org->id, 'user_id' => $this->owner->id, 'product_id' => $roleplay->id])->exists());

        // One sync of the shared subscription updates both rows.
        $this->stripe->subscriptions['sub_org']['items']['data'][1]['quantity'] = 6;
        $this->postWebhook('customer.subscription.updated', ['id' => 'sub_org'])->assertOk();
        $this->assertSame([3, 6], [$intel->fresh()->quantity, $rp->fresh()->quantity]);
    }

    public function test_second_product_must_share_the_billing_period(): void
    {
        $this->paidOrg($this->teamQ, 3);
        $roleplay = Product::factory()->create();
        $yearly = Plan::factory()->for($roleplay)->organization()->paid(9000, 'price_rp_y')
            ->create(['billing_period' => 'year']);

        $this->putJson("/api/orgs/{$this->org->slug}/subscription", ['plan_id' => $yearly->id, 'quantity' => 2])
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_period_mismatch')
            ->assertJsonPath('billing_period', 'quarter');
    }

    public function test_dropping_one_of_two_products_ends_it_at_period_end_without_refund(): void
    {
        $intel = $this->paidOrg($this->teamQ, 3);
        $roleplay = Product::factory()->create();
        $rpTeam = Plan::factory()->for($roleplay)->organization()->paid(2000, 'price_rp_q')->create(['billing_period' => 'quarter']);
        $rpFree = Plan::factory()->for($roleplay)->organization()->create();
        $url = "/api/orgs/{$this->org->slug}/subscription";
        $this->putJson($url, ['plan_id' => $rpTeam->id, 'quantity' => 2])->assertOk();

        $this->putJson($url, ['plan_id' => $rpFree->id])
            ->assertOk()
            ->assertJsonPath('result', 'scheduled')
            ->assertJsonPath('subscription.plan_id', $rpTeam->id)
            ->assertJsonPath('subscription.cancel_at_period_end', true)
            ->assertJsonPath('subscription.next_plan.id', $rpFree->id);

        [, , $params] = $this->stripe->callsTo('deleteSubscriptionItem')[0];
        $this->assertSame(['proration_behavior' => 'none'], $params);
        $this->assertSame([], $this->stripe->callsTo('setCancelAtPeriodEnd'));

        // Still paid until the period ends, then free.
        $this->postWebhook('customer.subscription.updated', ['id' => 'sub_org'], 'evt_a')->assertOk();
        $this->assertSame($rpTeam->id, $this->org->subscriptionFor($roleplay)->plan_id);

        $this->travelTo($intel->current_period_end->addMinute());
        $this->stripe->subscriptions['sub_org']['items']['data'][0]['current_period_end'] = now()->addMonths(3)->getTimestamp();
        $this->postWebhook('invoice.paid', ['id' => 'in_1', 'parent' => ['subscription_details' => ['subscription' => 'sub_org']]], 'evt_b')->assertOk();

        $rp = $this->org->subscriptionFor($roleplay);
        $this->assertSame($rpFree->id, $rp->plan_id);
        $this->assertNull($rp->stripe_subscription_id);
        $this->assertSame(2, $rp->quantity);
        $this->assertSame($this->teamQ->id, $intel->fresh()->plan_id);
    }

    public function test_plan_change_keeps_the_quantity(): void
    {
        $this->paidOrg($this->teamQ, 4);
        $plus = Plan::factory()->for($this->product)->organization()->paid(4500, 'price_team_plus')
            ->create(['billing_period' => 'quarter']);
        $this->stripe->storePrice('price_team_plus', 'prod_team', 4500, 'usd', 'month', 3);

        $this->putJson($this->url(), ['plan_id' => $plus->id])
            ->assertOk()
            ->assertJsonPath('result', 'changed')
            ->assertJsonPath('subscription.plan_id', $plus->id)
            ->assertJsonPath('subscription.quantity', 4);

        [, , $params] = $this->stripe->callsTo('updateSubscriptionItem')[0];
        $this->assertSame(['price' => 'price_team_plus', 'quantity' => 4], array_intersect_key($params, ['price' => 1, 'quantity' => 1]));
        $this->assertSame(3000, $this->stripe->invoices[0]['amount_due']); // 4 × ($45 − $30) × half
    }

    public function test_seat_limit_comes_from_the_purchased_quantity(): void
    {
        $this->paidOrg($this->teamQ, 2);
        [$member] = $this->assignMembers(1);
        $third = User::factory()->create();
        OrgMember::create(['org_id' => $this->org->id, 'user_id' => $third->id, 'role' => 'member', 'joined_at' => now()]);

        $this->putJson("/api/orgs/{$this->org->slug}/members/{$third->id}/products", ['product_ids' => [$this->product->id]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'seat_limit_reached')
            ->assertJsonPath('seat_limit', 2);

        $this->getJson("{$this->url()}?product=re-intel")
            ->assertJsonPath('seat_limit', 2)
            ->assertJsonPath('seats_used', 2)
            ->assertJsonPath('subscription.quantity', 2);
    }

    public function test_free_org_plan_seats_change_instantly_within_its_cap(): void
    {
        $row = $this->subscribe($this->org, $this->teamFree, null, ['quantity' => 2]);

        $this->postJson("{$this->url()}/preview", ['plan_id' => $this->teamFree->id, 'quantity' => 3])
            ->assertJsonPath('preview.action', 'update_free')
            ->assertJsonPath('preview.amount_due_now_cents', 0);

        $this->putJson($this->url(), ['plan_id' => $this->teamFree->id, 'quantity' => 3])
            ->assertOk()
            ->assertJsonPath('result', 'changed')
            ->assertJsonPath('seat_limit', 3);

        $this->putJson($this->url(), ['plan_id' => $this->teamFree->id, 'quantity' => 4])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        $this->assertSame(3, $row->fresh()->quantity);
        $this->assertSame([], $this->stripe->calls);
    }

    public function test_free_org_plan_is_capped_by_the_global_setting(): void
    {
        config(['billing.free_org_max_seats' => 5]);
        $row = $this->subscribe($this->org, $this->teamFree, null, ['quantity' => 2]);

        $this->putJson($this->url(), ['plan_id' => $this->teamFree->id, 'quantity' => 5])
            ->assertOk()
            ->assertJsonPath('seat_limit', 5);

        $this->putJson($this->url(), ['plan_id' => $this->teamFree->id, 'quantity' => 6])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        $this->getJson("{$this->url()}?product=re-intel")->assertJsonPath('max_seats', 5);

        // Lowering the cap leaves existing free rows at their quantity.
        config(['billing.free_org_max_seats' => 3]);
        $this->putJson($this->url(), ['plan_id' => $this->teamFree->id, 'quantity' => 5])->assertOk();
        $this->assertSame(5, $row->fresh()->quantity);
        $this->getJson("{$this->url()}?product=re-intel")->assertJsonPath('max_seats', 5);
        $this->putJson($this->url(), ['plan_id' => $this->teamFree->id, 'quantity' => 6])->assertStatus(422);
    }

    public function test_paid_org_plan_quantity_has_no_cap_beyond_the_minimum(): void
    {
        $this->subscribe($this->org, $this->teamFree, null, ['quantity' => 2]);

        $this->putJson($this->url(), ['plan_id' => $this->teamQ->id, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        $this->putJson($this->url(), ['plan_id' => $this->teamQ->id, 'quantity' => 250])
            ->assertOk()
            ->assertJsonPath('result', 'checkout')
            ->assertJsonPath('max_seats', null);

        [$params] = $this->stripe->callsTo('createCheckoutSession')[0];
        $this->assertSame(250, $params['line_items'][0]['quantity']);
    }

    public function test_admin_creates_and_updates_org_plans_without_a_seat_limit(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

        $plan = $this->postJson('/api/admin/plans', [
            'product_id' => $this->product->id, 'name' => 'Agency', 'slug' => 'agency',
            'scope' => 'organization', 'price_cents' => 2500, 'billing_period' => 'year',
        ])->assertCreated()->json('plan');
        $this->assertArrayNotHasKey('seat_limit', $plan);
        $this->assertNull($plan['max_seats']);

        $this->putJson("/api/admin/plans/{$plan['id']}", ['name' => 'Agency+', 'price_cents' => 2900, 'seat_limit' => 10])
            ->assertOk()
            ->assertJsonPath('plan.name', 'Agency+')
            ->assertJsonMissingPath('plan.seat_limit');

        $free = $this->postJson('/api/admin/plans', [
            'product_id' => $this->product->id, 'name' => 'Starter', 'slug' => 'starter', 'scope' => 'organization',
        ])->assertCreated()->json('plan');
        $this->assertSame(3, $free['max_seats']); // free: the global cap
    }

    public function test_preview_needs_stripe_only_for_prorations(): void
    {
        $this->paidOrg($this->teamQ, 3);
        config(['services.stripe.secret' => '']);

        $this->postJson("{$this->url()}/preview", ['plan_id' => $this->teamQ->id, 'quantity' => 4])
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_unavailable');

        $this->postJson("{$this->url()}/preview", ['plan_id' => $this->teamQ->id, 'quantity' => 2])
            ->assertOk()
            ->assertJsonPath('preview.action', 'reduce_seats');
    }

    public function test_only_the_owner_can_preview(): void
    {
        $admin = User::factory()->create();
        OrgMember::create(['org_id' => $this->org->id, 'user_id' => $admin->id, 'role' => 'admin', 'joined_at' => now()]);
        Sanctum::actingAs($admin);

        $this->postJson("{$this->url()}/preview", ['plan_id' => $this->teamQ->id, 'quantity' => 2])->assertForbidden();
    }

    public function test_personal_plans_stay_quantity_one(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/profile/subscription', ['plan_id' => $this->personalPro->id])->assertOk();
        [$params] = $this->stripe->callsTo('createCheckoutSession')[0];
        $this->assertSame([['price' => 'price_personal_pro', 'quantity' => 1]], $params['line_items']);

        $this->stripe->putSubscription(FakeStripeGateway::subscriptionPayload('sub_me', 'price_personal_pro', 'active', $this->metadataFor($user, $this->personalPro)));
        $this->postWebhook('customer.subscription.created', ['id' => 'sub_me'])->assertOk();

        $row = $user->subscriptionFor($this->product);
        $this->assertNull($row->quantity);
        $this->assertNull($row->stripe_subscription_item_id);
    }

    public function test_admin_org_plans_bill_per_quarter_or_year_and_sync_per_seat_prices(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));
        $base = ['product_id' => $this->product->id, 'name' => 'Agency', 'scope' => 'organization', 'price_cents' => 2500];

        $this->postJson('/api/admin/plans', [...$base, 'slug' => 'agency-m', 'billing_period' => 'month'])
            ->assertStatus(422)->assertJsonValidationErrors('billing_period');

        $id = $this->postJson('/api/admin/plans', [...$base, 'slug' => 'agency-q', 'billing_period' => 'quarter'])
            ->assertCreated()->json('plan.id');

        $this->postJson("/api/admin/plans/{$id}/stripe-sync")
            ->assertOk()
            ->assertJsonPath('plan.stripe_sync_status', 'synced');

        [, , $amount, , $interval, $count, $unitLabel] = $this->stripe->callsTo('createProductAndPrice')[0];
        $this->assertSame([2500, 'month', 3, 'seat'], [$amount, $interval, $count, $unitLabel]);

        // An existing monthly org plan can still be edited (name only).
        $this->putJson("/api/admin/plans/{$this->teamPro->id}", ['name' => 'Team legacy'])->assertOk();
    }

    public function test_migration_backfills_org_quantities(): void
    {
        $unlimited = Plan::factory()->for($this->product)->organization()->create();
        $other = $this->orgWith(User::factory()->create());
        $open = $this->subscribe($other, $unlimited);
        foreach (User::factory()->count(2)->create() as $u) {
            OrgMember::create(['org_id' => $other->id, 'user_id' => $u->id, 'role' => 'member', 'joined_at' => now()]);
            OrgMemberProduct::create(['org_id' => $other->id, 'user_id' => $u->id, 'product_id' => $this->product->id]);
        }
        $personal = $this->subscribe(User::factory()->create(), $this->personalFree);

        (require database_path('migrations/2026_11_02_000002_per_seat_subscriptions.php'))->backfill();

        $this->assertSame(3, $open->fresh()->quantity);     // owner + 2 assigned
        $this->assertNull($personal->fresh()->quantity);
    }

    private function url(): string
    {
        return "/api/orgs/{$this->org->slug}/subscription";
    }

    /**
     * The org pays for $plan with $quantity seats on Stripe subscription sub_org.
     */
    private function paidOrg(Plan $plan, int $quantity): Subscription
    {
        $periodEnd = now()->addMonths(2)->startOfSecond();
        $this->org->forceFill(['stripe_customer_id' => 'cus_org'])->save();
        $this->stripe->putSubscription(FakeStripeGateway::subscriptionPayload(
            'sub_org', $plan->stripe_price_id, 'active', $this->metadataFor($this->org, $plan, $this->owner),
            $periodEnd->getTimestamp(), false, $quantity,
        ));

        return $this->subscribe($this->org, $plan, 'sub_org', [
            'quantity' => $quantity,
            'stripe_subscription_item_id' => 'si_sub_org',
            'current_period_end' => $periodEnd,
        ]);
    }

    /**
     * @return array<int, User>
     */
    private function assignMembers(int $count): array
    {
        $users = User::factory()->count($count)->create()->all();

        foreach ($users as $user) {
            OrgMember::create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => 'member', 'joined_at' => now()]);
            OrgMemberProduct::create(['org_id' => $this->org->id, 'user_id' => $user->id, 'product_id' => $this->product->id]);
        }

        return $users;
    }

    private function pendingInvite(): void
    {
        $invite = OrgInvite::create([
            'org_id' => $this->org->id, 'email' => 'pending@example.com', 'role' => 'member',
            'token' => hash('sha256', Str::random()), 'invited_by' => $this->owner->id, 'expires_at' => now()->addDay(),
        ]);
        $invite->products()->attach($this->product->id, ['id' => (string) Str::uuid()]);
    }
}
