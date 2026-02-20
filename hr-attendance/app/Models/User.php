<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /* ── Role Constants ── */
    const ROLE_CEO      = 'ceo';
    const ROLE_ADMIN    = 'admin';
    const ROLE_HR_STAFF = 'hr_staff';

    /* ── Role Checks ── */
    public function isCeo(): bool
    {
        return $this->role === self::ROLE_CEO;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isHrStaff(): bool
    {
        return $this->role === self::ROLE_HR_STAFF;
    }

    /* ── Role Label ── */
    public function getRoleLabelAttribute(): string
    {
        return match ($this->role) {
            self::ROLE_CEO      => 'CEO',
            self::ROLE_ADMIN    => 'Admin',
            self::ROLE_HR_STAFF => 'HR Staff',
            default             => ucfirst($this->role),
        };
    }

    /**
     * Get the roles this user is allowed to create/delete.
     */
    public function manageableRoles(): array
    {
        return match ($this->role) {
            self::ROLE_CEO      => [self::ROLE_CEO, self::ROLE_ADMIN, self::ROLE_HR_STAFF],
            self::ROLE_ADMIN    => [self::ROLE_ADMIN, self::ROLE_HR_STAFF],
            self::ROLE_HR_STAFF => [self::ROLE_HR_STAFF],
            default             => [],
        };
    }

    /**
     * Check if this user can manage (create/delete) the given target user.
     */
    public function canManage(User $target): bool
    {
        // Cannot delete yourself
        if ($this->id === $target->id) {
            return false;
        }
        return in_array($target->role, $this->manageableRoles());
    }
}
