<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\OrgMember;
use App\Models\OrgMemberProduct;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Services\TokenService;
use App\Support\TenantRedirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tenant sub-domains: handoff redirects on <org-slug>.<domain> (product
 * tenant_redirect_url template) bind the sign-in to that org; products look
 * tenants up with GET product/orgs/{slug}.
 */
class TenantHandoffTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://app.re.localsite:3741/intelligence/auth/callback';

    private const TEMPLATE = 'http://{tenant}.re.localsite:3741/intelligence/auth/callback';

    private Product $intel;

    private Organization $acme;

    private Organization $other;

    private User $member;

    private User $seatless;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->intel = Product::factory()->create([
            'slug' => 're-intel', 'name' => 'RE Intel', 'is_active' => true,
            'redirect_url' => self::BASE, 'tenant_redirect_url' => self::TEMPLATE,
            'secret_key' => Hash::make('intel-secret'),
        ]);

        $owner = User::factory()->create();
        $this->member = User::factory()->create(['password' => Hash::make('password')]);
        $this->seatless = User::factory()->create(['password' => Hash::make('password')]);
        $this->outsider = User::factory()->create(['password' => Hash::make('password')]);

        $this->acme = Organization::factory()->create(['name' => 'Acme', 'slug' => 'acme', 'created_by' => $owner->id, 'brand_color' => '#112233']);
        $this->other = Organization::factory()->create(['name' => 'Other', 'slug' => 'other-co', 'created_by' => $owner->id]);

        OrgMember::create(['org_id' => $this->acme->id, 'user_id' => $owner->id, 'role' => 'owner', 'joined_at' => now()]);
        OrgMember::create(['org_id' => $this->acme->id, 'user_id' => $this->member->id, 'role' => 'member', 'joined_at' => now()]);
        OrgMember::create(['org_id' => $this->acme->id, 'user_id' => $this->seatless->id, 'role' => 'member', 'joined_at' => now()]);
        // The outsider is in another org only.
        OrgMember::create(['org_id' => $this->other->id, 'user_id' => $this->outsider->id, 'role' => 'member', 'joined_at' => now()]);

        $plan = Plan::factory()->for($this->intel)->organization()->create();
        Subscription::factory()->create([
            'subscribable_type' => Organization::class, 'subscribable_id' => $this->acme->id,
            'plan_id' => $plan->id, 'product_id' => $this->intel->id, 'quantity' => 5,
        ]);
        OrgMemberProduct::firstOrCreate(['org_id' => $this->acme->id, 'user_id' => $this->member->id, 'product_id' => $this->intel->id]);
    }

    private function handoff(string $redirect)
    {
        return $this->getJson('/api/auth/handoff?'.http_build_query(['product' => 're-intel', 'redirect' => $redirect]));
    }

    private function tenantSession(string $slug = 'acme'): string
    {
        return $this->handoff(str_replace('{tenant}', $slug, self::TEMPLATE))->assertOk()->json('handoff_session');
    }

    private function tokenFrom(string $redirectUrl): array
    {
        parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $q);

        return app(TokenService::class)->decode($q['token']);
    }

    // --- Template matching -----------------------------------------------------

    public function test_template_matching(): void
    {
        $this->assertTrue(TenantRedirect::isValidTemplate(self::TEMPLATE));
        $this->assertFalse(TenantRedirect::isValidTemplate('http://app.re.localsite/{tenant}/cb'));
        $this->assertFalse(TenantRedirect::isValidTemplate('http://{tenant}.{tenant}.x/cb'));

        $this->assertSame('acme', TenantRedirect::slugFrom(self::TEMPLATE, 'http://acme.re.localsite:3741/intelligence/auth/callback'));
        $this->assertSame('acme', TenantRedirect::slugFrom(self::TEMPLATE, 'http://acme.re.localsite:3741/intelligence/auth/callback?x=1'));
        $this->assertNull(TenantRedirect::slugFrom(self::TEMPLATE, 'http://acme.evil.com/.re.localsite:3741/intelligence/auth/callback'));
        $this->assertNull(TenantRedirect::slugFrom(self::TEMPLATE, 'http://a.b.re.localsite:3741/intelligence/auth/callback'));
        $this->assertNull(TenantRedirect::slugFrom(self::TEMPLATE, 'http://acme.re.localsite:3741/intelligence/auth/callbackevil'));
        $this->assertNull(TenantRedirect::slugFrom(self::TEMPLATE, 'http://acme.re.localsite:9999/intelligence/auth/callback'));
        $this->assertNull(TenantRedirect::slugFrom(self::TEMPLATE, 'http://ACME.re.localsite:3741/intelligence/auth/callback'));
    }

    // --- GET auth/handoff --------------------------------------------------------

    public function test_base_redirect_is_not_tenant_bound(): void
    {
        $this->handoff(self::BASE)->assertOk()->assertJsonPath('tenant', null);
    }

    public function test_tenant_redirect_returns_the_org(): void
    {
        $this->handoff('http://acme.re.localsite:3741/intelligence/auth/callback')
            ->assertOk()
            ->assertJsonPath('tenant.slug', 'acme')
            ->assertJsonPath('tenant.name', 'Acme')
            ->assertJsonPath('tenant.brand_color', '#112233');
    }

    public function test_unknown_or_reserved_tenant_redirect_is_rejected(): void
    {
        $this->handoff('http://nope-unknown.re.localsite:3741/intelligence/auth/callback')->assertStatus(422);

        // Even if an org somehow holds a reserved slug, it's never a tenant host.
        Organization::factory()->create(['slug' => 'support', 'created_by' => $this->member->id]);
        $this->handoff('http://support.re.localsite:3741/intelligence/auth/callback')->assertStatus(422);
    }

    public function test_product_without_template_rejects_tenant_redirects(): void
    {
        $this->intel->forceFill(['tenant_redirect_url' => null])->save();

        $this->handoff('http://acme.re.localsite:3741/intelligence/auth/callback')->assertStatus(422);
    }

    // --- Completing a tenant-bound sign-in -----------------------------------------

    public function test_member_with_a_seat_gets_an_org_token_whatever_org_id_is_posted(): void
    {
        Sanctum::actingAs($this->member);

        // No org_id (would be personal on the base host) → still Acme.
        $res = $this->postJson('/api/auth/handoff/complete', ['handoff_session' => $this->tenantSession()])->assertOk();
        $payload = $this->tokenFrom($res->json('redirect_url'));

        $this->assertStringStartsWith('http://acme.re.localsite:3741/intelligence/auth/callback?token=', $res->json('redirect_url'));
        $this->assertSame('org', $payload['context']);
        $this->assertSame($this->acme->id, $payload['org_id']);
        $this->assertSame('acme', $payload['org_slug']);

        // Another org's id is ignored too.
        $res = $this->postJson('/api/auth/handoff/complete', ['handoff_session' => $this->tenantSession(), 'org_id' => $this->other->id])->assertOk();
        $this->assertSame($this->acme->id, $this->tokenFrom($res->json('redirect_url'))['org_id']);
    }

    public function test_non_member_gets_not_org_member(): void
    {
        Sanctum::actingAs($this->outsider);

        $this->postJson('/api/auth/handoff/complete', ['handoff_session' => $this->tenantSession()])
            ->assertForbidden()
            ->assertJsonPath('code', 'not_org_member')
            ->assertJsonPath('organization.slug', 'acme')
            ->assertJsonPath('message', "You're not a member of Acme.");
    }

    public function test_member_without_a_seat_gets_no_product_access(): void
    {
        Sanctum::actingAs($this->seatless);

        $this->postJson('/api/auth/handoff/complete', ['handoff_session' => $this->tenantSession()])
            ->assertForbidden()
            ->assertJsonPath('code', 'no_product_access')
            ->assertJsonPath('organization.slug', 'acme');
    }

    public function test_login_is_bound_too(): void
    {
        $this->withHeaders(['Referer' => 'http://localhost:3742'])->postJson('/api/auth/login', ['email' => $this->outsider->email, 'password' => 'password', 'handoff_session' => $this->tenantSession()])
            ->assertForbidden()
            ->assertJsonPath('code', 'not_org_member');

        $res = $this->withHeaders(['Referer' => 'http://localhost:3742'])->postJson('/api/auth/login', ['email' => $this->member->email, 'password' => 'password', 'handoff_session' => $this->tenantSession()])
            ->assertOk();
        $this->assertSame($this->acme->id, $this->tokenFrom($res->json('redirect_url'))['org_id']);
    }

    public function test_register_is_refused_before_creating_the_user(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'New', 'email' => 'new@example.com', 'password' => 'password1', 'password_confirmation' => 'password1',
            'handoff_session' => $this->tenantSession(),
        ])->assertForbidden()->assertJsonPath('code', 'not_org_member');

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_base_host_org_sign_in_carries_the_org_slug(): void
    {
        Sanctum::actingAs($this->member);
        $session = $this->handoff(self::BASE)->json('handoff_session');

        $res = $this->postJson('/api/auth/handoff/complete', ['handoff_session' => $session, 'org_id' => $this->acme->id])->assertOk();
        $this->assertSame('acme', $this->tokenFrom($res->json('redirect_url'))['org_slug']);

        // Personal context still works on the base host.
        $res = $this->postJson('/api/auth/handoff/complete', ['handoff_session' => $session])->assertOk();
        $this->assertSame('personal', $this->tokenFrom($res->json('redirect_url'))['context']);
    }

    // --- GET product/orgs/{slug} ---------------------------------------------------

    private function lookup(string $slug, string $secret = 'intel-secret')
    {
        return $this->getJson("/api/product/orgs/{$slug}?product=re-intel", ['X-Product-Secret' => $secret]);
    }

    public function test_lookup_returns_public_branding(): void
    {
        $this->lookup('acme')->assertOk()->assertExactJson([
            'id' => $this->acme->id, 'name' => 'Acme', 'slug' => 'acme',
            'logo_url' => $this->acme->logo_url, 'brand_color' => '#112233',
        ]);
    }

    public function test_lookup_404s_for_unknown_and_reserved(): void
    {
        $this->lookup('nope-unknown')->assertNotFound()->assertJsonPath('code', 'org_not_found');
        $this->lookup('support')->assertNotFound()->assertJsonPath('code', 'reserved');
        $this->lookup('not_dns')->assertNotFound()->assertJsonPath('code', 'org_not_found');
    }

    public function test_lookup_needs_the_product_secret(): void
    {
        $this->lookup('acme', 'wrong')->assertUnauthorized();
        $this->getJson('/api/product/orgs/acme?product=re-intel')->assertUnauthorized();
    }

    // --- Admin + user-status -------------------------------------------------------------

    public function test_admin_validates_the_template(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

        $this->putJson("/api/admin/products/{$this->intel->id}", ['tenant_redirect_url' => 'https://app.example.com/cb'])
            ->assertStatus(422)->assertJsonValidationErrors('tenant_redirect_url');
        $this->putJson("/api/admin/products/{$this->intel->id}", ['tenant_redirect_url' => 'https://{tenant}.example.com/intelligence/auth/callback'])
            ->assertOk()->assertJsonPath('product.tenant_redirect_url', 'https://{tenant}.example.com/intelligence/auth/callback');
        $this->putJson("/api/admin/products/{$this->intel->id}", ['tenant_redirect_url' => null])
            ->assertOk()->assertJsonPath('product.tenant_redirect_url', null);
    }

    public function test_user_status_returns_the_org_slug_for_members(): void
    {
        $this->postJson('/api/product/user-status', ['product' => 're-intel', 'user_id' => $this->member->id, 'org_id' => $this->acme->id], ['X-Product-Secret' => 'intel-secret'])
            ->assertOk()->assertJsonPath('org', ['id' => $this->acme->id, 'slug' => 'acme']);
        $this->postJson('/api/product/user-status', ['product' => 're-intel', 'user_id' => $this->outsider->id, 'org_id' => $this->acme->id], ['X-Product-Secret' => 'intel-secret'])
            ->assertOk()->assertJsonPath('org', null);
    }
}
