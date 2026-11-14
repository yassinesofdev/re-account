<?php

namespace Tests\Feature\Auth;

use App\Models\Product;
use App\Models\User;
use App\Services\TokenService;
use Firebase\JWT\JWT;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Product handoff tokens are single-use (consumed_handoff_tokens), and API
 * guests always get a JSON 401.
 */
class HandoffTokenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Product::factory()->create(['slug' => 're-intel', 'is_active' => true, 'secret_key' => Hash::make('intel-secret')]);
        $this->user = User::factory()->create();
    }

    private function verify(string $token, string $secret = 'intel-secret')
    {
        return $this->postJson('/api/verify-token', ['token' => $token], ['X-Product-Secret' => $secret]);
    }

    public function test_token_is_accepted_once_then_rejected(): void
    {
        $token = app(TokenService::class)->issueHandoffToken($this->user, 're-intel');

        $this->verify($token)->assertOk()->assertJsonPath('user_id', $this->user->id);
        $this->verify($token)->assertUnauthorized()->assertJsonPath('message', 'Token already used.');

        $this->assertSame(1, DB::table('consumed_handoff_tokens')->count());
    }

    public function test_each_issued_token_has_a_unique_jti(): void
    {
        $tokens = app(TokenService::class);
        $a = $tokens->decode($tokens->issueHandoffToken($this->user, 're-intel'));
        $b = $tokens->decode($tokens->issueHandoffToken($this->user, 're-intel'));

        $this->assertNotEmpty($a['jti']);
        $this->assertNotSame($a['jti'], $b['jti']);
    }

    public function test_wrong_secret_does_not_burn_the_token(): void
    {
        $token = app(TokenService::class)->issueHandoffToken($this->user, 're-intel');

        $this->verify($token, 'wrong')->assertUnauthorized();
        $this->verify($token)->assertOk();
    }

    public function test_token_without_jti_is_rejected(): void
    {
        $legacy = JWT::encode([
            'iat' => time(), 'exp' => time() + 900, 'purpose' => 'handoff',
            'user_id' => $this->user->id, 'email' => $this->user->email, 'name' => $this->user->name,
            'product' => 're-intel', 'context' => 'personal', 'org_id' => null, 'org_name' => null, 'org_role' => null,
        ], (string) config('jwt.secret'), 'HS256');

        $this->verify($legacy)->assertUnauthorized();
    }

    public function test_expired_rows_are_pruned_on_write(): void
    {
        DB::table('consumed_handoff_tokens')->insert([
            ['jti' => 'old', 'expires_at' => now()->subMinute(), 'consumed_at' => now()->subMinutes(20)],
            ['jti' => 'live', 'expires_at' => now()->addMinute(), 'consumed_at' => now()->subMinutes(14)],
        ]);

        $this->verify(app(TokenService::class)->issueHandoffToken($this->user, 're-intel'))->assertOk();

        $this->assertDatabaseMissing('consumed_handoff_tokens', ['jti' => 'old']);
        $this->assertDatabaseHas('consumed_handoff_tokens', ['jti' => 'live']);
        $this->assertSame(2, DB::table('consumed_handoff_tokens')->count());
    }

    public function test_consumption_is_atomic_per_jti(): void
    {
        // Two containers racing on the same token: the jti primary key lets
        // exactly one insert win, whatever the interleaving.
        $tokens = app(TokenService::class);
        $payload = ['jti' => 'race', 'exp' => time() + 900];

        $this->assertTrue($tokens->consumeHandoffToken($payload));
        $this->assertFalse($tokens->consumeHandoffToken($payload));

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('consumed_handoff_tokens')->insert(['jti' => 'race', 'expires_at' => now()->addMinute(), 'consumed_at' => now()]);
    }

    public function test_api_guest_gets_json_401_without_accept_header(): void
    {
        $this->get('/api/auth/me')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->get('/api/admin/users')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->getJson('/api/orgs')->assertUnauthorized();
        $this->getJson('/api/orgs/slug-availability?slug=acme')->assertUnauthorized();
    }
}
