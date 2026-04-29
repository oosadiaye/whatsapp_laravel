<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Carries TWO role systems during the Phase 11 transition:
 *
 *   - The legacy `role` string column ('admin'/'user') used by the existing
 *     {@see \App\Http\Middleware\AdminOnly} middleware.
 *   - The new spatie/laravel-permission roles via the HasRoles trait
 *     (super_admin / admin / manager / agent).
 *
 * `isAdmin()` checks both, so legacy middleware keeps working while new code
 * migrates to spatie's role/permission gates. Once all controllers use
 * spatie role checks, the legacy `role` column can be dropped.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_AGENT = 'agent';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function contactGroups(): HasMany
    {
        return $this->hasMany(ContactGroup::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function whatsAppInstances(): HasMany
    {
        return $this->hasMany(WhatsAppInstance::class);
    }

    public function messageTemplates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class);
    }

    /**
     * Legacy admin check used by {@see \App\Http\Middleware\AdminOnly}.
     *
     * Returns true for super_admin OR admin role (spatie) OR legacy
     * `role='admin'` column so middleware decisions stay consistent across
     * both systems during the migration.
     */
    public function isAdmin(): bool
    {
        return $this->hasAnyRole([self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN])
            || $this->role === 'admin';
    }

    public function isAgent(): bool
    {
        return $this->hasRole(self::ROLE_AGENT);
    }
}
