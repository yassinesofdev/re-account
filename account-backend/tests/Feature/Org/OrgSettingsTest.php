<?php

namespace Tests\Feature\Org;

use App\Models\Organization;
use App\Models\OrgMember;
use App\Models\Product;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrgSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private User $member;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->member = User::factory()->create();

        Sanctum::actingAs($this->owner);
        $slug = $this->postJson('/api/orgs', ['name' => 'Marina Homes'])->assertCreated()->json('organization.slug');
        $this->org = Organization::where('slug', $slug)->firstOrFail();

        OrgMember::create(['org_id' => $this->org->id, 'user_id' => $this->admin->id, 'role' => 'admin']);
        OrgMember::create(['org_id' => $this->org->id, 'user_id' => $this->member->id, 'role' => 'member']);
    }

    private function settings(): array
    {
        return [
            'name' => 'Marina Homes LLC',
            'website' => 'marinahomes.ae',
            'contact_email' => 'hello@marinahomes.ae',
            'phone' => '+971 4 123 4567',
            'address' => 'Marina Plaza, Office 1203',
            'city' => 'Dubai',
            'orn' => '12345',
            'trade_license_number' => 'DED-987654',
            'timezone' => 'Asia/Dubai',
            'brand_color' => '#10b981',
        ];
    }

    public function test_new_org_defaults_to_dubai_timezone(): void
    {
        $this->getJson("/api/orgs/{$this->org->slug}")
            ->assertOk()
            ->assertJsonPath('organization.timezone', 'Asia/Dubai')
            ->assertJsonPath('organization.brand_color', null)
            ->assertJsonPath('organization.orn', null);
    }

    public function test_admin_saves_all_settings(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/orgs/{$this->org->slug}", $this->settings())
            ->assertOk()
            ->assertJsonPath('organization.name', 'Marina Homes LLC')
            ->assertJsonPath('organization.website', 'https://marinahomes.ae')
            ->assertJsonPath('organization.brand_color', '#10B981')
            ->assertJsonPath('organization.orn', '12345')
            ->assertJsonPath('organization.trade_license_number', 'DED-987654')
            ->assertJsonPath('organization.city', 'Dubai');

        // Clearing optional fields works.
        $this->putJson("/api/orgs/{$this->org->slug}", ['orn' => null, 'brand_color' => '', 'website' => ''])
            ->assertOk()
            ->assertJsonPath('organization.orn', null)
            ->assertJsonPath('organization.brand_color', null)
            ->assertJsonPath('organization.website', null)
            ->assertJsonPath('organization.city', 'Dubai');
    }

    public function test_member_cannot_edit_settings(): void
    {
        Sanctum::actingAs($this->member);

        $this->putJson("/api/orgs/{$this->org->slug}", ['city' => 'Abu Dhabi'])->assertForbidden();
        $this->assertNull($this->org->fresh()->city);
    }

    public function test_settings_are_validated(): void
    {
        $bad = [
            'orn' => 'ORN-12',
            'brand_color' => 'green',
            'phone' => 'call me',
            'timezone' => 'Mars/Olympus',
            'contact_email' => 'nope',
            'website' => 'ftp://example.com',
            'trade_license_number' => '!!',
            'name' => '',
        ];

        $this->putJson("/api/orgs/{$this->org->slug}", $bad)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($bad));
    }

    public function test_orn_is_unique_across_organizations(): void
    {
        $this->putJson("/api/orgs/{$this->org->slug}", ['orn' => '777'])->assertOk();
        // Re-saving the same ORN on the same org is fine.
        $this->putJson("/api/orgs/{$this->org->slug}", ['orn' => '777', 'city' => 'Dubai'])->assertOk();

        $other = $this->postJson('/api/orgs', ['name' => 'Palm Realty'])->json('organization.slug');
        $this->putJson("/api/orgs/{$other}", ['orn' => '777'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('orn');

        // Several orgs without an ORN don't collide.
        $this->putJson("/api/orgs/{$other}", ['orn' => null])->assertOk();
        $this->postJson('/api/orgs', ['name' => 'Downtown Brokers'])->assertCreated();
    }

    public function test_logo_upload(): void
    {
        Storage::fake('public');

        $url = $this->post("/api/orgs/{$this->org->slug}/logo", [
            'logo' => UploadedFile::fake()->createWithContent('logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==')),
        ], ['Accept' => 'application/json'])->assertOk()->json('organization.logo_url');

        $this->assertStringContainsString('/storage/org-logos/', $url);
        Storage::disk('public')->assertExists('org-logos/'.basename($url));

        $this->post("/api/orgs/{$this->org->slug}/logo", [
            'logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('logo');
    }

    public function test_verify_token_includes_org_branding(): void
    {
        $product = Product::factory()->create(['slug' => 're-intel', 'secret_key' => Hash::make('s3cret')]);
        $this->putJson("/api/orgs/{$this->org->slug}", $this->settings())->assertOk();
        $membership = $this->org->members()->where('user_id', $this->owner->id)->first();

        $orgToken = app(TokenService::class)->issueHandoffToken($this->owner, $product->slug, $membership);
        $this->postJson('/api/verify-token', ['token' => $orgToken], ['X-Product-Secret' => 's3cret'])
            ->assertOk()
            ->assertJsonPath('org_id', $this->org->id)
            ->assertJsonPath('org_name', 'Marina Homes LLC')
            ->assertJsonPath('org.brand_color', '#10B981')
            ->assertJsonPath('org.timezone', 'Asia/Dubai')
            ->assertJsonPath('org.orn', '12345')
            ->assertJsonPath('org.logo_url', null);

        $personal = app(TokenService::class)->issueHandoffToken($this->owner, $product->slug);
        $this->postJson('/api/verify-token', ['token' => $personal], ['X-Product-Secret' => 's3cret'])
            ->assertOk()
            ->assertJsonPath('context', 'personal')
            ->assertJsonPath('org', null);
    }
}
