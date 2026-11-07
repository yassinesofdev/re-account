<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\StripeGateway;
use Laravel\Sanctum\Sanctum;
use Stripe\Exception\InvalidRequestException;
use Stripe\Price;
use Tests\Fakes\FakeStripeGateway;

/**
 * Plan::stripe_sync_status, POST /admin/plans/{plan}/stripe-sync and
 * POST /admin/products/{product}/stripe-sync.
 */
class StripePlanSyncTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));
    }

    private function markSynced(Plan $plan): Plan
    {
        $plan->forceFill([
            'stripe_synced_price_cents' => $plan->price_cents,
            'stripe_synced_billing_period' => $plan->billing_period,
            'stripe_synced_name' => $plan->name,
            'stripe_synced_at' => now(),
        ])->save();

        return $plan;
    }

    public function test_status_computation(): void
    {
        $this->assertSame('not_required', $this->personalFree->stripe_sync_status);
        // Linked by hand, never verified.
        $this->assertSame('out_of_sync', $this->personalPro->stripe_sync_status);

        $this->markSynced($this->personalPro);
        $this->assertSame('synced', $this->personalPro->fresh()->stripe_sync_status);

        $this->personalPro->update(['price_cents' => 1500]);
        $this->assertSame('out_of_sync', $this->personalPro->fresh()->stripe_sync_status);

        $this->markSynced($this->personalPro)->update(['name' => 'Pro Plus']);
        $this->assertSame('out_of_sync', $this->personalPro->fresh()->stripe_sync_status);

        $this->markSynced($this->personalPro)->update(['billing_period' => 'year']);
        $this->assertSame('out_of_sync', $this->personalPro->fresh()->stripe_sync_status);

        $this->personalPro->forceFill(['stripe_price_id' => null])->save();
        $this->assertSame('not_synced', $this->personalPro->fresh()->stripe_sync_status);

        $this->getJson("/api/admin/products/{$this->product->id}/plans")
            ->assertOk()
            ->assertJsonPath('plans.0.stripe_sync_status', 'not_required');
    }

    public function test_manual_link_is_verified_against_stripe(): void
    {
        $this->stripe->storePrice('price_live', 'prod_live', 1200);

        $this->putJson("/api/admin/plans/{$this->personalPro->id}", ['stripe_price_id' => 'price_live'])
            ->assertOk()
            ->assertJsonPath('plan.stripe_sync_status', 'synced');

        // A Price whose amount doesn't match the plan → out_of_sync.
        $this->stripe->storePrice('price_wrong', 'prod_live', 999);

        $this->putJson("/api/admin/plans/{$this->personalPro->id}", ['stripe_price_id' => 'price_wrong'])
            ->assertOk()
            ->assertJsonPath('plan.stripe_sync_status', 'out_of_sync');

        // Stripe not configured → out_of_sync, the save still succeeds.
        config(['services.stripe.secret' => '']);
        $this->stripe->storePrice('price_other', 'prod_live', 1200);

        $this->putJson("/api/admin/plans/{$this->personalPro->id}", ['stripe_price_id' => 'price_other'])
            ->assertOk()
            ->assertJsonPath('plan.stripe_sync_status', 'out_of_sync');
    }

    public function test_sync_creates_product_and_price_for_unlinked_plan(): void
    {
        $this->personalPro->forceFill(['stripe_price_id' => null, 'stripe_product_id' => null])->save();

        $res = $this->postJson("/api/admin/plans/{$this->personalPro->id}/stripe-sync")
            ->assertOk()
            ->assertJsonPath('plan.stripe_sync_status', 'synced')
            ->assertJsonPath('plan.stripe_synced_price_cents', 1200);

        $this->assertStringStartsWith('price_fake_', $res->json('plan.stripe_price_id'));
        $this->assertStringStartsWith('prod_fake_', $res->json('plan.stripe_product_id'));
        [$name] = $this->stripe->callsTo('createProductAndPrice')[0];
        $this->assertSame('RE Intel Pro', $name);
    }

    public function test_price_change_creates_new_price_and_archives_old_one(): void
    {
        $this->personalPro->forceFill(['stripe_product_id' => 'prod_pro'])->save();
        $this->markSynced($this->personalPro)->update(['price_cents' => 1500, 'billing_period' => 'year']);

        $this->postJson("/api/admin/plans/{$this->personalPro->id}/stripe-sync")
            ->assertOk()
            ->assertJsonPath('plan.stripe_sync_status', 'synced')
            ->assertJsonPath('plan.stripe_product_id', 'prod_pro');

        $plan = $this->personalPro->fresh();
        $this->assertNotSame('price_personal_pro', $plan->stripe_price_id);
        $this->assertSame([['prod_pro', ['plan_id' => $plan->id], 1500, 'usd', 'year', 1]], $this->stripe->callsTo('createPrice'));
        $this->assertSame([['prod_pro', ['name' => 'RE Intel Pro', 'default_price' => $plan->stripe_price_id]]], $this->stripe->callsTo('updateProduct'));
        $this->assertSame([['price_personal_pro']], $this->stripe->callsTo('deactivatePrice'));
        $this->assertSame([], $this->stripe->callsTo('createProductAndPrice'));
    }

    public function test_name_change_only_renames_the_stripe_product(): void
    {
        $this->personalPro->forceFill(['stripe_product_id' => 'prod_pro'])->save();
        $this->markSynced($this->personalPro)->update(['name' => 'Pro Plus']);

        $this->postJson("/api/admin/plans/{$this->personalPro->id}/stripe-sync")
            ->assertOk()
            ->assertJsonPath('plan.stripe_sync_status', 'synced')
            ->assertJsonPath('plan.stripe_price_id', 'price_personal_pro');

        $this->assertSame([['prod_pro', ['name' => 'RE Intel Pro Plus']]], $this->stripe->callsTo('updateProduct'));
        $this->assertSame([], $this->stripe->callsTo('createPrice'));
        $this->assertSame([], $this->stripe->callsTo('deactivatePrice'));
    }

    public function test_unverified_link_matching_stripe_is_adopted_without_new_price(): void
    {
        $this->personalPro->forceFill(['stripe_product_id' => null])->save();
        $this->stripe->storePrice('price_personal_pro', 'prod_existing', 1200);

        $this->postJson("/api/admin/plans/{$this->personalPro->id}/stripe-sync")
            ->assertOk()
            ->assertJsonPath('plan.stripe_sync_status', 'synced')
            ->assertJsonPath('plan.stripe_price_id', 'price_personal_pro')
            ->assertJsonPath('plan.stripe_product_id', 'prod_existing');

        $this->assertSame([], $this->stripe->callsTo('createPrice'));
    }

    public function test_sync_is_a_noop_for_free_and_synced_plans(): void
    {
        $this->markSynced($this->personalPro);

        $this->postJson("/api/admin/plans/{$this->personalFree->id}/stripe-sync")
            ->assertOk()->assertJsonPath('plan.stripe_sync_status', 'not_required');
        $this->postJson("/api/admin/plans/{$this->personalPro->id}/stripe-sync")
            ->assertOk()->assertJsonPath('plan.stripe_sync_status', 'synced');

        $this->assertSame([], $this->stripe->calls);
    }

    public function test_sync_errors(): void
    {
        config(['services.stripe.secret' => '']);
        $this->postJson("/api/admin/plans/{$this->personalPro->id}/stripe-sync")
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_unavailable');

        config(['services.stripe.secret' => 'sk_test_fake']);
        $this->personalPro->forceFill(['billing_period' => null])->save();
        $this->postJson("/api/admin/plans/{$this->personalPro->id}/stripe-sync")
            ->assertStatus(422)
            ->assertJsonValidationErrors('billing_period');
    }

    public function test_sync_requires_super_admin(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/admin/plans/{$this->personalPro->id}/stripe-sync")->assertForbidden();
        $this->postJson("/api/admin/products/{$this->product->id}/stripe-sync")->assertForbidden();
    }

    public function test_product_summary_and_sync_all(): void
    {
        $this->teamPro->forceFill(['stripe_price_id' => null, 'stripe_product_id' => null])->save();
        $inactive = Plan::factory()->for($this->product)->paid(9900)->create(['slug' => 'legacy', 'is_active' => false]);

        $this->getJson('/api/admin/products')
            ->assertOk()
            ->assertJsonPath('products.0.stripe_sync_summary', [
                'status' => 'needs_sync', 'synced' => 0, 'out_of_sync' => 1, 'not_synced' => 1,
            ]);

        $this->postJson("/api/admin/products/{$this->product->id}/stripe-sync")
            ->assertOk()
            ->assertJsonCount(5, 'plans')
            ->assertJsonPath('summary', ['status' => 'synced', 'synced' => 2, 'out_of_sync' => 0, 'not_synced' => 0]);

        $this->assertSame('out_of_sync', $inactive->fresh()->stripe_sync_status);

        $this->getJson("/api/admin/products/{$this->product->id}")
            ->assertOk()
            ->assertJsonPath('product.stripe_sync_summary.status', 'synced')
            ->assertJsonMissingPath('product.secret_key');
    }

    public function test_product_with_only_free_plans_does_not_require_sync(): void
    {
        $this->personalPro->delete();
        $this->teamPro->delete();

        $this->getJson("/api/admin/products/{$this->product->id}")
            ->assertOk()
            ->assertJsonPath('product.stripe_sync_summary.status', 'not_required');
    }

    public function test_stripe_api_error_is_502(): void
    {
        $this->personalPro->forceFill(['stripe_price_id' => 'price_missing'])->save();
        $failing = new class extends FakeStripeGateway
        {
            public function retrievePrice(string $id): Price
            {
                throw InvalidRequestException::factory("No such price: '{$id}'", 404);
            }
        };
        $this->app->instance(StripeGateway::class, $failing);

        $this->postJson("/api/admin/plans/{$this->personalPro->id}/stripe-sync")
            ->assertStatus(502)
            ->assertJsonPath('code', 'stripe_error');
    }
}
