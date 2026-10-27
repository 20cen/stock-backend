<?php

namespace App\Models;

use App\Enums\Permission as PermissionCode;
use App\Enums\RoleCode;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'username', 'email', 'password', 'role_id', 'active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @var list<string>|null */
    private ?array $permissionCodes = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isOwner(): bool
    {
        return $this->role?->code === RoleCode::Owner->value;
    }

    /**
     * @return list<string>
     */
    public function permissionCodes(): array
    {
        return $this->permissionCodes ??= $this->role?->permissions()->pluck('code')->all() ?? [];
    }

    public function hasPermission(PermissionCode|string $permission): bool
    {
        $code = $permission instanceof PermissionCode ? $permission->value : $permission;

        return $this->active && in_array($code, $this->permissionCodes(), true);
    }
}
