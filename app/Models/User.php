<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    public const MIC_DENIED = 'denied';

    public const MIC_PERMISSION_STATES = [
        self::MIC_PENDING,
        self::MIC_GRANTED,
        self::MIC_DENIED,
    ];

    /** Send no task email at all. */
    public const TASK_NOTIFICATIONS_OFF = 'off';

    /** Column moves only - the default. */
    public const TASK_NOTIFICATIONS_TRANSITIONS = 'transitions';

    /** Column moves plus comments, assignment and description changes. */
    public const TASK_NOTIFICATIONS_ALL = 'all';

    public const TASK_NOTIFICATION_LEVELS = [
        self::TASK_NOTIFICATIONS_OFF,
        self::TASK_NOTIFICATIONS_TRANSITIONS,
        self::TASK_NOTIFICATIONS_ALL,
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
        'task_notifications',
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
        return $this->hasMany(Conversation::class, 'assigned_to_user_id');
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

    /**
     * Task-management relations.
     *
     * `boards` are the boards this user created and `tasks` the cards they
     * created - both are audit metadata only. The app has a single shared
     * workspace, so board visibility is company-wide rather than per-user (the
     * same convention as CampaignController::index()).
     *
     * Who is actually doing the work lives in the two belongsToMany pivots
     * below, which are independent of the creator.
     */
    public function boards(): HasMany
    {
        return $this->hasMany(Board::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function assignedTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_assignees');
    }

    public function watchedTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_watchers');
    }

    /**
     * Up to two uppercase initials for avatar chips: "John Smith" -> "JS",
     * "Cher" -> "C". Used by the task board's assignee badges.
     */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->name)) ?: [];

        $initials = collect($parts)
            ->filter(fn ($part) => $part !== '')
            ->take(2)
            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        // Fall back to a single character so a nameless user still renders a
        // chip rather than an empty circle.
        return $initials !== '' ? $initials : mb_strtoupper(mb_substr((string) $this->name, 0, 1));
    }

    /**
     * The stored task-notification level, normalised.
     *
     * Falls back to the default for a null (row predating the column) or
     * unrecognised value, so a hand-edited database can never make the
     * notification resolver throw.
     */
    public function taskNotificationLevel(): string
    {
        $level = (string) ($this->task_notifications ?? '');

        return in_array($level, self::TASK_NOTIFICATION_LEVELS, true)
            ? $level
            : self::TASK_NOTIFICATIONS_TRANSITIONS;
    }

    /**
     * Whether this user wants email about a given kind of task event.
     *
     * $kind is 'transition' for a column move and 'activity' for a comment,
     * assignment or description change. 'all' covers both; 'transitions'
     * deliberately does not cover activity, which is what makes the level
     * worth choosing.
     */
    public function wantsTaskNotificationsFor(string $kind): bool
    {
        return match ($this->taskNotificationLevel()) {
            self::TASK_NOTIFICATIONS_OFF => false,
            self::TASK_NOTIFICATIONS_ALL => true,
            // Default arm: transitions only.
            default => $kind === 'transition',
        };
    }
}
