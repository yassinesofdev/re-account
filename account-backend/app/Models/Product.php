<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<int, array{key: string, name: string, default: bool}>|null $roles
 */
#[Fillable(['name', 'slug', 'description', 'redirect_url', 'secret_key', 'is_active', 'roles', 'super_admin_only'])]
#[Hidden(['secret_key'])]
class Product extends Model
{
    use HasFactory, HasUuids;

    /** Org owners/admins get this product role by default when the product declares it. */
    public const MANAGER_ROLE = 'manager';

    protected $attributes = [
        'super_admin_only' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'super_admin_only' => 'boolean',
            'roles' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            $product->roles = self::normalizeRoles($product->roles ?? []);
        });
    }

    /**
     * Exactly one default (the first flagged one, else the first role).
     *
     * @param  array<int, array<string, mixed>>  $roles
     * @return array<int, array{key: string, name: string, default: bool}>
     */
    public static function normalizeRoles(array $roles): array
    {
        $out = [];
        $defaultSeen = false;

        foreach (array_values($roles) as $role) {
            $isDefault = ! $defaultSeen && ! empty($role['default']);
            $defaultSeen = $defaultSeen || $isDefault;
            $out[] = ['key' => (string) $role['key'], 'name' => (string) $role['name'], 'default' => $isDefault];
        }

        if ($out && ! $defaultSeen) {
            $out[0]['default'] = true;
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    public function roleKeys(): array
    {
        return array_column($this->roles ?? [], 'key');
    }

    public function hasRole(?string $key): bool
    {
        return $key !== null && in_array($key, $this->roleKeys(), true);
    }

    /**
     * The role a person gets when assigned without an explicit one: org
     * owner/admin → `manager` (when declared), otherwise the default role.
     * Null when the product declares no roles.
     */
    public function defaultRoleFor(?string $orgRole = null): ?string
    {
        if (in_array($orgRole, ['owner', 'admin'], true) && $this->hasRole(self::MANAGER_ROLE)) {
            return self::MANAGER_ROLE;
        }

        foreach ($this->roles ?? [] as $role) {
            if ($role['default']) {
                return $role['key'];
            }
        }

        return null;
    }

    /**
     * @return HasMany<Plan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }
}
