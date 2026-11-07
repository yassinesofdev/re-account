<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrgMember;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductUserStatusTest extends TestCase
{
    use RefreshDatabase;

    private function check(array $body, ?string $secret = 'product-secret')
    {
        $headers = ['Accept' => 'application/json'];
        if ($secret !== null) {
            $headers['X-Product-Secret'] = $secret;
        }

        return $this->postJson('/api/product/user-status', $body + ['product' => 're-intel'], $headers);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Product::factory()->create(['slug' => 're-intel']);
    }

    public function test_requires_a_valid_product_secret(): void
    {
        $user = User::factory()->create();

        $this->check(['user_id' => $user->id], null)->assertStatus(401);
        $this->check(['user_id' => $user->id], 'wrong')->assertStatus(401);
        $this->check(['user_id' => $user->id, 'product' => 'nope'])->assertStatus(401);
    }

    public function test_existing_user_without_org(): void
    {
        $user = User::factory()->create(['timezone' => 'Asia/Dubai']);

        $this->check(['user_id' => $user->id])
            ->assertOk()
            ->assertExactJson(['exists' => true, 'active' => true, 'org_member' => null, 'timezone' => 'Asia/Dubai']);
    }

    public function test_unknown_user(): void
    {
        $this->check(['user_id' => (string) Str::uuid(), 'org_id' => (string) Str::uuid()])
            ->assertOk()
            ->assertJson(['exists' => false, 'active' => false, 'org_member' => false]);
    }

    public function test_org_membership(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        OrgMember::create(['org_id' => $org->id, 'user_id' => $user->id, 'role' => 'member']);

        $this->check(['user_id' => $user->id, 'org_id' => $org->id])->assertOk()->assertJson(['org_member' => true]);
        $this->check(['user_id' => $user->id, 'org_id' => $other->id])->assertOk()->assertJson(['org_member' => false]);
    }
}
