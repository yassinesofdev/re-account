<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * verify-token entitlement, admin plan linking/deletion, org deletion.
 */
class AdminAndLifecycleTest extends BillingTestCase
{
    public function test_verify_token_returns_entitled_plan_with_status(): void
    {
        $this->product->forceFill(['secret_key' => Hash::make('the-secret')])->save();
        $user = User::factory()->create();
        $sub = $this->subscribePaid($user, $this->personalPro, 'sub_1', ['status' => 'past_due']);
        $token = app(TokenService::class)->issueHandoffToken($user, 're-intel');

        $this->postJson('/api/verify-token', ['token' => $token], ['X-Product-Secret' => 'the-secret'])
            ->assertOk()
            ->assertJsonPath('plan.slug', 'personal-pro')
            ->assertJsonPath('plan.status', 'past_due')
            ->assertJsonPath('plan.current_period_end', $sub->current_period_end->toJSON());

        $sub->forceFill(['status' => 'incomplete'])->save();
        $token = app(TokenService::class)->issueHandoffToken($user, 're-intel'); // tokens are single-use

        $this->postJson('/api/verify-token', ['token' => $token], ['X-Product-Secret' => 'the-secret'])
            ->assertOk()
            ->assertJsonPath('plan', null);
    }

    public function test_admin_links_price_and_validates_period(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));
        $base = [
            'product_id' => $this->product->id, 'name' => 'Plus', 'scope' => 'personal', 'price_cents' => 500,
        ];

        $this->postJson('/api/admin/plans', [...$base, 'slug' => 'plus', 'stripe_price_id' => 'price_plus'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('billing_period');

        $this->postJson('/api/admin/plans', [...$base, 'slug' => 'plus', 'stripe_price_id' => 'not_a_price', 'billing_period' => 'month'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stripe_price_id');

        $this->postJson('/api/admin/plans', [...$base, 'slug' => 'plus', 'stripe_price_id' => 'price_plus', 'stripe_product_id' => 'prod_plus', 'billing_period' => 'month'])
            ->assertCreated()
            ->assertJsonPath('plan.stripe_price_id', 'price_plus')
            ->assertJsonPath('plan.stripe_product_id', 'prod_plus')
            ->assertJsonPath('plan.is_purchasable', true);

        // The manual link is verified against Stripe (the fake's stub has no amount → out_of_sync).
        $this->assertSame([['price_plus']], $this->stripe->callsTo('retrievePrice'));
    }

    public function test_admin_cannot_delete_plan_with_paid_subscribers(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));
        $this->subscribePaid(User::factory()->create(), $this->personalPro, 'sub_1');
        $this->subscribe(User::factory()->create(), $this->personalFree);

        $this->deleteJson("/api/admin/plans/{$this->personalPro->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'plan_has_paid_subscribers');

        $this->deleteJson("/api/admin/plans/{$this->personalFree->id}")->assertOk();
        $this->assertDatabaseMissing('plans', ['id' => $this->personalFree->id]);
    }

    public function test_org_deletion_cancels_stripe_subscription(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgWith($owner);
        $this->subscribePaid($org, $this->teamPro, 'sub_org');
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/orgs/{$org->slug}")->assertOk();

        $this->assertSame([['sub_org']], $this->stripe->callsTo('cancelNow'));
    }

    public function test_sync_stripe_plans_command_links_unlinked_paid_plans(): void
    {
        $this->personalPro->forceFill(['stripe_price_id' => null, 'stripe_product_id' => null])->save();

        $this->artisan('billing:sync-stripe-plans', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($this->personalPro->fresh()->stripe_price_id);

        $this->artisan('billing:sync-stripe-plans')->assertSuccessful();

        [$name, $metadata, $amount, $currency, $interval] = $this->stripe->callsTo('createProductAndPrice')[0];
        $this->assertSame('RE Intel Pro', $name);
        $this->assertSame(['plan_id' => $this->personalPro->id], $metadata);
        $this->assertSame([1200, 'usd', 'month'], [$amount, $currency, $interval]);
        $this->assertStringStartsWith('price_fake_', $this->personalPro->fresh()->stripe_price_id);
    }
}
