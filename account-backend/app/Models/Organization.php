<?php

namespace App\Models;

use App\Models\Concerns\HasEntitledPlan;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'name', 'slug', 'logo_url', 'created_by',
    'website', 'contact_email', 'phone', 'address', 'city',
    'orn', 'trade_license_number', 'timezone', 'brand_color',
])]
#[Hidden(['stripe_customer_id'])]
class Organization extends Model
{
    use HasEntitledPlan, HasFactory, HasUuids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'timezone' => 'Asia/Dubai',
    ];

    /**
     * Branding/defaults handed to products (verify-token `org`).
     *
     * @return array<string, mixed>
     */
    public function productProfile(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'logo_url' => $this->logo_url,
            'brand_color' => $this->brand_color,
            'timezone' => $this->timezone ?? 'Asia/Dubai',
            'orn' => $this->orn,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<OrgMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(OrgMember::class, 'org_id');
    }

    /**
     * @return HasMany<OrgInvite, $this>
     */
    public function invites(): HasMany
    {
        return $this->hasMany(OrgInvite::class, 'org_id');
    }

    /**
     * Per-product access of the org's members (one row = one product seat).
     *
     * @return HasMany<OrgMemberProduct, $this>
     */
    public function productAccess(): HasMany
    {
        return $this->hasMany(OrgMemberProduct::class, 'org_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'org_members', 'org_id', 'user_id')
            ->withPivot(['id', 'role', 'joined_at']);
    }

    /**
     * @return MorphMany<Subscription, $this>
     */
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'subscribable');
    }

    public function subscriptionFor(Product $product): ?Subscription
    {
        return $this->subscriptions()->where('product_id', $product->id)->first();
    }
}
