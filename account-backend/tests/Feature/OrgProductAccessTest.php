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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrgProductAccessTest extends TestCase
{
    use RefreshDatabase;

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

        $this->intel = Product::factory()->create(['slug' => 're-intel', 'name' => 'RE Intel']);
        $this->roleplay = Product::factory()->create(['slug' => 'roleplay', 'name' => 'Roleplay']);

        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->member = User::factory()->create();

        $this->org = Organization::factory()->create(['created_by' => $this->owner->id]);
        foreach (['owner' => $this->owner, 'admin' => $this->admin, 'member' => $this->member] as $role => $user) {
            OrgMember::create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);
        }

        // Intel: 3 seats. Roleplay: unlimited.
        $this->subscribe($this->intel, 3);
        $this->subscribe($this->roleplay, null);
    }

    private function subscribe(Product $product, ?int $seats, ?Organization $org = null): Subscription
    {
        $plan = Plan::factory()->for($product)->organization()->create();
        $org ??= $this->org;

        return Subscription::factory()->create([
            'subscribable_type' => Organization::class,
            'subscribable_id' => $org->id,
            'plan_id' => $plan->id,
            'product_id' => $product->id,
            'quantity' => $seats,
        ]);
    }

    private function assign(User $user, Product $product): void
    {
        OrgMemberProduct::firstOrCreate(['org_id' => $this->org->id, 'user_id' => $user->id, 'product_id' => $product->id]);
    }

    private function pendingInvite(string $email, array $products, ?\DateTimeInterface $expires = null): OrgInvite
    {
        $invite = OrgInvite::create([
            'org_id' => $this->org->id, 'email' => $email, 'role' => 'member',
            'token' => hash('sha256', Str::random()), 'invited_by' => $this->owner->id,
            'expires_at' => $expires ?? now()->addDay(),
        ]);
        foreach ($products as $product) {
            $invite->products()->attach($product->id, ['id' => (string) Str::uuid()]);
        }

        return $invite;
    }

    private function seatsFor(Product $product): array
    {
        $row = collect($this->getJson("/api/orgs/{$this->org->slug}/seats")->assertOk()->json('seats'))
            ->firstWhere('product.slug', $product->slug);

        return [$row['used'], $row['limit']];
    }

    public function test_first_org_subscription_assigns_the_owner(): void
    {
        $this->assertDatabaseHas('org_member_products', ['org_id' => $this->org->id, 'user_id' => $this->owner->id, 'product_id' => $this->intel->id]);
        $this->assertDatabaseHas('org_member_products', ['org_id' => $this->org->id, 'user_id' => $this->owner->id, 'product_id' => $this->roleplay->id]);
        $this->assertSame(2, OrgMemberProduct::count());
    }

    public function test_members_list_includes_products(): void
    {
        $this->assign($this->member, $this->intel);
        Sanctum::actingAs($this->member);

        $members = collect($this->getJson("/api/orgs/{$this->org->slug}/members")->assertOk()->json('members'));

        $this->assertSame(['RE Intel'], collect($members->firstWhere('user_id', $this->member->id)['products'])->pluck('name')->all());
        $this->assertSame(['RE Intel', 'Roleplay'], collect($members->firstWhere('user_id', $this->owner->id)['products'])->pluck('name')->all());
        $this->assertSame([], $members->firstWhere('user_id', $this->admin->id)['products']);
    }

    public function test_owner_and_admin_assign_products_members_cannot(): void
    {
        $url = "/api/orgs/{$this->org->slug}/members/{$this->member->id}/products";

        Sanctum::actingAs($this->member);
        $this->putJson($url, ['product_ids' => [$this->intel->id]])->assertStatus(403);

        Sanctum::actingAs($this->admin);
        $this->putJson($url, ['product_ids' => [$this->intel->id, $this->roleplay->id]])
            ->assertOk()
            ->assertJsonCount(2, 'member.products')
            ->assertJsonPath('seats.0.product.slug', 're-intel')
            ->assertJsonPath('seats.0.used', 2);
        $this->assertDatabaseHas('org_member_products', ['user_id' => $this->member->id, 'product_id' => $this->intel->id, 'assigned_by' => $this->admin->id]);

        Sanctum::actingAs($this->owner);
        $this->putJson($url, ['product_ids' => [$this->roleplay->id]])->assertOk()->assertJsonPath('member.products.0.slug', 'roleplay');
        $this->assertDatabaseMissing('org_member_products', ['user_id' => $this->member->id, 'product_id' => $this->intel->id]);

        $this->putJson($url, ['product_ids' => []])->assertOk()->assertJsonCount(0, 'member.products');
    }

    public function test_cannot_assign_a_product_the_org_does_not_subscribe_to(): void
    {
        $other = Product::factory()->create(['slug' => 'other']);
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/orgs/{$this->org->slug}/members/{$this->member->id}/products", ['product_ids' => [$other->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_ids');
    }

    public function test_seats_are_counted_per_product(): void
    {
        $this->assign($this->member, $this->roleplay);
        $this->pendingInvite('a@example.com', [$this->intel]);
        $this->pendingInvite('b@example.com', [$this->roleplay]);
        $this->pendingInvite('old@example.com', [$this->intel], now()->subDay()); // expired: no seat
        Sanctum::actingAs($this->member);

        $this->assertSame([2, 3], $this->seatsFor($this->intel));     // owner + invite a
        $this->assertSame([3, null], $this->seatsFor($this->roleplay)); // owner + member + invite b

        $this->getJson("/api/orgs/{$this->org->slug}/subscription?product=re-intel")
            ->assertJsonPath('seats_used', 2)->assertJsonPath('seat_limit', 3);
    }

    public function test_assigning_beyond_the_limit_is_rejected_and_names_the_product(): void
    {
        $this->assign($this->admin, $this->intel);
        $this->pendingInvite('a@example.com', [$this->intel]); // 3/3
        Sanctum::actingAs($this->owner);
        $url = "/api/orgs/{$this->org->slug}/members/{$this->member->id}/products";

        $this->putJson($url, ['product_ids' => [$this->intel->id, $this->roleplay->id]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'seat_limit_reached')
            ->assertJsonPath('product.slug', 're-intel')
            ->assertJsonPath('seats_used', 3)
            ->assertJsonPath('seat_limit', 3)
            ->assertJsonFragment(['message' => 'RE Intel has no seats left (3/3). Upgrade the plan or free a seat first.']);
        // All-or-nothing: Roleplay wasn't granted either.
        $this->assertSame(0, OrgMemberProduct::where('user_id', $this->member->id)->count());

        // Unassigning frees a seat.
        $this->putJson("/api/orgs/{$this->org->slug}/members/{$this->admin->id}/products", ['product_ids' => []])->assertOk();
        $this->putJson($url, ['product_ids' => [$this->intel->id]])->assertOk();
        $this->assertSame([3, 3], $this->seatsFor($this->intel));
    }

    public function test_invite_with_products_is_seat_checked_and_granted_on_accept(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/orgs/{$this->org->slug}/invites", [
            'email' => 'new@example.com', 'role' => 'member', 'product_ids' => [$this->intel->id, $this->roleplay->id],
        ])->assertCreated()->assertJsonCount(2, 'invite.products');

        $this->getJson("/api/orgs/{$this->org->slug}/invites")->assertOk()->assertJsonCount(2, 'invites.0.products');
        $this->assertSame([2, 3], $this->seatsFor($this->intel));

        $this->postJson("/api/orgs/{$this->org->slug}/invites", [
            'email' => 'two@example.com', 'role' => 'member', 'product_ids' => [$this->intel->id],
        ])->assertCreated();

        // Intel full (owner + 2 invites): a third Intel invite fails, a Roleplay-only one works.
        $this->postJson("/api/orgs/{$this->org->slug}/invites", [
            'email' => 'three@example.com', 'role' => 'member', 'product_ids' => [$this->intel->id],
        ])->assertStatus(422)->assertJsonPath('code', 'seat_limit_reached')->assertJsonPath('product.name', 'RE Intel');
        $this->postJson("/api/orgs/{$this->org->slug}/invites", [
            'email' => 'three@example.com', 'role' => 'member', 'product_ids' => [$this->roleplay->id],
        ])->assertCreated();

        // Accept: membership + both product seats.
        $invite = OrgInvite::where('email', 'new@example.com')->first();
        $plain = 'plain-token';
        $invite->forceFill(['token' => hash('sha256', $plain)])->save();
        $newUser = User::factory()->create(['email' => 'new@example.com']);
        Sanctum::actingAs($newUser);

        $this->getJson("/api/invites/{$plain}")->assertOk()->assertJsonCount(2, 'invite.products');
        $this->postJson("/api/invites/{$plain}/accept")->assertOk();

        $this->assertDatabaseHas('org_members', ['org_id' => $this->org->id, 'user_id' => $newUser->id]);
        $this->assertSame(2, OrgMemberProduct::where('user_id', $newUser->id)->count());
        $this->assertSame([3, 3], $this->seatsFor($this->intel)); // the seat moved from invite to member
    }

    public function test_legacy_product_slug_invite_still_works(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/orgs/{$this->org->slug}/invites", [
            'email' => 'legacy@example.com', 'role' => 'member', 'product_slug' => 're-intel',
        ])->assertCreated()->assertJsonPath('invite.products.0.slug', 're-intel');

        $this->postJson("/api/orgs/{$this->org->slug}/invites", ['email' => 'plain@example.com', 'role' => 'admin'])
            ->assertCreated()->assertJsonCount(0, 'invite.products');
    }

    public function test_members_cannot_manage_invites(): void
    {
        Sanctum::actingAs($this->member);

        $this->postJson("/api/orgs/{$this->org->slug}/invites", ['email' => 'x@example.com', 'role' => 'member'])->assertStatus(403);
        $this->getJson("/api/orgs/{$this->org->slug}/invites")->assertStatus(403);
    }

    public function test_removing_a_member_removes_their_access(): void
    {
        $this->assign($this->member, $this->intel);
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/orgs/{$this->org->slug}/members/{$this->member->id}")->assertOk();

        $this->assertSame(0, OrgMemberProduct::where('user_id', $this->member->id)->count());
        $this->deleteJson("/api/orgs/{$this->org->slug}/members/{$this->owner->id}")->assertForbidden(); // policy: nobody removes the owner
    }

    public function test_backfill_assigns_subscribed_products_to_members_and_pending_invites(): void
    {
        $bare = Organization::factory()->create(['created_by' => $this->owner->id]);
        OrgMember::create(['org_id' => $bare->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        $pending = $this->pendingInvite('p@example.com', []);
        $accepted = $this->pendingInvite('done@example.com', []);
        $accepted->forceFill(['accepted_at' => now()])->save();
        OrgMemberProduct::query()->delete(); // state before the feature existed

        $migration = require database_path('migrations/2026_10_31_000001_create_org_product_access_tables.php');
        $migration->backfill();
        $migration->backfill(); // idempotent

        foreach ([$this->owner, $this->admin, $this->member] as $user) {
            $this->assertSame(2, OrgMemberProduct::where('org_id', $this->org->id)->where('user_id', $user->id)->count());
        }
        $this->assertSame(2, $pending->products()->count());
        $this->assertSame(0, $accepted->products()->count());
        $this->assertSame(0, OrgMemberProduct::where('org_id', $bare->id)->count());
    }

    private function handoffSession(): string
    {
        return $this->getJson('/api/auth/handoff?product=re-intel&redirect='.urlencode('http://localhost:3741/auth/callback'))
            ->assertOk()->json('handoff_session');
    }

    public function test_handoff_in_org_context_requires_product_access(): void
    {
        Sanctum::actingAs($this->member);
        $session = $this->handoffSession();

        $this->postJson('/api/auth/handoff/complete', ['handoff_session' => $session, 'org_id' => $this->org->id])
            ->assertStatus(403)
            ->assertJsonPath('code', 'no_product_access')
            ->assertJsonPath('product.name', 'RE Intel')
            ->assertJsonPath('organization.name', $this->org->name)
            ->assertJsonPath('message', "You don't have access to RE Intel in {$this->org->name}. Ask your organization admin.");

        // Personal context is unaffected.
        $this->postJson('/api/auth/handoff/complete', ['handoff_session' => $session])
            ->assertOk()->assertJsonStructure(['redirect_url']);

        // Once assigned, org context works.
        $this->assign($this->member, $this->intel);
        $this->postJson('/api/auth/handoff/complete', ['handoff_session' => $session, 'org_id' => $this->org->id])
            ->assertOk()->assertJsonStructure(['redirect_url']);
    }

    public function test_login_handoff_in_org_context_without_access_is_refused(): void
    {
        $session = $this->handoffSession();

        $this->withHeaders(['Referer' => 'http://localhost:3742'])->postJson('/api/auth/login', [
            'email' => $this->member->email, 'password' => 'password', 'handoff_session' => $session, 'org_id' => $this->org->id,
        ])->assertStatus(403)->assertJsonPath('code', 'no_product_access');
    }

    public function test_handoff_for_an_org_without_that_product_is_refused(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->member->id]);
        OrgMember::create(['org_id' => $other->id, 'user_id' => $this->member->id, 'role' => 'owner']);
        Sanctum::actingAs($this->member);

        $this->postJson('/api/auth/handoff/complete', ['handoff_session' => $this->handoffSession(), 'org_id' => $other->id])
            ->assertStatus(403)->assertJsonPath('code', 'no_product_access');
    }
}
