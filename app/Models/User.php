<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMINISTRATOR = 'administrator';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_CASHIER = 'cashier';

    public const ROLE_INVENTORY = 'inventory';

    /**
     * @var array<int, string>
     */
    protected $fillable = ['name', 'email', 'password', 'role'];

    /**
     * @var array<int, string>
     */
    protected $hidden = ['password', 'remember_token'];

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
        ];
    }

    /**
     * The user's role, falling back to the configured default when the stored
     * value is missing or no longer defined in config/permissions.php.
     */
    public function roleName(): string
    {
        $role = $this->attributes['role'] ?? null;
        $role = $role ?: config('permissions.default_role', self::ROLE_CASHIER);

        return array_key_exists($role, config('permissions.roles', []))
            ? $role
            : config('permissions.default_role', self::ROLE_CASHIER);
    }

    /**
     * Determine whether the user holds the given permission.
     */
    public function hasPermission(string $permission): bool
    {
        $granted = config("permissions.roles.{$this->roleName()}.permissions", []);

        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }

    /**
     * Determine whether the user holds any of the given permissions.
     *
     * @param  array<int, string>  $permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the user is a full administrator.
     */
    public function isAdministrator(): bool
    {
        return $this->roleName() === self::ROLE_ADMINISTRATOR;
    }

    /**
     * The human readable label for the user's role.
     */
    public function roleLabel(): string
    {
        return config("permissions.roles.{$this->roleName()}.label", Str::headline($this->roleName()));
    }

    /**
     * Backups requested by the user.
     *
     * @return HasMany<Backup, $this>
     */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class, 'created_by');
    }

    /**
     * Activity log entries attributed to the user.
     *
     * @return HasMany<ActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }
}
