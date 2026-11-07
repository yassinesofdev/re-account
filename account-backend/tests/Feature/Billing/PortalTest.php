<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Laravel\Sanctum\Sanctum;

class PortalTest extends BillingTestCase
{
    public function test_profile_portal_returns_url(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['stripe_customer_id' => 'cus_1'])->save();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/billing/portal')
            ->assertOk()
            ->assertJsonPath('url', 'https://billing.stripe.com/p/session/test_cus_1');

        $this->assertSame([['cus_1', 'http://localhost:3742/profile']], $this->stripe->callsTo('createPortalSession'));
    }

    public function test_profile_portal_without_customer_is_409(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/profile/billing/portal')
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_billing_account');
    }

    public function test_org_portal_owner_only(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $org = $this->orgWith($owner, ['admin' => $admin]);
        $org->forceFill(['stripe_customer_id' => 'cus_org'])->save();

        Sanctum::actingAs($admin);
        $this->postJson("/api/orgs/{$org->slug}/billing/portal")->assertForbidden();
        $this->getJson("/api/orgs/{$org->slug}/subscription?product=re-intel")
            ->assertOk()
            ->assertJsonPath('billing_portal_available', false);

        Sanctum::actingAs($owner);
        $this->postJson("/api/orgs/{$org->slug}/billing/portal")
            ->assertOk()
            ->assertJsonPath('url', 'https://billing.stripe.com/p/session/test_cus_org');
        $this->getJson("/api/orgs/{$org->slug}/subscription?product=re-intel")
            ->assertOk()
            ->assertJsonPath('billing_portal_available', true);
    }

    public function test_portal_without_secret_is_503(): void
    {
        config(['services.stripe.secret' => '']);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/profile/billing/portal')
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_unavailable');
    }
}
