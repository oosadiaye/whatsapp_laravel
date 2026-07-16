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
 * Authorization is via spatie/laravel-permission roles (super_admin / admin /
 * manager / agent) + the HasRoles trait; every route gates on a permission or
 * role. The denormalized `role` string column is retained because some queries
 * filter on it directly (e.g. the team/wallboard rosters use
 * `where('role', ROLE_AGENT)`); it's kept in sync in UserController.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_AGENT = 'agent';

    public const PRESENCE_AVAILABLE = 'available';
    public const PRESENCE_BUSY = 'busy';
    public const PRESENCE_AWAY = 'away';

    public const PRESENCE_STATUSES = [
        self::PRESENCE_AVAILABLE,
        self::PRESENCE_BUSY,
        self::PRESENCE_AWAY,
    ];

    public const MIC_PENDING = 'pending';
    public const MIC_GRANTED = 'granted';
    public const MIC_DENIED  = 'denied';

    public const MIC_PERMISSION_STATES = [
        self::MIC_PENDING,
        self::MIC_GRANTED,
        self::MIC_DENIED,
    ];

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
            'last_seen_at' => 'datetime',
            'last_assigned_at' => 'datetime',
            'presence_status_set_at' => 'datetime',
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

    /**
     * Conversations where this user is the assigned agent. Used by the
     * Phase 15 team-load dashboard's withCount query and by future
     * features that need to enumerate an agent's threads.
     */
    public function assignedConversations(): HasMany
    {
        return $this->hasMany(\App\Models\Conversation::class, 'assigned_to_user_id');
    }

    public function messageTemplates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class);
    }

    public function isAgent(): bool
    {
        try {
            return $this->hasRole(self::ROLE_AGENT);
        } catch (\Throwable) {
            return false;
        }
    }
}
