<?php

namespace App\Services;

use App\Exceptions\SeatLimitExceededException;
use App\Mail\InviteMail;
use App\Models\Organization;
use App\Models\OrgInvite;
use App\Models\OrgMember;
use App\Models\OrgMemberProduct;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrgService
{
    public function findBySlugOrFail(string $slug): Organization
    {
        return Organization::where('slug', $slug)->firstOrFail();
    }

    public function membershipFor(Organization $org, User $user): ?OrgMember
    {
        return $org->members()->where('user_id', $user->id)->first();
    }

    public function createOrganization(User $owner, string $name, ?string $slug = null, ?string $logoUrl = null): Organization
    {
        $slug = $this->uniqueSlug($slug ?: $name);

        $org = Organization::create([
            'name' => $name,
            'slug' => $slug,
            'logo_url' => $logoUrl,
            'created_by' => $owner->id,
        ]);

        OrgMember::create([
            'org_id' => $org->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        return $org;
    }

    public function uniqueSlug(string $source): string
    {
        $base = Str::slug($source);
        $slug = $base;
        $i = 1;

        while (Organization::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    /**
     * The products this org can hand out access to: those it has a
     * subscription for (any status), ordered by name.
     *
     * @return Collection<int, Product>
     */
    public function orgProducts(Organization $org): Collection
    {
        return Product::whereIn('id', $org->subscriptions()->select('product_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Seats in use for one product: members assigned to it + pending,
     * unexpired invites that include it.
     */
    public function seatsUsed(Organization $org, Product $product): int
    {
        return array_sum($this->seatUsage($org, $product));
    }

    /**
     * @return array{assigned: int, pending_invites: int}
     */
    public function seatUsage(Organization $org, Product $product): array
    {
        return [
            'assigned' => $org->productAccess()->where('product_id', $product->id)->count(),
            'pending_invites' => $org->invites()
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->whereHas('products', fn ($q) => $q->where('products.id', $product->id))
                ->count(),
        ];
    }

    /**
     * Seats the org may use for a product: the purchased quantity of its
     * entitled subscription (per-seat billing). Null = unlimited (no entitled
     * plan picked yet, or a row without a quantity).
     */
    public function seatLimitFor(Organization $org, Product $product): ?int
    {
        $subscription = $org->subscriptionFor($product);

        return $subscription?->isEntitled() ? $subscription->seatLimit() : null;
    }

    /**
     * @return array<int, array{product: array<string, mixed>, plan: ?array<string, mixed>, used: int, limit: ?int}>
     */
    public function seatSummary(Organization $org): array
    {
        return $this->orgProducts($org)->map(function (Product $product) use ($org) {
            $plan = $org->entitledPlanFor($product);

            return [
                // `roles` lets the UI render the role select per product.
                'product' => $product->only(['id', 'slug', 'name', 'roles']),
                'plan' => $plan?->only(['id', 'slug', 'name']),
                'used' => $this->seatsUsed($org, $product),
                'limit' => $this->seatLimitFor($org, $product),
            ];
        })->values()->all();
    }

    /**
     * @throws SeatLimitExceededException when one more seat doesn't fit
     */
    public function assertSeatAvailable(Organization $org, Product $product): void
    {
        $limit = $this->seatLimitFor($org, $product);
        $used = $this->seatsUsed($org, $product);

        if ($limit !== null && $used >= $limit) {
            throw new SeatLimitExceededException($product, $used, $limit);
        }
    }

    /**
     * Resolve requested product IDs to products of this org (422 otherwise).
     *
     * @param  array<int, string>  $productIds
     * @return Collection<int, Product>
     */
    public function resolveOrgProducts(Organization $org, array $productIds): Collection
    {
        $available = $this->orgProducts($org)->keyBy('id');
        $unknown = array_diff(array_unique($productIds), $available->keys()->all());

        if ($unknown) {
            throw ValidationException::withMessages([
                'product_ids' => ['This organization does not subscribe to one of the selected products.'],
            ]);
        }

        return $available->only(array_unique($productIds))->values();
    }

    /**
     * Normalise a product selection: plain IDs or {product_id, role?} objects
     * → [product_id => role|null] (null = use the default).
     *
     * @param  array<int, mixed>  $items
     * @return array<string, ?string>
     */
    public static function parseProductSelection(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $out[(string) ($item['product_id'] ?? '')] = isset($item['role']) && $item['role'] !== '' ? (string) $item['role'] : null;
            } else {
                $out[(string) $item] = null;
            }
        }

        return $out;
    }

    /**
     * Resolve a selection to this org's products with a valid product role
     * each. A missing role keeps $current[$productId] when set, otherwise the
     * product's default for $orgRole (owner/admin → manager when declared).
     *
     * @param  array<string, ?string>  $selection  product_id => role|null
     * @param  array<string, ?string>  $current  product_id => current role
     * @return \Illuminate\Support\Collection<int, array{product: Product, role: ?string}>
     */
    public function resolveProductAssignments(Organization $org, array $selection, ?string $orgRole, array $current = [])
    {
        $products = $this->resolveOrgProducts($org, array_keys($selection));

        return $products->map(function (Product $product) use ($selection, $orgRole, $current) {
            $role = $selection[$product->id] ?? null;

            if ($role !== null && ! $product->hasRole($role)) {
                throw ValidationException::withMessages([
                    'product_role' => [$product->roleKeys()
                        ? "{$product->name} roles are: ".implode(', ', $product->roleKeys()).'.'
                        : "{$product->name} has no roles."],
                ]);
            }

            if ($role === null) {
                $kept = $current[$product->id] ?? null;
                $role = $product->hasRole($kept) ? $kept : $product->defaultRoleFor($orgRole);
            }

            return ['product' => $product, 'role' => $role];
        })->values()->toBase();
    }

    /**
     * The member's role in a product within an org (null: no access, or the
     * product declares no roles).
     */
    public function productRoleFor(string $orgId, string $userId, Product $product): ?string
    {
        return OrgMemberProduct::where([
            'org_id' => $orgId,
            'user_id' => $userId,
            'product_id' => $product->id,
        ])->value('role');
    }

    /**
     * After a product's role list changed: assignments/invites whose role is
     * no longer declared (or null while roles exist) get the default again.
     */
    public function reconcileProductRoles(Product $product): void
    {
        $keys = $product->roleKeys();

        $members = OrgMemberProduct::where('product_id', $product->id)
            ->when($keys, fn ($q) => $q->where(fn ($q) => $q->whereNull('role')->orWhereNotIn('role', $keys)))
            ->when(! $keys, fn ($q) => $q->whereNotNull('role'))
            ->get();

        foreach ($members as $row) {
            $orgRole = OrgMember::where(['org_id' => $row->org_id, 'user_id' => $row->user_id])->value('role');
            $row->forceFill(['role' => $product->defaultRoleFor($orgRole)])->save();
        }

        $invites = DB::table('org_invite_products as oip')
            ->join('org_invites as i', 'i.id', '=', 'oip.invite_id')
            ->where('oip.product_id', $product->id)
            ->when($keys, fn ($q) => $q->where(fn ($q) => $q->whereNull('oip.role')->orWhereNotIn('oip.role', $keys)))
            ->when(! $keys, fn ($q) => $q->whereNotNull('oip.role'))
            ->get(['oip.id', 'i.role as org_role']);

        foreach ($invites as $row) {
            DB::table('org_invite_products')->where('id', $row->id)->update(['role' => $product->defaultRoleFor($row->org_role)]);
        }
    }

    public function hasProductAccess(string $orgId, string $userId, Product $product): bool
    {
        return OrgMemberProduct::where([
            'org_id' => $orgId,
            'user_id' => $userId,
            'product_id' => $product->id,
        ])->exists();
    }

    /**
     * Replace a member's product access with exactly $assignments (from
     * resolveProductAssignments). Only newly added products are seat-checked;
     * removing access or changing a role always works.
     *
     * @param  \Illuminate\Support\Collection<int, array{product: Product, role: ?string}>  $assignments
     */
    public function setMemberProducts(Organization $org, string $userId, $assignments, ?User $actor): void
    {
        DB::transaction(function () use ($org, $userId, $assignments, $actor) {
            // Serialize seat checks per org.
            Organization::whereKey($org->id)->lockForUpdate()->first();

            $current = $org->productAccess()->where('user_id', $userId)->get()->keyBy('product_id');
            $wanted = $assignments->pluck('product.id')->all();

            $org->productAccess()
                ->where('user_id', $userId)
                ->whereNotIn('product_id', $wanted)
                ->delete();

            foreach ($assignments as ['product' => $product, 'role' => $role]) {
                if ($existing = $current->get($product->id)) {
                    if ($existing->role !== $role) {
                        $existing->forceFill(['role' => $role])->save();
                    }

                    continue;
                }

                $this->assertSeatAvailable($org, $product);

                OrgMemberProduct::create([
                    'org_id' => $org->id,
                    'user_id' => $userId,
                    'product_id' => $product->id,
                    'assigned_by' => $actor?->id,
                    'role' => $role,
                ]);
            }
        });
    }

    /**
     * Current product roles of a member: [product_id => role].
     *
     * @return array<string, ?string>
     */
    public function memberProductRoles(Organization $org, string $userId): array
    {
        return $org->productAccess()->where('user_id', $userId)->pluck('role', 'product_id')->all();
    }

    /**
     * Members with their assigned products ({id, slug, name}).
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function membersWithProducts(Organization $org, ?string $userId = null)
    {
        $members = $org->members()->with('user')
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('joined_at')
            ->get();

        $access = $org->productAccess()->with('product:id,slug,name,roles')
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->get()
            ->groupBy('user_id');

        return $members->map(fn (OrgMember $member) => [
            ...$member->toArray(),
            'products' => ($access[$member->user_id] ?? collect())
                ->map(fn (OrgMemberProduct $row) => [...$row->product->only(['id', 'slug', 'name']), 'role' => $row->role])
                ->sortBy('name')->values()->all(),
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array{product: Product, role: ?string}>  $assignments  products (+ product role) the invite grants
     */
    public function inviteMember(Organization $org, User $inviter, string $email, string $role, $assignments): OrgInvite
    {
        $products = $assignments->pluck('product');

        $plainToken = Str::random(48);

        $invite = DB::transaction(function () use ($org, $inviter, $email, $role, $products, $assignments, $plainToken) {
            Organization::whereKey($org->id)->lockForUpdate()->first();

            foreach ($products as $product) {
                $this->assertSeatAvailable($org, $product);
            }

            $invite = OrgInvite::create([
                'org_id' => $org->id,
                'email' => $email,
                'role' => $role,
                'token' => hash('sha256', $plainToken),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(7),
            ]);

            foreach ($assignments as ['product' => $product, 'role' => $productRole]) {
                DB::table('org_invite_products')->insert([
                    'id' => (string) Str::uuid(),
                    'invite_id' => $invite->id,
                    'product_id' => $product->id,
                    'role' => $productRole,
                ]);
            }

            return $invite;
        });

        Mail::to($email)->send(new InviteMail($invite, $org, $inviter, $plainToken));

        return $invite->load('products:id,slug,name');
    }

    /**
     * Invite JSON with `products: [{id, slug, name, role}]` (role from the pivot).
     *
     * @return array<string, mixed>
     */
    public static function presentInvite(OrgInvite $invite): array
    {
        $products = $invite->products->map(fn (Product $p) => [
            ...$p->only(['id', 'slug', 'name']),
            'role' => $p->pivot?->role,
        ])->values()->all();

        $invite->unsetRelation('products');

        return [...$invite->toArray(), 'products' => $products];
    }

    /**
     * When an org subscribes to a product for the first time, its owner
     * gets a seat (the purchaser can unassign themselves afterwards).
     */
    public function assignOwnerToNewProduct(Subscription $subscription): void
    {
        if ($subscription->subscribable_type !== Organization::class) {
            return;
        }

        $owner = OrgMember::where('org_id', $subscription->subscribable_id)->where('role', 'owner')->first();

        if ($owner) {
            OrgMemberProduct::firstOrCreate([
                'org_id' => $owner->org_id,
                'user_id' => $owner->user_id,
                'product_id' => $subscription->product_id,
            ], [
                'role' => Product::find($subscription->product_id)?->defaultRoleFor('owner'),
            ]);
        }
    }

    public function findInviteByToken(string $token): ?OrgInvite
    {
        return OrgInvite::where('token', hash('sha256', $token))->first();
    }

    public function acceptInvite(OrgInvite $invite, User $user): ?OrgMember
    {
        if ($invite->isAccepted() || $invite->isExpired()) {
            return null;
        }

        if (strcasecmp($invite->email, $user->email) !== 0) {
            return null;
        }

        return DB::transaction(function () use ($invite, $user) {
            $member = OrgMember::firstOrCreate(
                ['org_id' => $invite->org_id, 'user_id' => $user->id],
                ['role' => $invite->role, 'joined_at' => now()],
            );

            // The invite already held these seats, so no second seat check.
            // The product role carries over (default if the invite had none).
            foreach ($invite->products()->get() as $product) {
                $role = $product->hasRole($product->pivot->role)
                    ? $product->pivot->role
                    : $product->defaultRoleFor($member->role);

                OrgMemberProduct::firstOrCreate(
                    ['org_id' => $invite->org_id, 'user_id' => $user->id, 'product_id' => $product->id],
                    ['assigned_by' => $invite->invited_by, 'role' => $role],
                );
            }

            $invite->forceFill(['accepted_at' => now()])->save();

            return $member;
        });
    }

    public function removeMember(Organization $org, string $userId): void
    {
        DB::transaction(function () use ($org, $userId) {
            $org->productAccess()->where('user_id', $userId)->delete();
            $org->members()->where('user_id', $userId)->delete();
        });
    }

    public function updateMemberRole(Organization $org, string $userId, string $role): void
    {
        $org->members()->where('user_id', $userId)->update(['role' => $role]);
    }
}
