<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrgInvite;
use App\Models\OrgMember;
use App\Models\OrgMemberProduct;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Services\TokenService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Product roles, verify-token `is_super_admin` / `product_role`, and the
 * super-admin-only `re-data` product (.project/plans/restructure.md, Agent D contract).
 */
class ProductRolesTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = [
        ['key' => 'manager', 'name' => 'Manager', 'default' => false],
        ['key' => 'agent', 'name' => 'Agent', 'default' => true],
    ];

    private Product $intel;

    private Product $roleplay;

    private User $owner;

    private User $admin;

    private User $member;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->intel = Product::factory()->create([
            'slug' => 're-intel', 'name' => 'RE Intel', 'roles' => self::ROLES, 'secret_key' => Hash::make('intel-secret'),
        ]);
        $this->roleplay = Product::factory()->create(['slug' => 'roleplay', 'name' => 'Roleplay']);

        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->member = User::factory()->create();

        $this->org = Organization::factory()->create(['created_by' => $this->owner->id]);
        foreach (['owner' => $this->owner, 'admin' => $this->admin, 'member' => $this->member] as $role => $user) {
            OrgMember::create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);
        }

        foreach ([$this->intel, $this->roleplay] as $product) {
            Subscription::factory()->create([
                'subscribable_type' => Organization::class,
                'subscribable_id' => $this->org->id,
                'plan_id' => Plan::factory()->for($product)->organization()->create()->id,
                'product_id' => $product->id,
                'quantity' => null,
            ]);
        }
    }

    private function url(User $user): string
    {
        return "/api/orgs/{$this->org->slug}/members/{$user->id}/products";
    }

    private function roleOf(User $user, Product $product): ?string
    {
        return OrgMemberProduct::where(['org_id' => $this->org->id, 'user_id' => $user->id, 'product_id' => $product->id])->value('role');
    }

    public function test_products_normalise_roles_with_exactly_one_default(): void
    {
        $this->assertSame('agent', $this->intel->defaultRoleFor('member'));
        $this->assertSame('manager', $this->intel->defaultRoleFor('admin'));
        $this->assertSame('manager', $this->intel->defaultRoleFor('owner'));
        $this->assertNull($this->roleplay->defaultRoleFor('owner'));
        $this->assertSame([], $this->roleplay->fresh()->roles);

        $p = Product::factory()->create(['roles' => [['key' => 'a', 'name' => 'A'], ['key' => 'b', 'name' => 'B']]]);
        $this->assertSame([true, false], array_column($p->fresh()->roles, 'default'));

        // Without a manager role, owners get the default too.
        $this->assertSame('a', $p->defaultRoleFor('owner'));
    }

    public function test_owner_is_auto_assigned_as_manager_and_members_list_returns_roles(): void
    {
        $this->assertSame('manager', $this->roleOf($this->owner, $this->intel));
        $this->assertNull($this->roleOf($this->owner, $this->roleplay));

        Sanctum::actingAs($this->member);
        $members = collect($this->getJson("/api/orgs/{$this->org->slug}/members")->assertOk()->json('members'));
        $products = collect($members->firstWhere('user_id', $this->owner->id)['products']);

        $this->assertSame('manager', $products->firstWhere('slug', 're-intel')['role']);
        $this->assertNull($products->firstWhere('slug', 'roleplay')['role']);

        // Seats carry the roles so the UI can render the select.
        $seats = collect($this->getJson("/api/orgs/{$this->org->slug}/seats")->assertOk()->json('seats'));
        $this->assertSame(['manager', 'agent'], array_column($seats->firstWhere('product.slug', 're-intel')['product']['roles'], 'key'));
    }

    public function test_assigning_with_plain_ids_uses_the_default_role(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson($this->url($this->member), ['product_ids' => [$this->intel->id, $this->roleplay->id]])
            ->assertOk()
            ->assertJsonPath('member.products.0.slug', 're-intel')
            ->assertJsonPath('member.products.0.role', 'agent')
            ->assertJsonPath('member.products.1.role', null);

        $this->putJson($this->url($this->admin), ['product_ids' => [$this->intel->id]])
            ->assertOk()->assertJsonPath('member.products.0.role', 'manager');
    }

    public function test_assigning_with_explicit_roles_and_keeping_roles(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson($this->url($this->member), ['products' => [['product_id' => $this->intel->id, 'role' => 'manager']]])
            ->assertOk()->assertJsonPath('member.products.0.role', 'manager');
        $this->assertSame('manager', $this->roleOf($this->member, $this->intel));

        // Plain id for an existing assignment keeps its role.
        $this->putJson($this->url($this->member), ['product_ids' => [$this->intel->id, $this->roleplay->id]])->assertOk();
        $this->assertSame('manager', $this->roleOf($this->member, $this->intel));

        // Changing only the role.
        $this->putJson($this->url($this->member), ['products' => [
            ['product_id' => $this->intel->id, 'role' => 'agent'],
            ['product_id' => $this->roleplay->id],
        ]])->assertOk();
        $this->assertSame('agent', $this->roleOf($this->member, $this->intel));
        $this->assertSame(2, OrgMemberProduct::where('user_id', $this->member->id)->count());

        // Objects are also accepted under product_ids.
        $this->putJson($this->url($this->member), ['product_ids' => [['product_id' => $this->intel->id, 'role' => 'manager']]])
            ->assertOk()->assertJsonCount(1, 'member.products');
        $this->assertSame('manager', $this->roleOf($this->member, $this->intel));
    }

    public function test_role_must_belong_to_the_product(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson($this->url($this->member), ['products' => [['product_id' => $this->intel->id, 'role' => 'boss']]])
            ->assertStatus(422)->assertJsonValidationErrors('product_role');
        $this->putJson($this->url($this->member), ['products' => [['product_id' => $this->roleplay->id, 'role' => 'agent']]])
            ->assertStatus(422)->assertJsonValidationErrors('product_role');
        $this->putJson($this->url($this->member), ['product_ids' => ['not-a-uuid']])
            ->assertStatus(422)->assertJsonValidationErrors('product_ids');
        $this->assertSame(0, OrgMemberProduct::where('user_id', $this->member->id)->count());
    }

    public function test_members_cannot_change_product_roles(): void
    {
        Sanctum::actingAs($this->member);

        $this->putJson($this->url($this->member), ['products' => [['product_id' => $this->intel->id, 'role' => 'manager']]])
            ->assertForbidden();
    }

    public function test_invites_carry_product_roles_to_the_membership(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/orgs/{$this->org->slug}/invites", [
            'email' => 'new@example.com', 'role' => 'member',
            'products' => [['product_id' => $this->intel->id, 'role' => 'manager'], ['product_id' => $this->roleplay->id]],
        ])->assertCreated()
            ->assertJsonPath('invite.products.0.slug', 're-intel')
            ->assertJsonPath('invite.products.0.role', 'manager')
            ->assertJsonPath('invite.products.1.role', null);

        // Plain ids: default for the invite's org role.
        $this->postJson("/api/orgs/{$this->org->slug}/invites", [
            'email' => 'boss@example.com', 'role' => 'admin', 'product_ids' => [$this->intel->id],
        ])->assertCreated()->assertJsonPath('invite.products.0.role', 'manager');
        $this->postJson("/api/orgs/{$this->org->slug}/invites", [
            'email' => 'agent@example.com', 'role' => 'member', 'product_ids' => [$this->intel->id],
        ])->assertCreated()->assertJsonPath('invite.products.0.role', 'agent');

        $this->postJson("/api/orgs/{$this->org->slug}/invites", [
            'email' => 'bad@example.com', 'role' => 'member', 'products' => [['product_id' => $this->intel->id, 'role' => 'boss']],
        ])->assertStatus(422)->assertJsonValidationErrors('product_role');

        $invites = collect($this->getJson("/api/orgs/{$this->org->slug}/invites")->assertOk()->json('invites'));
        $this->assertSame('manager', collect($invites->firstWhere('email', 'new@example.com')['products'])->firstWhere('slug', 're-intel')['role']);

        $invite = OrgInvite::where('email', 'new@example.com')->first();
        $invite->forceFill(['token' => hash('sha256', 'plain-token')])->save();
        $newUser = User::factory()->create(['email' => 'new@example.com']);
        Sanctum::actingAs($newUser);

        $this->getJson('/api/invites/plain-token')->assertOk()->assertJsonPath('invite.products.0.role', 'manager');
        $this->postJson('/api/invites/plain-token/accept')->assertOk();

        $this->assertSame('manager', OrgMemberProduct::where(['user_id' => $newUser->id, 'product_id' => $this->intel->id])->value('role'));
        $this->assertNull(OrgMemberProduct::where(['user_id' => $newUser->id, 'product_id' => $this->roleplay->id])->value('role'));
    }

    public function test_accepting_an_invite_without_a_stored_role_uses_the_default(): void
    {
        $invite = OrgInvite::create([
            'org_id' => $this->org->id, 'email' => 'legacy@example.com', 'role' => 'admin',
            'token' => hash('sha256', 'legacy'), 'invited_by' => $this->owner->id, 'expires_at' => now()->addDay(),
        ]);
        $invite->products()->attach($this->intel->id, ['id' => (string) Str::uuid()]);
        $user = User::factory()->create(['email' => 'legacy@example.com']);
        Sanctum::actingAs($user);

        $this->postJson('/api/invites/legacy/accept')->assertOk();
        $this->assertSame('manager', OrgMemberProduct::where(['user_id' => $user->id, 'product_id' => $this->intel->id])->value('role'));
    }

    public function test_verify_token_returns_is_super_admin_and_product_role(): void
    {
        OrgMemberProduct::create(['org_id' => $this->org->id, 'user_id' => $this->member->id, 'product_id' => $this->intel->id, 'role' => 'agent']);
        $tokens = app(TokenService::class);
        $membership = OrgMember::with('organization')->where(['org_id' => $this->org->id, 'user_id' => $this->member->id])->first();
        $verify = fn (string $token) => $this->postJson('/api/verify-token', ['token' => $token], ['X-Product-Secret' => 'intel-secret'])->assertOk();

        $verify($tokens->issueHandoffToken($this->member, 're-intel', $membership))
            ->assertJsonPath('is_super_admin', false)
            ->assertJsonPath('product_role', 'agent')
            ->assertJsonPath('org_role', 'member');

        $ownerMembership = OrgMember::with('organization')->where(['org_id' => $this->org->id, 'user_id' => $this->owner->id])->first();
        $verify($tokens->issueHandoffToken($this->owner, 're-intel', $ownerMembership))->assertJsonPath('product_role', 'manager');

        // Personal context: null.
        $verify($tokens->issueHandoffToken($this->member, 're-intel'))
            ->assertJsonPath('context', 'personal')
            ->assertJsonPath('product_role', null);

        $this->member->forceFill(['is_super_admin' => true])->save();
        $verify($tokens->issueHandoffToken($this->member, 're-intel'))->assertJsonPath('is_super_admin', true);
    }

    private function reData(): Product
    {
        return Product::factory()->create([
            'slug' => 're-data', 'name' => 'Data Admin', 'redirect_url' => 'http://localhost:3743/auth/callback',
            'secret_key' => Hash::make('data-secret'), 'super_admin_only' => true,
        ]);
    }

    private function reDataSession(): string
    {
        return $this->getJson('/api/auth/handoff?product=re-data&redirect='.urlencode('http://localhost:3743/auth/callback'))
            ->assertOk()
            ->assertJsonPath('product.super_admin_only', true)
            ->json('handoff_session');
    }

    public function test_re_data_handoff_is_refused_for_non_super_admins(): void
    {
        $this->reData();
        $session = $this->reDataSession();
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/auth/handoff/complete', ['handoff_session' => $session])
            ->assertStatus(403)
            ->assertJsonPath('code', 'not_super_admin')
            ->assertJsonPath('product.name', 'Data Admin');
        $this->postJson('/api/auth/handoff/complete', ['handoff_session' => $session, 'org_id' => $this->org->id])
            ->assertStatus(403)->assertJsonPath('code', 'not_super_admin');
    }

    public function test_re_data_login_and_register_are_refused_for_non_super_admins(): void
    {
        $this->reData();
        $session = $this->reDataSession();

        $this->withHeaders(['Referer' => 'http://localhost:3742'])->postJson('/api/auth/login', [
            'email' => $this->member->email, 'password' => 'password', 'handoff_session' => $session,
        ])->assertStatus(403)->assertJsonPath('code', 'not_super_admin');

        $this->withHeaders(['Referer' => 'http://localhost:3742'])->postJson('/api/auth/register', [
            'name' => 'New', 'email' => 'nobody@example.com', 'password' => 'password123',
            'password_confirmation' => 'password123', 'handoff_session' => $session,
        ])->assertStatus(403)->assertJsonPath('code', 'not_super_admin');
        $this->assertDatabaseMissing('users', ['email' => 'nobody@example.com']);
    }

    public function test_super_admins_sign_in_to_re_data_without_plans_or_seats(): void
    {
        $this->reData();
        $this->owner->forceFill(['is_super_admin' => true])->save();
        $session = $this->reDataSession();
        Sanctum::actingAs($this->owner->fresh());

        $url = $this->postJson('/api/auth/handoff/complete', ['handoff_session' => $session])->assertOk()->json('redirect_url');
        $this->assertStringStartsWith('http://localhost:3743/auth/callback?token=', $url);

        // Org context: no subscription/seat for re-data, still allowed.
        $orgUrl = $this->postJson('/api/auth/handoff/complete', ['handoff_session' => $session, 'org_id' => $this->org->id])
            ->assertOk()->json('redirect_url');
        parse_str((string) parse_url($orgUrl, PHP_URL_QUERY), $query);

        $this->postJson('/api/verify-token', ['token' => $query['token']], ['X-Product-Secret' => 'data-secret'])
            ->assertOk()
            ->assertJsonPath('product', 're-data')
            ->assertJsonPath('is_super_admin', true)
            ->assertJsonPath('product_role', null)
            ->assertJsonPath('plan', null);
    }

    public function test_admin_edits_product_roles_and_removed_roles_fall_back_to_default(): void
    {
        OrgMemberProduct::create(['org_id' => $this->org->id, 'user_id' => $this->member->id, 'product_id' => $this->intel->id, 'role' => 'agent']);
        $super = User::factory()->create(['is_super_admin' => true]);
        Sanctum::actingAs($super);

        $this->getJson('/api/admin/products')->assertOk()->assertJsonPath('products.0.roles.0.key', 'manager');

        $this->putJson("/api/admin/products/{$this->intel->id}", ['roles' => [
            ['key' => 'manager', 'name' => 'Manager'],
            ['key' => 'viewer', 'name' => 'Viewer', 'default' => true],
        ]])->assertOk()
            ->assertJsonPath('product.roles.1.key', 'viewer')
            ->assertJsonPath('product.roles.1.default', true)
            ->assertJsonPath('product.roles.0.default', false);

        $this->assertSame('viewer', $this->roleOf($this->member, $this->intel));
        $this->assertSame('manager', $this->roleOf($this->owner, $this->intel));

        $this->putJson("/api/admin/products/{$this->intel->id}", ['roles' => [
            ['key' => 'dup', 'name' => 'A'], ['key' => 'dup', 'name' => 'B'],
        ]])->assertStatus(422)->assertJsonValidationErrors('roles.0.key');
        $this->putJson("/api/admin/products/{$this->intel->id}", ['roles' => [['key' => 'Bad Key', 'name' => 'A']]])
            ->assertStatus(422)->assertJsonValidationErrors('roles.0.key');

        // Clearing the list nulls the roles.
        $this->putJson("/api/admin/products/{$this->intel->id}", ['roles' => []])->assertOk()->assertJsonPath('product.roles', []);
        $this->assertNull($this->roleOf($this->owner, $this->intel));

        $this->putJson("/api/admin/products/{$this->roleplay->id}", ['super_admin_only' => true])
            ->assertOk()->assertJsonPath('product.super_admin_only', true);

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/admin/products/{$this->intel->id}", ['roles' => []])->assertForbidden();
    }

    public function test_backfill_gives_owners_and_admins_manager_and_members_agent(): void
    {
        foreach ([$this->admin, $this->member] as $user) {
            OrgMemberProduct::create(['org_id' => $this->org->id, 'user_id' => $user->id, 'product_id' => $this->intel->id]);
        }
        OrgMemberProduct::where('org_id', $this->org->id)->update(['role' => null]);
        $invite = OrgInvite::create([
            'org_id' => $this->org->id, 'email' => 'p@example.com', 'role' => 'member',
            'token' => hash('sha256', 'p'), 'invited_by' => $this->owner->id, 'expires_at' => now()->addDay(),
        ]);
        $invite->products()->attach($this->intel->id, ['id' => (string) Str::uuid()]);

        $migration = require database_path('migrations/2026_11_02_000004_add_product_roles.php');
        $migration->backfill();
        $migration->backfill(); // idempotent

        $this->assertSame('manager', $this->roleOf($this->owner, $this->intel));
        $this->assertSame('manager', $this->roleOf($this->admin, $this->intel));
        $this->assertSame('agent', $this->roleOf($this->member, $this->intel));
        $this->assertNull($this->roleOf($this->owner, $this->roleplay));
        $this->assertSame('agent', DB::table('org_invite_products')->where('invite_id', $invite->id)->value('role'));
    }

    public function test_seeder_is_idempotent_and_registers_re_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $intel = Product::where('slug', 're-intel')->sole();
        $data = Product::where('slug', 're-data')->sole();

        $this->assertSame(['manager', 'agent'], $intel->roleKeys());
        $this->assertSame('agent', $intel->defaultRoleFor('member'));
        $this->assertTrue($data->super_admin_only);
        $this->assertSame('Data Admin', $data->name);
        $this->assertSame('http://localhost:3743/auth/callback', $data->redirect_url);
        $this->assertTrue(Hash::check('re-data-dev-secret-key-change-me', $data->secret_key));
        $this->assertSame(0, $data->plans()->count());
    }
}
