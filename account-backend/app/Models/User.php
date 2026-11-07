<?php

namespace App\Models;

use App\Models\Concerns\HasEntitledPlan;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'avatar_url', 'timezone'])]
#[Hidden(['password', 'remember_token', 'stripe_customer_id'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasEntitledPlan, HasFactory, HasUuids, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * @return HasMany<OrgMember, $this>
     */
    public function orgMemberships(): HasMany
    {
        return $this->hasMany(OrgMember::class);
    }

    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'org_members', 'user_id', 'org_id')
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
