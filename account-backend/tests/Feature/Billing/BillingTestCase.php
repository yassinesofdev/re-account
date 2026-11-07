<?php

namespace Tests\Feature\Billing;

use App\Models\Organization;
use App\Models\OrgMember;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\StripeGateway;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeStripeGateway;
use Tests\TestCase;

abstract class BillingTestCase extends TestCase
{
    use RefreshDatabase;

    protected const WEBHOOK_SECRET = 'whsec_test_secret';

    protected FakeStripeGateway $stripe;

    protected Product $product;

    protected Plan $personalFree;

    protected Plan $personalPro;

    protected Plan $teamFree;

    protected Plan $teamPro;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.secret' => 'sk_test_fake',
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
            'app.frontend_url' => 'http://localhost:3742',
        ]);

        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);

        $this->product = Product::factory()->create(['slug' => 're-intel', 'name' => 'RE Intel']);
        $this->personalFree = Plan::factory()->for($this->product)->create(['slug' => 'personal-free']);
        $this->personalPro = Plan::factory()->for($this->product)->paid(1200, 'price_personal_pro')->create(['slug' => 'personal-pro']);
        $this->teamFree = Plan::factory()->for($this->product)->organization()->create(['slug' => 'team-free']);
        $this->teamPro = Plan::factory()->for($this->product)->organization()->paid(4900, 'price_team_pro')->create(['slug' => 'team-pro', 'name' => 'Team']);
    }

    protected function subscribe(Model $subject, Plan $plan, ?string $stripeId = null, array $attributes = []): Subscription
    {
        $factory = Subscription::factory();
        if ($stripeId) {
            $factory = $factory->stripe($stripeId, $plan->stripe_price_id);
        }

        return $factory->create([
            'subscribable_type' => $subject::class,
            'subscribable_id' => $subject->id,
            'plan_id' => $plan->id,
            'product_id' => $plan->product_id,
            // Org rows hold a quantity; a free org plan starts at its cap.
            ...($subject instanceof Organization && $plan->seatCap() !== null ? ['quantity' => $plan->seatCap()] : []),
            ...$attributes,
        ]);
    }

    /**
     * Local paid subscription + the matching Stripe subscription in the fake.
     */
    protected function subscribePaid(Model $subject, Plan $plan, string $stripeId = 'sub_1', array $attributes = []): Subscription
    {
        $this->stripe->putSubscription(FakeStripeGateway::subscriptionPayload(
            $stripeId, $plan->stripe_price_id, $attributes['status'] ?? 'active', $this->metadataFor($subject, $plan),
            null, $attributes['cancel_at_period_end'] ?? false,
        ));

        return $this->subscribe($subject, $plan, $stripeId, $attributes);
    }

    /**
     * @return array<string, string>
     */
    protected function metadataFor(Model $subject, Plan $plan, ?User $initiator = null): array
    {
        return [
            'subscribable_type' => $subject instanceof Organization ? 'organization' : 'user',
            'subscribable_id' => $subject->id,
            'product_id' => $plan->product_id,
            'plan_id' => $plan->id,
            'initiated_by_user_id' => $initiator?->id ?? ($subject instanceof User ? $subject->id : $subject->created_by),
        ];
    }

    protected function orgWith(User $owner, array $others = []): Organization
    {
        $org = Organization::factory()->create(['created_by' => $owner->id]);
        OrgMember::create(['org_id' => $org->id, 'user_id' => $owner->id, 'role' => 'owner', 'joined_at' => now()]);

        foreach ($others as $role => $users) {
            foreach (is_array($users) ? $users : [$users] as $user) {
                OrgMember::create(['org_id' => $org->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);
            }
        }

        return $org;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    protected function postWebhook(string $type, array $object, string $eventId = 'evt_1', ?string $secret = null): TestResponse
    {
        $payload = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ]);

        $ts = time();
        $sig = hash_hmac('sha256', "{$ts}.{$payload}", $secret ?? self::WEBHOOK_SECRET);

        return $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$ts},v1={$sig}",
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }
}
