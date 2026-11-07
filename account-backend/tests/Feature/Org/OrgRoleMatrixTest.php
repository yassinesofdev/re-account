<?php

namespace Tests\Feature\Org;

use App\Models\Organization;
use App\Models\OrgInvite;
use App\Models\OrgMemberProduct;
use App\Models\User;
use App\Policies\OrganizationPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Billing\BillingTestCase;

/**
 * Owner / admin / member / outsider × every org endpoint, against
 * OrganizationPolicy::MATRIX, plus the edge cases around the owner.
 */
class OrgRoleMatrixTest extends BillingTestCase
{
    private User $owner;

    private User $admin;

    private User $member;

    /** A second plain member: the target of member-management actions. */
    private User $other;

    private User $outsider;

    private Organization $org;

    private OrgInvite $invite;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('public');

        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->member = User::factory()->create();
        $this->other = User::factory()->create();
        $this->outsider = User::factory()->create();

        $this->org = $this->orgWith($this->owner, ['admin' => $this->admin, 'member' => [$this->member, $this->other]]);
        $this->org->forceFill(['stripe_customer_id' => 'cus_org'])->save();
        $this->subscribe($this->org, $this->teamFree);

        $this->invite = OrgInvite::create([
            'org_id' => $this->org->id, 'email' => 'pending@example.com', 'role' => 'member',
            'token' => hash('sha256', Str::random()), 'invited_by' => $this->owner->id, 'expires_at' => now()->addDay(),
        ]);
    }

    /**
     * action => [roles allowed, success status]. Roles not listed must get 403.
     *
     * @return array<string, array{0: array<int, string>, 1: int}>
     */
    private static function actions(): array
    {
        $all = ['owner', 'admin', 'member'];
        $managers = ['owner', 'admin'];

        return [
            'view org' => [$all, 200],
            'list members' => [$all, 200],
            'seats' => [$all, 200],
            'view subscription' => [$all, 200],
            'update settings' => [$managers, 200],
            'upload logo' => [$managers, 200],
            'list invites' => [$managers, 200],
            'create invite' => [$managers, 201],
            'revoke invite' => [$managers, 200],
            'remove member' => [$managers, 200],
            'change role' => [$managers, 200],
            'assign products' => [$managers, 200],
            'change plan' => [['owner'], 200],
            'billing portal' => [['owner'], 200],
            'delete org' => [['owner'], 200],
            'leave' => [['admin', 'member'], 200],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function matrix(): array
    {
        $cases = [];
        foreach (array_keys(self::actions()) as $action) {
            foreach (['owner', 'admin', 'member', 'outsider'] as $role) {
                $cases["{$role} → {$action}"] = [$role, $action];
            }
        }

        return $cases;
    }

    #[DataProvider('matrix')]
    public function test_role_matrix(string $role, string $action): void
    {
        [$allowed, $successStatus] = self::actions()[$action];
        Sanctum::actingAs($this->{$role});

        $response = $this->perform($action);

        if (in_array($role, $allowed, true)) {
            $response->assertStatus($successStatus);
        } else {
            $response->assertForbidden();

            if ($role === 'outsider') {
                $response->assertJsonPath('message', OrganizationPolicy::NOT_A_MEMBER);
            } else {
                $this->assertNotSame(OrganizationPolicy::NOT_A_MEMBER, $response->json('message'));
            }
        }
    }

    private function perform(string $action): TestResponse
    {
        $base = "/api/orgs/{$this->org->slug}";
        $other = $this->other->id;

        return match ($action) {
            'view org' => $this->getJson($base),
            'list members' => $this->getJson("{$base}/members"),
            'seats' => $this->getJson("{$base}/seats"),
            'view subscription' => $this->getJson("{$base}/subscription?product=re-intel"),
            'update settings' => $this->putJson($base, ['city' => 'Dubai']),
            'upload logo' => $this->post("{$base}/logo", ['logo' => UploadedFile::fake()->createWithContent('logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='))], ['Accept' => 'application/json']),
            'list invites' => $this->getJson("{$base}/invites"),
            'create invite' => $this->postJson("{$base}/invites", ['email' => 'new@example.com', 'role' => 'member']),
            'revoke invite' => $this->deleteJson("{$base}/invites/{$this->invite->id}"),
            'remove member' => $this->deleteJson("{$base}/members/{$other}"),
            'change role' => $this->putJson("{$base}/members/{$other}/role", ['role' => 'admin']),
            'assign products' => $this->putJson("{$base}/members/{$other}/products", ['product_ids' => [$this->product->id]]),
            'change plan' => $this->putJson("{$base}/subscription", ['plan_id' => $this->teamFree->id]),
            'billing portal' => $this->postJson("{$base}/billing/portal"),
            'delete org' => $this->deleteJson($base),
            'leave' => $this->postJson("{$base}/leave"),
        };
    }

    public function test_show_returns_permissions_from_the_matrix(): void
    {
        $expect = [
            'owner' => ['update' => true, 'manage_members' => true, 'manage_billing' => true, 'delete' => true, 'leave' => false],
            'admin' => ['update' => true, 'manage_members' => true, 'manage_billing' => false, 'delete' => false, 'leave' => true],
            'member' => ['update' => false, 'manage_members' => false, 'manage_billing' => false, 'delete' => false, 'leave' => true],
        ];

        foreach ($expect as $role => $flags) {
            Sanctum::actingAs($this->{$role});
            $permissions = $this->getJson("/api/orgs/{$this->org->slug}")->assertOk()->assertJsonPath('my_role', $role)->json('permissions');

            foreach ($flags as $flag => $value) {
                $this->assertSame($value, $permissions[$flag], "{$role}.{$flag}");
            }
        }
    }

    public function test_admin_cannot_remove_or_change_the_owner(): void
    {
        Sanctum::actingAs($this->admin);
        $base = "/api/orgs/{$this->org->slug}";

        $this->deleteJson("{$base}/members/{$this->owner->id}")->assertForbidden();
        $this->putJson("{$base}/members/{$this->owner->id}/role", ['role' => 'member'])->assertForbidden();
        $this->putJson("{$base}/members/{$this->other->id}/role", ['role' => 'owner'])->assertUnprocessable();

        $this->assertSame('owner', $this->roleOf($this->owner));
        $this->assertSame('member', $this->roleOf($this->other));
    }

    public function test_owner_cannot_demote_or_remove_themselves(): void
    {
        Sanctum::actingAs($this->owner);
        $base = "/api/orgs/{$this->org->slug}";

        $this->putJson("{$base}/members/{$this->owner->id}/role", ['role' => 'admin'])->assertForbidden();
        $this->deleteJson("{$base}/members/{$this->owner->id}")->assertForbidden();
        $this->postJson("{$base}/leave")->assertForbidden()
            ->assertJsonPath('message', 'The owner cannot leave the organization. Delete the organization instead.');

        $this->assertSame(1, $this->ownerCount());
    }

    public function test_admin_manages_other_admins_and_members(): void
    {
        $second = User::factory()->create();
        $this->org->members()->create(['user_id' => $second->id, 'role' => 'admin']);
        Sanctum::actingAs($this->admin);
        $base = "/api/orgs/{$this->org->slug}";

        $this->putJson("{$base}/members/{$second->id}/role", ['role' => 'member'])->assertOk();
        $this->assertSame('member', $this->roleOf($second));

        $this->putJson("{$base}/members/{$this->other->id}/role", ['role' => 'admin'])->assertOk();
        $this->deleteJson("{$base}/members/{$this->other->id}")->assertOk();
        $this->assertNull($this->roleOf($this->other));

        // Admins can invite other admins.
        $this->postJson("{$base}/invites", ['email' => 'boss@example.com', 'role' => 'admin'])->assertCreated();
    }

    public function test_removing_yourself_goes_through_leave(): void
    {
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/orgs/{$this->org->slug}/members/{$this->admin->id}")->assertUnprocessable();
        $this->assertSame('admin', $this->roleOf($this->admin));
    }

    public function test_ownership_can_never_be_transferred(): void
    {
        Sanctum::actingAs($this->owner);
        $base = "/api/orgs/{$this->org->slug}";

        // The transfer endpoint no longer exists.
        $this->postJson("{$base}/transfer", ['new_owner_id' => $this->member->id])->assertNotFound();

        // Nobody can promote a member to owner, not even the owner.
        $this->putJson("{$base}/members/{$this->member->id}/role", ['role' => 'owner'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertArrayNotHasKey('transfer_ownership', OrganizationPolicy::permissionsFor('owner'));
        $this->assertSame(1, $this->ownerCount());
        $this->assertSame('owner', $this->roleOf($this->owner));
        $this->assertSame('member', $this->roleOf($this->member));
    }

    public function test_leave_removes_membership_and_product_access(): void
    {
        OrgMemberProduct::create(['org_id' => $this->org->id, 'user_id' => $this->member->id, 'product_id' => $this->product->id]);
        Sanctum::actingAs($this->member);

        $this->postJson("/api/orgs/{$this->org->slug}/leave")->assertOk();

        $this->assertNull($this->roleOf($this->member));
        $this->assertSame(0, OrgMemberProduct::where('user_id', $this->member->id)->count());
        $this->getJson("/api/orgs/{$this->org->slug}")->assertForbidden();
        $this->postJson("/api/orgs/{$this->org->slug}/leave")->assertForbidden();
    }

    public function test_unknown_targets_are_404(): void
    {
        Sanctum::actingAs($this->owner);
        $base = "/api/orgs/{$this->org->slug}";

        $this->deleteJson("{$base}/members/not-a-uuid")->assertNotFound();
        $this->deleteJson("{$base}/members/{$this->outsider->id}")->assertNotFound();
        $this->deleteJson("{$base}/invites/".Str::uuid())->assertNotFound();
        $this->getJson('/api/orgs/no-such-org')->assertNotFound();
    }

    private function roleOf(User $user): ?string
    {
        return $this->org->members()->where('user_id', $user->id)->value('role');
    }

    private function ownerCount(): int
    {
        return $this->org->members()->where('role', 'owner')->count();
    }
}
