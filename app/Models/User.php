<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Services\ModuleAccess;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property UserRole $role
 * @property bool $is_active
 * @property int $version
 * @property int $access_version
 * @property Carbon|null $deactivated_at
 * @property bool $must_change_password
 * @property Carbon|null $temporary_password_expires_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true, 'version' => 1, 'access_version' => 1, 'must_change_password' => false];

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
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'version' => 'integer',
            'access_version' => 'integer',
            'deactivated_at' => 'datetime',
            'must_change_password' => 'boolean',
            'temporary_password_expires_at' => 'datetime',
        ];
    }

    /**
     * Buildings this user is assigned to (building-level access restriction).
     *
     * @return BelongsToMany<Building, $this>
     */
    public function buildings(): BelongsToMany
    {
        return $this->belongsToMany(Building::class, 'user_building');
    }

    /** @return HasMany<UserModuleRestriction, $this> */
    public function moduleRestrictions(): HasMany
    {
        return $this->hasMany(UserModuleRestriction::class);
    }

    public function temporaryPasswordExpired(): bool
    {
        return $this->must_change_password
            && ($this->temporary_password_expires_at === null || ! $this->temporary_password_expires_at->isFuture());
    }

    public function isUsable(): bool
    {
        return $this->is_active && ! $this->must_change_password;
    }

    public function canAccessModule(string $module): bool
    {
        return $this->isUsable() && ModuleAccess::roleAllows($this->role, $module)
            && (ModuleAccess::essential($this->role, $module) || ! $this->moduleRestrictions()->where('module_key', $module)->exists());
    }

    /** @return list<string> */
    public function accessibleModules(): array
    {
        return array_values(array_filter(array_keys(ModuleAccess::catalogue()), fn (string $module) => $this->canAccessModule($module)));
    }

    /**
     * Whether the user holds any of the given roles.
     */
    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * Whether the user has access to a specific building (building-level check).
     * Admins, Energy Managers, Budget Officers and Viewers are unrestricted
     * per the current role baseline; only Facilities Personnel is restricted.
     */
    public function hasBuildingAccess(Building|int $building): bool
    {
        $buildingId = $building instanceof Building ? $building->id : $building;

        return ! $this->role->isBuildingRestricted()
            || $this->buildings()->whereKey($buildingId)->exists();
    }

    /**
     * IDs of buildings this user may access; null means unrestricted.
     *
     * @return array<int, int>|null
     */
    public function allowedBuildingIds(): ?array
    {
        if (! $this->role->isBuildingRestricted()) {
            return null;
        }

        return $this->buildings()
            ->get(['buildings.id'])
            ->map(fn (Building $building): int => $building->id)
            ->values()
            ->all();
    }
}
