<?php

namespace Tests\Feature\Org;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Org slugs become subdomains: DNS-safe and never a reserved name
 * (config/subdomains.php).
 */
class OrgSlugTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
    }

    public function test_reserved_list_is_lowercase_dns_safe_and_unique(): void
    {
        $reserved = config('subdomains.reserved');

        $this->assertGreaterThanOrEqual(150, count($reserved));
        $this->assertSame(count($reserved), count(array_unique($reserved)));
        foreach ($reserved as $name) {
            $this->assertMatchesRegularExpression('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $name);
        }
    }

    public function test_valid_slug_is_accepted_on_create(): void
    {
        $this->postJson('/api/orgs', ['name' => 'Marina Homes', 'slug' => 'marina-homes-2'])
            ->assertCreated()
            ->assertJsonPath('organization.slug', 'marina-homes-2');
    }

    public static function reservedSlugs(): array
    {
        return [['support'], ['login'], ['www'], ['api'], ['intelligence'], ['no-reply']];
    }

    #[DataProvider('reservedSlugs')]
    public function test_reserved_slug_is_rejected_on_create(string $slug): void
    {
        $this->postJson('/api/orgs', ['name' => 'Acme', 'slug' => $slug])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug'])
            ->assertJsonPath('errors.slug.0', "\"{$slug}\" is reserved. Please choose a different slug.");

        $this->assertDatabaseMissing('organizations', ['slug' => $slug]);
    }

    public static function invalidSlugs(): array
    {
        return [
            'uppercase' => ['Acme'],
            'underscore' => ['acme_homes'],
            'leading hyphen' => ['-acme'],
            'trailing hyphen' => ['acme-'],
            'dot' => ['acme.homes'],
            'too long' => [str_repeat('a', 64)],
        ];
    }

    #[DataProvider('invalidSlugs')]
    public function test_non_dns_safe_slug_is_rejected_on_create(string $slug): void
    {
        $this->postJson('/api/orgs', ['name' => 'Acme', 'slug' => $slug])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_63_char_slug_is_accepted(): void
    {
        $slug = str_repeat('a', 63);

        $this->postJson('/api/orgs', ['name' => 'Acme', 'slug' => $slug])
            ->assertCreated()
            ->assertJsonPath('organization.slug', $slug);
    }

    public function test_reserved_slug_is_rejected_on_change(): void
    {
        $slug = $this->postJson('/api/orgs', ['name' => 'Palm Realty'])->assertCreated()->json('organization.slug');

        $this->putJson("/api/orgs/{$slug}", ['slug' => 'billing'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);

        $this->putJson("/api/orgs/{$slug}", ['slug' => 'Palm_Realty'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);

        $this->assertDatabaseHas('organizations', ['slug' => 'palm-realty']);
    }

    public function test_valid_slug_change_is_accepted(): void
    {
        $slug = $this->postJson('/api/orgs', ['name' => 'Palm Realty'])->json('organization.slug');

        $this->putJson("/api/orgs/{$slug}", ['slug' => 'palm-realty-dubai'])
            ->assertOk()
            ->assertJsonPath('organization.slug', 'palm-realty-dubai');
    }

    public function test_legacy_slug_sent_back_unchanged_is_allowed(): void
    {
        $org = Organization::factory()->create(['slug' => 'support']);
        $org->members()->create(['user_id' => auth()->id(), 'role' => 'owner', 'joined_at' => now()]);

        $this->putJson('/api/orgs/support', ['slug' => 'support', 'city' => 'Dubai'])
            ->assertOk()
            ->assertJsonPath('organization.city', 'Dubai');
    }

    public function test_reserved_name_gets_a_safe_generated_slug(): void
    {
        $this->postJson('/api/orgs', ['name' => 'Support'])
            ->assertCreated()
            ->assertJsonPath('organization.slug', 'support-1');

        $this->postJson('/api/orgs', ['name' => 'Support'])
            ->assertCreated()
            ->assertJsonPath('organization.slug', 'support-2');

        $this->postJson('/api/orgs', ['name' => 'RE'])
            ->assertCreated()
            ->assertJsonPath('organization.slug', 're-1');
    }

    public function test_generated_slug_is_dns_safe_for_awkward_names(): void
    {
        $this->postJson('/api/orgs', ['name' => '!!!'])
            ->assertCreated()
            ->assertJsonPath('organization.slug', 'org-1'); // "org" itself is reserved

        $long = $this->postJson('/api/orgs', ['name' => str_repeat('Palm ', 30)])
            ->assertCreated()
            ->json('organization.slug');
        $this->assertMatchesRegularExpression('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $long);

        $again = $this->postJson('/api/orgs', ['name' => str_repeat('Palm ', 30)])->json('organization.slug');
        $this->assertNotSame($long, $again);
        $this->assertLessThanOrEqual(63, strlen($again));
    }

    public function test_slug_availability(): void
    {
        Organization::factory()->create(['slug' => 'acme']);

        $this->getJson('/api/orgs/slug-availability?slug=marina-homes')->assertOk()
            ->assertExactJson(['slug' => 'marina-homes', 'available' => true, 'reason' => null, 'suggestion' => null]);

        $this->getJson('/api/orgs/slug-availability?slug=acme')->assertOk()
            ->assertJsonPath('available', false)->assertJsonPath('reason', 'taken')->assertJsonPath('suggestion', 'acme-1');

        $this->getJson('/api/orgs/slug-availability?slug=support')->assertOk()
            ->assertJsonPath('reason', 'reserved')->assertJsonPath('suggestion', 'support-1');

        $this->getJson('/api/orgs/slug-availability?slug=slug-availability')->assertOk()->assertJsonPath('reason', 'reserved');

        foreach (['Acme', '-acme', 'acme-', 'ac_me', str_repeat('a', 64)] as $bad) {
            $this->getJson('/api/orgs/slug-availability?slug='.urlencode($bad))->assertOk()
                ->assertJsonPath('available', false)->assertJsonPath('reason', 'invalid');
        }
        $this->getJson('/api/orgs/slug-availability?slug=Ac_Me')->assertJsonPath('suggestion', 'ac-me');

        $this->getJson('/api/orgs/slug-availability')->assertUnprocessable()->assertJsonValidationErrors(['slug']);
    }
}
